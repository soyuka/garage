import { Suspense, useEffect, useRef, useState } from 'react';
import { Canvas } from '@react-three/fiber';
import { View } from '@react-three/drei';
import { SilhouetteCar } from './SilhouetteCar';
import { bodyClassFor, CarProxy } from './CarProxy';
import { resolveMesh } from './vehicleMesh';
import { ParcThumb } from './ParcThumb';
import { MeshErrorBoundary } from './VehicleBody';
import { readStageVariant, type StageVariant } from './stageVariant';
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

function ParcCard({
  vehicle,
  onSelect,
  live,
  reduced,
  paused,
  stageVariant,
}: {
  vehicle: VehicleSummary;
  onSelect?: (iri: Iri) => void;
  live: boolean;
  reduced: boolean;
  paused: boolean;
  stageVariant: StageVariant;
}) {
  const v = vehicle;
  const signal = parcSignal(v);
  const tag = tagFor(signal);
  const bodyClass = bodyClassFor(v.vehicleType);
  const paint = paintFor(v.make);
  const sub = [v.year, v.engineVariant].filter(Boolean).join(' · ');
  const horizon = v.nextDue ? horizonFor(v.nextDue) : null;
  const activate = onSelect ? () => onSelect(v['@id']) : undefined;
  // A blank just-created vehicle has empty make/model — degrade the name (and the button's accessible
  // name) to a readable fallback instead of bare whitespace.
  const name = [v.make, v.model].filter(Boolean).join(' ') || 'Véhicule sans nom';

  const stageRef = useRef<HTMLDivElement>(null);
  const inView = useInView(stageRef);
  const spec = resolveMesh(v);
  const proxy = <CarProxy bodyClass={bodyClass} paint={paint} stageVariant={stageVariant} />;

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
                <ParcThumb spec={spec} paint={paint} active={inView && !paused} reduced={reduced} stageVariant={stageVariant} />
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
  // WebGL probed once on mount (SSR-safe: false until mounted, then the real capability).
  const [live, setLive] = useState(false);
  useEffect(() => setLive(detectWebgl()), []);
  const pageVisible = usePageVisible();
  const paused = !pageVisible;
  // Stage look (stageVariant.ts), read once like Plateau's — the parc grid shares the same porcelain/
  // night-glass fill as the hero (see ParcThumb's doc comment for the ambientLight this needs under 'mat').
  const [stageVariant] = useState<StageVariant>(() => readStageVariant());

  if (ordered.length === 0 && !onAddVehicle) {
    return (
      <section className="at-parc" data-reduced={reduced ? 'true' : undefined} aria-label="Le parc">
        <p className="at-parc-empty">Aucun véhicule dans le parc.</p>
      </section>
    );
  }
  return (
    <section className="at-parc" data-reduced={reduced ? 'true' : undefined} aria-label="Le parc">
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
          {/* Only needed by 'mat' porcelain (lit MeshStandardMaterial); 'night' fill+edges stay unlit
              basic/line materials and ignore it entirely — safe to always mount. */}
          <ambientLight intensity={1.1} />
          <View.Port />
        </Canvas>
      )}
      <ul className="at-parc-grid">
        {ordered.map((v) => (
          <li key={v['@id']}>
            <ParcCard vehicle={v} onSelect={onSelect} live={live} reduced={!!reduced} paused={paused} stageVariant={stageVariant} />
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
