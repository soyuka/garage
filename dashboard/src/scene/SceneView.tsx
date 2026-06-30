import { useRef, type ReactNode } from 'react';
import { TruthPanel, type TruthPanelEdit } from '../floor/TruthPanel';
import { FlatPlanList } from '../floor/FlatPlanList';
import { Noyau } from '../noyau/Noyau';
import type { NoyauState } from '../noyau/state';
import { Plateau, type Rung } from './Plateau';
import { CardinalReadouts } from './CardinalReadouts';
import { Checklist, type ChecklistHandlers } from './Checklist';
import { CtaCard, type Cta } from './CtaCard';
import { daysSince, latestPerformedAt } from '../derive';
import { regionsForKeys, type BodyRegion } from './anchors';
import { slotPanel, type Focus } from '../focus';
import type { ForecastDue, Iri, PlannedPart, VehicleBundle } from '../types';

// The PURE presentational scene — the main region (empty / honest-floor / hero) + the dock (Le Noyau
// over the composer). It owns NO data source, no turn lifecycle, no crossing logic: a driver feeds it
// an already-resolved bundle + the Noyau state and the composer handlers. Both the fixture playground
// (AtelierScene) and the live orchestrator (Atelier) render THIS, so the view model is written once.
// Z-order is owned by scene.css: stage canvas (z0) → glass (z10) → hotspot cards (z30) → dock (z40)
// → Le Noyau (z50). Placed by the driver inside a `.at-scene` flex column alongside its own chrome.

export type { Rung } from './Plateau';

interface SceneViewProps {
  /** The vehicle to show (already crossing-resolved by the driver). Null → empty parc. */
  bundle: VehicleBundle | null;
  rung: Rung;
  reduced: boolean;
  noyau: NoyauState;
  onClearEmber: () => void;
  /** Composer focus toggles Le Noyau's listening cue (a local UI event, not a wire frame). */
  onComposerFocus?: (active: boolean) => void;
  /** Submit an instruction (Enter). Live → posts an agent turn; absent → input is inert (pre-agent). */
  onSubmit?: (instruction: string) => void;
  /** Driver-injected controls rendered before the composer input (the playground's ▶/⚠ sims). */
  composerExtras?: ReactNode;
  /** A `proposeActions` card rising from the cradle (live orchestrator only). */
  cta?: Cta | null;
  onCtaInstruction?: (instruction: string) => void;
  onCtaPatch?: (patch: { id: Iri; parts: PlannedPart[] }) => void;
  onCtaDismiss?: () => void;
  /** The unified slot focus: which panel fills the centre slot + which keys are spotlit (truth-panel
   * etches matching rows, the car lights matching regions). Null/absent → the specs panel at rest. */
  focus?: Focus | null;
  /** Live-hero editing seam (#9): makes the truth-panel scalars/facts interactive. Absent → read-only. */
  edit?: TruthPanelEdit;
  /** Live-hero open-checklist (#10): the interactive task list. Absent → no checklist panel (playground). */
  checklist?: ChecklistHandlers;
  /** Click a car pin → focus the slot on that region's tasks. Absent → pins are inert (playground). */
  onFocusRegion?: (region: BodyRegion) => void;
}

export function SceneView({
  bundle,
  rung,
  reduced,
  noyau,
  onClearEmber,
  onComposerFocus,
  onSubmit,
  composerExtras,
  cta,
  onCtaInstruction,
  onCtaPatch,
  onCtaDismiss,
  focus,
  edit,
  checklist,
  onFocusRegion,
}: SceneViewProps) {
  const hotspotLayer = useRef<HTMLDivElement>(null);
  const focusKeys = focus?.keys;
  const panel = slotPanel(focus ?? null);
  // The car regions the focus lights — only real service-type keys glow (a fact key etches the row only).
  const highlight: BodyRegion[] | undefined = focusKeys ? regionsForKeys(focusKeys) : undefined;

  // Cardinal derivations — pure functions of the bundle (the leaves stay dumb).
  const v = bundle?.vehicle;
  const due = bundle?.forecast.due ?? [];
  const today = new Date().toISOString().slice(0, 10);
  const cardinals = v
    ? {
        mileage: v.usageValue ?? null,
        mileageUnit: v.usageUnit,
        healthPct: v.health?.score ?? null,
        daysSinceService: daysSince(latestPerformedAt(due), today),
        overdueCount: v.health?.overdue ?? due.filter((d) => d.overdue).length,
      }
    : null;

  // "Planifier" (#10): a hotspot forecast row → a concrete planned task pegged to the predicted-due
  // reading. Uses the checklist's add path (so it re-reads), and the vehicle's garage-spelling axis unit
  // (NOT the forecast's maintenance-side spelling) for the target. Absent checklist → no planning.
  const onPlan = checklist
    ? (d: ForecastDue) => {
        const target = d.usage ? { value: Math.round(d.usage.due), unit: checklist.defaultUnit } : null;
        checklist.onAddTask({ type: d.type, target, dueDate: target ? null : d.dueAt ?? d.projectedDueAt ?? null });
      }
    : undefined;

  const handleSubmit = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key !== 'Enter' || !onSubmit) return;
    const value = e.currentTarget.value.trim();
    if (!value) return;
    onSubmit(value);
    e.currentTarget.value = '';
  };

  return (
    <>
      {!bundle ? (
        <div className="at-scene-main">
          <div className="at-scene-empty">Parc vide — ajoutez un véhicule.</div>
        </div>
      ) : rung === 'd' ? (
        // canvas-off fallback (weak GPU) — the honest floor.
        <div className="at-scene-floor">
          <TruthPanel vehicle={bundle.vehicle} forecast={bundle.forecast} focusKeys={focusKeys} edit={edit} onPlan={onPlan} />
          <div className="at-scene-floor-right">
            <div className="glass at-scene-planglass">
              <FlatPlanList forecast={bundle.forecast} />
            </div>
          </div>
        </div>
      ) : (
        // hero: the car is full-bleed; the truth-panel floats over it (car reads behind/around it)
        <>
          <div className="at-scene-stage-full" data-dim={focus ? 'true' : undefined}>
            <Plateau vehicle={bundle.vehicle} rung={rung} due={bundle.forecast.due} reduced={reduced} portalTarget={hotspotLayer} highlight={highlight} onFocusRegion={onFocusRegion} />
          </div>
          {/* hotspot cards portal HERE — above the glass (z10) but below the dock (z40). */}
          <div className="at-scene-hotspot-layer" ref={hotspotLayer} />
          {cardinals && (
            <div className="at-scene-cardinals" data-dim={focus ? 'true' : undefined}>
              <CardinalReadouts {...cardinals} reduced={reduced} />
            </div>
          )}
          {/* The single swappable slot (#9/#10). Default = the specs at-a-glance panel; the agent (or a
              car-pin click) swaps it to the task list and lights the car. A focus scales it + dims the
              stage (the spotlight). `tasks` falls back to specs when no checklist handlers (playground). */}
          <aside className="at-scene-slot" data-panel={panel} data-focused={focus ? 'true' : undefined}>
            {panel === 'tasks' && checklist ? (
              <Checklist {...checklist} />
            ) : (
              <TruthPanel vehicle={bundle.vehicle} forecast={bundle.forecast} focusKeys={focusKeys} edit={edit} onPlan={onPlan} />
            )}
          </aside>
        </>
      )}

      <div className="at-scene-dock">
        {cta && onCtaInstruction && onCtaPatch && onCtaDismiss && (
          <CtaCard cta={cta} onInstruction={onCtaInstruction} onPatch={onCtaPatch} onDismiss={onCtaDismiss} reduced={reduced} />
        )}
        <div className="at-scene-noyau">
          <Noyau state={noyau} reduced={reduced} onClearEmber={onClearEmber} />
        </div>
        <div className="at-scene-composer">
          {composerExtras}
          <input
            className="at-scene-input"
            name="composer"
            aria-label="Parlez à l'atelier"
            placeholder="Parlez à l'atelier…  (le focus = à l'écoute)"
            onFocus={() => onComposerFocus?.(true)}
            onBlur={() => onComposerFocus?.(false)}
            onKeyDown={handleSubmit}
          />
        </div>
      </div>
    </>
  );
}
