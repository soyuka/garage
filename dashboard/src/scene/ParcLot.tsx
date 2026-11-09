import { Suspense, useEffect, useRef, useState } from 'react';
import { useLocale, useT } from '../i18n/LocaleProvider';
import type { Locale } from '../i18n/locale';
import { messages } from '../i18n/messages';
import { Canvas } from '@react-three/fiber';
import { View } from '@react-three/drei';
import { SilhouetteCar } from './SilhouetteCar';
import { bodyClassFor, CarProxy } from './CarProxy';
import { resolveMesh } from './vehicleMesh';
import { ParcThumb } from './ParcThumb';
import { MeshErrorBoundary } from './VehicleBody';
import { formatHorizon, formatOverdue, formatReading } from '../format';
import { serviceLabel } from '../labels';
import type { Iri, NextDue, Signal, VehicleSummary } from '../types';
import './parclot.css';

// §6 "Le Parc" — the fleet as a grid of cards. Each card's stage shows a small SPINNING blueprint mesh
// (the low-poly thumb) rendered through ONE shared WebGL canvas via drei <View> (never N canvases — that
// blows the browser context cap). Each card keeps its signal base-ring, ALWAYS paired with a text health
// tag (§8: signal is never colour alone). Most-recent-first. WebGL-unavailable devices degrade to the
// flat SVG silhouette. Own route #/parc.

// One-shot WebGL capability probe (the whole grid degrades to the SVG silhouette when false).
function detectWebgl(): boolean {
  try {
    const c = document.createElement('canvas');
    return !!(window.WebGLRenderingContext && (c.getContext('webgl') || c.getContext('experimental-webgl')));
  } catch {
    return false;
  }
}

// Per-card visibility — only on-screen cards spin (drei culls the GPU draw of an offscreen View, but NOT
// our useFrame spin; the IntersectionObserver gates that).
function useInView<T extends Element>(ref: React.RefObject<T | null>): boolean {
  const [inView, setInView] = useState(false);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const io = new IntersectionObserver(([e]) => setInView(e.isIntersecting), { rootMargin: '120px' });
    io.observe(el);
    return () => io.disconnect();
  }, [ref]);
  return inView;
}

// Pause every spin while the tab is hidden.
function usePageVisible(): boolean {
  const [visible, setVisible] = useState(() => (typeof document === 'undefined' ? true : !document.hidden));
  useEffect(() => {
    const on = () => setVisible(!document.hidden);
    document.addEventListener('visibilitychange', on);
    return () => document.removeEventListener('visibilitychange', on);
  }, []);
  return visible;
}

/** The clicked card's own rect + vehicle — the departure ghost is fixed
 *  at `rect` and needs the vehicle's fields to look like the card it grew out of. */
export interface DepartureOrigin {
  rect: DOMRect;
  vehicle: VehicleSummary;
}

interface ParcLotProps {
  vehicles: VehicleSummary[];
  onSelect?: (iri: Iri, origin: DepartureOrigin) => void;
  /** Create a blank vehicle then drive into its hero to fill it in (#9). Absent → no add affordance. */
  onAddVehicle?: () => void;
  reduced?: boolean;
  /** A departure ghost is in flight: the grid fades to 0 (CSS only) while the ghost drives to the
   *  stage centre. Never set together with a fresh interactive state — it is a one-way trip. */
  receding?: boolean;
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

export function paintFor(make: string): string {
  return MAKE_PAINT[make.toLowerCase()] ?? NEUTRAL_PAINT;
}

export type ParcTag = { text: string; tone: 'ok' | 'soon' | 'late' | 'gap' };

export function tagFor(signal: Signal | 'unknown', locale: Locale): ParcTag {
  const t = messages(locale);
  if (signal === 'red') return { text: t.signal.late, tone: 'late' };
  if (signal === 'amber') return { text: t.signal.soon, tone: 'soon' };
  if (signal === 'green') return { text: t.signal.ok, tone: 'ok' };
  return { text: t.signal.untracked, tone: 'gap' };
}

// The next-due one-liner horizon. Overdue is precise (§5 exception); else the rounded horizon. Every
// value shown comes straight off nextDue — nothing invented. When overdue, show the axis ACTUALLY past
// due: a negative usageRemaining (km/h overshoot) wins; else a negative day count; else bare DÉPASSÉ.
// usageRemaining can be POSITIVE on a time-overdue service (km left but the calendar lapsed) — never
// format that as an overshoot (the live MAZDA: overdue 113 j, yet 6 548 km remaining).
export function horizonFor(n: NextDue, locale: Locale): string {
  const t = messages(locale);
  if (n.overdue) {
    if (n.usageRemaining != null && n.usageRemaining < 0 && n.usageUnit) return formatOverdue(n.usageRemaining, n.usageUnit, locale);
    if (n.daysUntilDue != null && n.daysUntilDue < 0) {
      const days = Math.abs(n.daysUntilDue);
      return formatOverdue(days, t.forecast.dayUnit(days), locale);
    }
    return t.forecast.overdue;
  }
  if (n.daysUntilDue != null) return formatHorizon(n.daysUntilDue, locale);
  if (n.usageRemaining != null && n.usageUnit) return t.forecast.inReading(formatReading(n.usageRemaining, n.usageUnit, locale));
  return '';
}

function ParcCard({
  vehicle,
  onSelect,
  live,
  reduced,
  paused,
}: {
  vehicle: VehicleSummary;
  onSelect?: (iri: Iri, origin: DepartureOrigin) => void;
  live: boolean;
  reduced: boolean;
  paused: boolean;
}) {
  const [locale] = useLocale();
  const t = useT();
  const v = vehicle;
  const signal = parcSignal(v);
  const tag = tagFor(signal, locale);
  const bodyClass = bodyClassFor(v.vehicleType);
  const paint = paintFor(v.make);
  const sub = [v.year, v.engineVariant].filter(Boolean).join(' · ');
  const horizon = v.nextDue ? horizonFor(v.nextDue, locale) : null;
  // The departure ghost needs the clicked card's OWN rect, not the stage's
  // — it grows from where the whole glass card sits, not just its thumb.
  const activate = onSelect
    ? (e: React.MouseEvent<HTMLButtonElement>) => onSelect(v['@id'], { rect: e.currentTarget.getBoundingClientRect(), vehicle: v })
    : undefined;
  // A blank just-created vehicle has empty make/model — degrade the name (and the button's accessible
  // name) to a readable fallback instead of bare whitespace.
  const name = [v.make, v.model].filter(Boolean).join(' ') || t.parc.unnamed;

  const stageRef = useRef<HTMLDivElement>(null);
  const inView = useInView(stageRef);
  const spec = resolveMesh(v);
  const proxy = <CarProxy bodyClass={bodyClass} paint={paint} />;

  return (
    <button
      type="button"
      className="at-parc-card glass"
      data-signal={signal}
      onClick={activate}
      aria-label={`${name}${v.plate ? `, ${v.plate}` : ''} — ${tag.text}`}
    >
      <div className="at-parc-stage" ref={stageRef}>
        <span className="at-parc-ring" aria-hidden="true" />
        {live ? (
          // drei <View> (outside a Canvas) renders + tracks ITS OWN div — style it to fill the stage.
          <View className="at-parc-view">
            <MeshErrorBoundary key={spec.thumbUrl} fallback={proxy}>
              <Suspense fallback={proxy}>
                <ParcThumb spec={spec} paint={paint} active={inView && !paused} reduced={reduced} />
              </Suspense>
            </MeshErrorBoundary>
          </View>
        ) : (
          <SilhouetteCar bodyClass={bodyClass} paint={paint} />
        )}
      </div>

      <div className="at-parc-head">
        {v.plate && <span className="at-parc-plate">{v.plate}</span>}
        <span className="at-parc-name">{name}</span>
        {sub && <span className="at-parc-sub">{sub}</span>}
      </div>

      <div className="at-parc-meta">
        <span className="at-label at-parc-meta-label">{t.parc.usage}</span>
        <span className="at-parc-mileage">
          {v.usageValue != null ? formatReading(v.usageValue, v.usageUnit, locale) : '—'}
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
              <span className="at-parc-due-label">{serviceLabel(v.nextDue.type, locale)}</span>
              {horizon && <span className="at-parc-due-horizon">{horizon}</span>}
            </>
          ) : (
            <span className="at-parc-due-label at-parc-due-none">{t.parc.nothingDue}</span>
          )}
        </span>
        <span className={`at-tag at-tag--${tag.tone}`}>{tag.text}</span>
      </div>
    </button>
  );
}

function AddCard({ onAdd }: { onAdd: () => void }) {
  const t = useT();

  return (
    <button type="button" className="at-parc-card at-parc-add glass" onClick={onAdd} aria-label={t.parc.addAria}>
      <span className="at-parc-add-glyph" aria-hidden="true">
        +
      </span>
      <span className="at-parc-add-label">{t.parc.add}</span>
    </button>
  );
}

export function ParcLot({ vehicles, onSelect, onAddVehicle, reduced, receding }: ParcLotProps) {
  const ordered = byRecency(vehicles);
  // WebGL probed once on mount (SSR-safe: false until mounted, then the real capability).
  const [live, setLive] = useState(false);
  useEffect(() => setLive(detectWebgl()), []);
  const t = useT();
  const pageVisible = usePageVisible();
  const paused = !pageVisible;

  if (ordered.length === 0 && !onAddVehicle) {
    return (
      <section className="at-parc" data-reduced={reduced ? 'true' : undefined} aria-label={t.parc.landmark}>
        <p className="at-parc-empty">{t.parc.empty}</p>
      </section>
    );
  }
  return (
    <section className="at-parc" data-reduced={reduced ? 'true' : undefined} aria-label={t.parc.landmark}>
      {live && (
        // ONE shared canvas for every card's <View>. Fixed + transparent + pointer-events:none so it
        // overlays the stages (drei tracks each rect per frame) without blocking the card buttons.
        <Canvas
          className="at-parc-canvas"
          frameloop="always"
          dpr={[1, 1.5]}
          gl={{ antialias: true, alpha: true }}
          camera={{ position: [2.0, 1.3, 2.6], fov: 32, near: 0.1, far: 100 }}
          onCreated={({ camera }) => camera.lookAt(0, 0, 0)}
          style={{ position: 'fixed', inset: 0, pointerEvents: 'none' }}
        >
          <View.Port />
        </Canvas>
      )}
      <ul className="at-parc-grid" data-receding={receding ? 'true' : undefined}>
        {ordered.map((v) => (
          <li key={v['@id']}>
            <ParcCard vehicle={v} onSelect={onSelect} live={live} reduced={!!reduced} paused={paused} />
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
