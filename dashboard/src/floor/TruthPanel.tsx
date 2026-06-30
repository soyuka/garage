import { useEffect, useState } from 'react';
import { dueDescriptor, latestPerformedAt, signalTag } from '../derive';
import { coerceInt, defaultUsageUnit, emptyToNull, VEHICLE_USAGE_UNITS } from '../edit';
import { formatDate, formatHealthPct, formatReading, groupThousands, NBSP } from '../format';
import { HEALTH_LABEL, serviceLabel } from '../labels';
import type { ForecastDue, VehicleFact, VehicleForecast, VehicleSummary } from '../types';
import type { VehiclePatch } from '../garage';
import { FactsSection, type FactsEdit } from './FactsSection';
import { InlineEdit } from './InlineEdit';
import './floor.css';

// The glass truth-panel — the AUTHORITATIVE surface (DESIGN-VISION §8). Identity, mileage, overall
// health, per-system échéances and the vehicle facts at FULL PRECISION, always visible. The 3D conveys
// urgency; this carries the truth. Hide the WebGL canvas and not one value is lost — it all lives here.
// Every glow pairs with a mono label; every signal pairs with a TEXT tag (legible in grayscale).
// `focusKeys` (a showFact frame) etches the matching échéance rows with a light-sweep.
//
// When the live hero passes `edit`, the identity scalars become click-to-edit (InlineEdit), the usage
// reading gains a value+unit editor, the facts section gains add/remove, and a discreet soft-delete
// appears. When `edit` is absent (the canvas-off floor + the fixture playground), the panel renders
// read-only exactly as before — no regression.

/** The live-hero editing seam. Present → the panel is interactive; absent → read-only. */
export interface TruthPanelEdit {
  onPatch: (patch: VehiclePatch) => void;
  onDelete: () => void;
  facts: FactsEdit;
}

export function TruthPanel({
  vehicle,
  forecast,
  focusKeys,
  edit,
  onPlan,
}: {
  vehicle: VehicleSummary;
  forecast: VehicleForecast;
  focusKeys?: string[];
  edit?: TruthPanelEdit;
  /** Plan a concrete task pegged to a forecast row's predicted-due reading. Absent → no plan button. */
  onPlan?: (due: ForecastDue) => void;
}) {
  const v = vehicle;
  const focus = new Set(focusKeys ?? []);
  const health = v.health;
  const status = health?.status ?? 'unknown';
  const facts: VehicleFact[] = v.facts ?? [];
  // authoritative mirror of the cardinal "depuis l'entretien" readout — keeps the panel lossless
  const lastService = latestPerformedAt(forecast.due);

  return (
    <section className="at-truth glass" aria-label="Fiche véhicule">
      <div className="at-truth-plate-row">
        {edit ? (
          <InlineEdit
            value={v.plate ?? ''}
            placeholder="+ plaque"
            ariaLabel="Modifier la plaque d'immatriculation"
            className="at-truth-plate--edit"
            onCommit={(s) => edit.onPatch({ plate: emptyToNull(s) })}
          />
        ) : (
          v.plate && <div className="at-truth-plate">{v.plate}</div>
        )}
      </div>

      <h2 className="at-truth-name">
        {edit ? (
          <>
            <InlineEdit value={v.make} placeholder="Marque" ariaLabel="Modifier la marque" onCommit={(s) => edit.onPatch({ make: s })} />{' '}
            <InlineEdit value={v.model} placeholder="Modèle" ariaLabel="Modifier le modèle" onCommit={(s) => edit.onPatch({ model: s })} />
          </>
        ) : (
          <>
            {v.make} {v.model}
          </>
        )}
      </h2>

      <p className="at-truth-sub">
        {edit ? (
          <>
            <InlineEdit value={v.year != null ? String(v.year) : ''} placeholder="+ année" ariaLabel="Modifier l'année" onCommit={(s) => edit.onPatch({ year: coerceInt(s) })} />
            <span className="at-truth-sub-sep"> · </span>
            <InlineEdit value={v.engineVariant ?? ''} placeholder="+ finition" ariaLabel="Modifier la finition" onCommit={(s) => edit.onPatch({ engineVariant: emptyToNull(s) })} />
            <span className="at-truth-sub-sep"> · </span>
            <InlineEdit value={v.engineCode ?? ''} placeholder="+ moteur" ariaLabel="Modifier le code moteur" onCommit={(s) => edit.onPatch({ engineCode: emptyToNull(s) })} />
            <span className="at-truth-sub-sep"> · </span>
            <InlineEdit value={v.kType != null ? String(v.kType) : ''} placeholder="+ kType" ariaLabel="Modifier le kType TecDoc" onCommit={(s) => edit.onPatch({ kType: coerceInt(s) })} />
          </>
        ) : (
          [v.year, v.engineVariant].filter(Boolean).join(' · ')
        )}
      </p>

      <div className="at-truth-block">
        <span className="at-label">Kilométrage</span>
        {edit ? (
          <UsageEdit value={v.usageValue ?? null} unit={v.usageUnit ?? null} vehicleType={v.vehicleType} onCommit={(value, unit) => edit.onPatch({ usageValue: value, usageUnit: unit })} />
        ) : (
          <span className="at-truth-big">{v.usageValue != null ? formatReading(v.usageValue, v.usageUnit) : '—'}</span>
        )}
      </div>

      <div className="at-truth-block at-truth-health" data-signal={status}>
        <span className="at-label">État</span>
        <span className="at-row">
          <span className="at-dot" aria-hidden="true" />
          <span className="at-pill">{HEALTH_LABEL[status]}</span>
          {health?.score != null && <span className="at-truth-score">{formatHealthPct(health.score)}</span>}
        </span>
        {health && (health.overdue > 0 || health.dueSoon > 0) && (
          <span className="at-truth-context">
            {health.overdue > 0 && `${health.overdue}${NBSP}en retard`}
            {health.overdue > 0 && health.dueSoon > 0 && ' · '}
            {health.dueSoon > 0 && `${health.dueSoon}${NBSP}bientôt`}
          </span>
        )}
      </div>

      {lastService && (
        <div className="at-truth-block at-truth-last">
          <span className="at-label">Dernière intervention</span>
          <span className="at-truth-last-date">{formatDate(lastService)}</span>
        </div>
      )}

      <div className="at-truth-block">
        <span className="at-label">Échéances par système</span>
        <ul className="at-truth-systems">
          {forecast.due.map((due, i) => {
            const d = dueDescriptor(due);
            const date = formatDate(due.projectedDueAt ?? due.dueAt);
            const tag = signalTag(d.overdue, d.signal);
            return (
              <li className="at-truth-system" key={i} data-signal={d.signal} data-focus={focus.has(due.type) ? 'true' : undefined}>
                <span className="at-dot" aria-hidden="true" />
                <span className="at-truth-system-name">{serviceLabel(due.type)}</span>
                <span className="at-truth-system-pct">{d.healthPct != null ? formatHealthPct(d.healthPct) : '—'}</span>
                <span className="at-truth-system-when">
                  <span className={`at-truth-system-due${d.overdue ? ' at-truth-system-due--late' : ''}`}>{d.horizon}</span>
                  {date && <span className="at-truth-system-date">{date}</span>}
                </span>
                <span className={`at-tag at-tag--${tag.tone}`}>{tag.text}</span>
                {/* #10: plan a concrete task pegged to this predicted-due service (moved off the old
                    floating hotspot card into the stable panel row). */}
                {onPlan && (
                  <button type="button" className="at-truth-plan" onClick={() => onPlan(due)}>
                    Planifier {serviceLabel(due.type)} →
                  </button>
                )}
              </li>
            );
          })}
          {forecast.due.length === 0 && <li className="at-truth-system at-plan-empty">Aucune échéance prévue.</li>}
        </ul>
        {forecast.gaps.length > 0 && (
          <p className="at-truth-gaps">
            <span className="at-tag at-tag--gap">NON SUIVI</span> {forecast.gaps.map(serviceLabel).join(' · ')}
          </p>
        )}
      </div>

      <FactsSection facts={facts} edit={edit?.facts} />

      {edit && (
        <div className="at-truth-block at-truth-danger">
          <button
            type="button"
            className="at-truth-delete"
            onClick={() => {
              if (window.confirm(`Retirer « ${[v.make, v.model].filter(Boolean).join(' ') || 'ce véhicule'} » du parc ?`)) edit.onDelete();
            }}
          >
            Retirer du parc
          </button>
        </div>
      )}
    </section>
  );
}

// The usage reading editor: a click-to-edit value paired with a unit select. The Vehicle resource
// binds App\Garage\UsageUnit (km|mi|h), so the select offers those — sending the maintenance-side
// "hours" would be rejected. Value + unit are always written together (the backend requires the pair);
// changing the unit re-stamps an existing reading immediately, a value commit carries the held unit.
function UsageEdit({ value, unit, vehicleType, onCommit }: { value: number | null; unit: string | null; vehicleType: string; onCommit: (value: number, unit: string) => void }) {
  const [u, setU] = useState<string>(unit ?? defaultUsageUnit(vehicleType));
  useEffect(() => {
    if (unit) setU(unit);
  }, [unit]);

  const commitValue = (raw: string) => {
    const n = coerceInt(raw);
    if (n != null) onCommit(n, u); // empty/non-numeric is a no-op — a reading can't be cleared to null
  };
  const changeUnit = (next: string) => {
    setU(next);
    if (value != null) onCommit(value, next); // re-stamp the existing reading with the new unit
  };

  return (
    <span className="at-usage-edit">
      <InlineEdit
        value={value != null ? groupThousands(value) : ''}
        placeholder="+ relevé"
        ariaLabel="Modifier le kilométrage"
        className="at-usage-edit-val"
        onCommit={commitValue}
      />
      <select className="at-usage-edit-unit" aria-label="Unité du relevé" value={u} onChange={(e) => changeUnit(e.target.value)}>
        {VEHICLE_USAGE_UNITS.map((opt) => (
          <option key={opt} value={opt}>
            {opt}
          </option>
        ))}
      </select>
    </span>
  );
}
