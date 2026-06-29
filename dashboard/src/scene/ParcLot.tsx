import { SilhouetteCar } from './SilhouetteCar';
import { bodyClassFor } from './CarProxy';
import { formatHorizon, formatOverdue, formatReading } from '../format';
import { serviceLabel } from '../labels';
import type { Iri, NextDue, Signal, VehicleSummary } from '../types';
import './parclot.css';

// §6 "Le Parc" — the fleet as a grid of baked-render-style cards (NOT live 3D: the flat SVG
// silhouette). Each card carries a base-ring in the car's health colour, ALWAYS paired with a text
// health tag (§8: signal is never colour alone). Most-recent-first. Own route #/parc.

interface ParcLotProps {
  vehicles: VehicleSummary[];
  onSelect?: (iri: Iri) => void;
  /** Create a blank vehicle then drive into its hero to fill it in (#9). Absent → no add affordance. */
  onAddVehicle?: () => void;
  reduced?: boolean;
}

// The parc listing (the COLLECTION) omits `health` — only `nextDue` rides each member. So the card
// signal is re-derived from nextDue alone: contract-faithful, never inventing the missing rollup.
// LIMITATION (documented, Stage-C backend task): the §2 amber rule is composite — "≤30 days OR ≤10% of
// the usage interval". `NextDue` carries `usageRemaining` but NOT the interval, so the usage-10% branch
// is uncomputable on the collection; a car amber by usage-only (not by days) reads green here while the
// item view (forecast.due → dueSignal) correctly shows BIENTÔT. Overdue (red) and unknown ARE faithful.
// Fix is a server-provided signal on the collection, not a fabricated client threshold.
export function parcSignal(v: VehicleSummary): Signal | 'unknown' {
  const n = v.nextDue;
  if (!n) return 'unknown';
  if (n.overdue) return 'red';
  if (n.daysUntilDue != null && n.daysUntilDue <= 30) return 'amber';
  return 'green';
}

// Stage C → mpa.recency localStorage rail. For now: most-recent-first by `addedAt` desc, pure +
// non-mutating (stable for equal dates so the source order survives ties).
export function byRecency(vehicles: VehicleSummary[]): VehicleSummary[] {
  return vehicles
    .map((v, i) => [v, i] as const)
    .sort((a, b) => (a[0].addedAt < b[0].addedAt ? 1 : a[0].addedAt > b[0].addedAt ? -1 : a[1] - b[1]))
    .map(([v]) => v);
}

// A small LOCAL paint map per make (do NOT reach into CarProxy internals). Neutral graphite default.
const NEUTRAL_PAINT = '#3a3f46';
const MAKE_PAINT: Record<string, string> = {
  renault: '#4a5a6a',
  peugeot: '#46505c',
  citroën: '#52565e',
  citroen: '#52565e',
  yamaha: '#3f4a5a',
};

function paintFor(make: string): string {
  return MAKE_PAINT[make.toLowerCase()] ?? NEUTRAL_PAINT;
}

type ParcTag = { text: string; tone: 'ok' | 'soon' | 'late' | 'gap' };

function tagFor(signal: Signal | 'unknown'): ParcTag {
  if (signal === 'red') return { text: 'EN RETARD', tone: 'late' };
  if (signal === 'amber') return { text: 'BIENTÔT', tone: 'soon' };
  if (signal === 'green') return { text: 'OK', tone: 'ok' };
  return { text: 'NON SUIVI', tone: 'gap' };
}

// The next-due one-liner horizon. Overdue is precise (§5 exception); else the rounded horizon. Every
// value shown comes straight off nextDue — nothing invented. When overdue, show the axis ACTUALLY past
// due: a negative usageRemaining (km/h overshoot) wins; else a negative day count; else bare DÉPASSÉ.
// usageRemaining can be POSITIVE on a time-overdue service (km left but the calendar lapsed) — never
// format that as an overshoot (the live MAZDA: overdue 113 j, yet 6 548 km remaining).
export function horizonFor(n: NextDue): string {
  if (n.overdue) {
    if (n.usageRemaining != null && n.usageRemaining < 0 && n.usageUnit) return formatOverdue(n.usageRemaining, n.usageUnit);
    if (n.daysUntilDue != null && n.daysUntilDue < 0) return formatOverdue(Math.abs(n.daysUntilDue), n.daysUntilDue === -1 ? 'jour' : 'jours');
    return 'DÉPASSÉ';
  }
  if (n.daysUntilDue != null) return formatHorizon(n.daysUntilDue);
  if (n.usageRemaining != null && n.usageUnit) return `dans ${formatReading(n.usageRemaining, n.usageUnit)}`;
  return '';
}

function ParcCard({ vehicle, onSelect }: { vehicle: VehicleSummary; onSelect?: (iri: Iri) => void }) {
  const v = vehicle;
  const signal = parcSignal(v);
  const tag = tagFor(signal);
  const bodyClass = bodyClassFor(v.vehicleType);
  const sub = [v.year, v.engineVariant].filter(Boolean).join(' · ');
  const horizon = v.nextDue ? horizonFor(v.nextDue) : null;
  const activate = onSelect ? () => onSelect(v['@id']) : undefined;
  // A blank just-created vehicle has empty make/model — degrade the name (and the button's accessible
  // name) to a readable fallback instead of bare whitespace.
  const name = [v.make, v.model].filter(Boolean).join(' ') || 'Véhicule sans nom';

  return (
    <button
      type="button"
      className="at-parc-card glass"
      data-signal={signal}
      onClick={activate}
      aria-label={`${name}${v.plate ? `, ${v.plate}` : ''} — ${tag.text}`}
    >
      <div className="at-parc-stage">
        <span className="at-parc-ring" aria-hidden="true" />
        <SilhouetteCar bodyClass={bodyClass} paint={paintFor(v.make)} />
      </div>

      <div className="at-parc-head">
        {v.plate && <span className="at-parc-plate">{v.plate}</span>}
        <span className="at-parc-name">{name}</span>
        {sub && <span className="at-parc-sub">{sub}</span>}
      </div>

      <div className="at-parc-meta">
        <span className="at-label at-parc-meta-label">Kilométrage</span>
        <span className="at-parc-mileage">
          {v.usageValue != null ? formatReading(v.usageValue, v.usageUnit) : '—'}
        </span>
      </div>

      <div className="at-parc-due">
        <span className="at-parc-dot at-dot" aria-hidden="true" />
        <span className="at-parc-due-text">
          {v.nextDue ? (
            <>
              {/* The backend nextDue.label is unreliable (sometimes the raw type "oil_change",
                  sometimes an English component "foam element"), so localize from the type — matching
                  the floor leaves (serviceLabel) and keeping the parc French-correct. */}
              <span className="at-parc-due-label">{serviceLabel(v.nextDue.type)}</span>
              {horizon && <span className="at-parc-due-horizon">{horizon}</span>}
            </>
          ) : (
            <span className="at-parc-due-label at-parc-due-none">Aucune échéance</span>
          )}
        </span>
        <span className={`at-tag at-tag--${tag.tone}`}>{tag.text}</span>
      </div>
    </button>
  );
}

function AddCard({ onAdd }: { onAdd: () => void }) {
  return (
    <button type="button" className="at-parc-card at-parc-add glass" onClick={onAdd} aria-label="Ajouter un véhicule au parc">
      <span className="at-parc-add-glyph" aria-hidden="true">
        +
      </span>
      <span className="at-parc-add-label">Ajouter un véhicule</span>
    </button>
  );
}

export function ParcLot({ vehicles, onSelect, onAddVehicle, reduced }: ParcLotProps) {
  const ordered = byRecency(vehicles);
  if (ordered.length === 0 && !onAddVehicle) {
    return (
      <section className="at-parc" data-reduced={reduced ? 'true' : undefined} aria-label="Le parc">
        <p className="at-parc-empty">Aucun véhicule dans le parc.</p>
      </section>
    );
  }
  return (
    <section className="at-parc" data-reduced={reduced ? 'true' : undefined} aria-label="Le parc">
      <ul className="at-parc-grid">
        {ordered.map((v) => (
          <li key={v['@id']}>
            <ParcCard vehicle={v} onSelect={onSelect} />
          </li>
        ))}
        {onAddVehicle && (
          <li>
            <AddCard onAdd={onAddVehicle} />
          </li>
        )}
      </ul>
    </section>
  );
}
