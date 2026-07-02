import { useState, type ReactNode } from 'react';
import { TruthPanel, type TruthPanelEdit } from '../floor/TruthPanel';
import { FlatPlanList } from '../floor/FlatPlanList';
import { Noyau } from '../noyau/Noyau';
import type { NoyauState } from '../noyau/state';
import { Plateau, type Rung } from './Plateau';
import type { PulseRegion } from './VehicleMesh';
import { Cockpit } from './Cockpit';
import { SpecConstellation } from './SpecConstellation';
import { Checklist, type ChecklistHandlers } from './Checklist';
import { CtaCard, type Cta } from './CtaCard';
import { PartsManifest } from './PartsManifest';
import { manifestFromCta } from './parts';
import type { BodyRegion } from './anchors';
import { FactsDrawer } from './FactsDrawer';
import { HistoryLog } from './HistoryLog';
import { EcheancePanels } from './EcheancePanels';
import { buildEcheances } from './echeances';
import { splitEcheances } from './ringEcheances';
import type { Focus } from '../focus';
import type { ForecastDue, Iri, MaintenanceRecord, PlannedPart, VehicleBundle } from '../types';

// The PURE presentational scene — the main region (empty / honest-floor / hero) + the dock (Le Noyau
// over the composer). It owns NO data source, no turn lifecycle, no crossing logic: a driver feeds it
// an already-resolved bundle + the Noyau state and the composer handlers. Both the fixture playground
// (AtelierScene) and the live orchestrator (Atelier) render THIS, so the view model is written once.
// Z-order is owned by scene.css: stage canvas (z0) → glass (z10) → hotspot cards (z30) → dock (z40)
// → Le Noyau (z50). Placed by the driver inside a `.at-scene` flex column alongside its own chrome.

export type { Rung } from './Plateau';

/** A deterministic quick-action chip (above the composer): resolves client-side, NEVER a `postTurn`. */
export interface QuickAction {
  label: string;
  onClick: () => void;
  disabled?: boolean;
}

interface SceneViewProps {
  /** The vehicle to show (already crossing-resolved by the driver). Null → empty parc. */
  bundle: VehicleBundle | null;
  rung: Rung;
  reduced: boolean;
  noyau: NoyauState;
  /** PULSE verb: the region the agent is currently acting on (one-shot cyan flare on the car). The
   * `key` re-triggers the flare even for a repeat region; null = at rest. Absent → playground (no pulse). */
  pulseRegion?: PulseRegion | null;
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
  /** Deterministic quick-action chips shown above the composer (client-side, no agent turn). */
  quickActions?: QuickAction[];
  /** Pinned fact keys (persisted per-vehicle by the driver) — what the resting constellation shows. */
  pinnedKeys: string[];
  onTogglePin: (key: string) => void;
  /** The "Fiche technique" drawer (full fact sheet + pinning) — driver owns open/close state. */
  factsDrawerOpen: boolean;
  onOpenFacts?: () => void;
  onCloseFacts?: () => void;
  /** Past service visits for the "Historique" quick action (Le Carnet). Driver owns open/close state.
   * onOpen* are mutually exclusive in the driver (opening one closes the other). */
  records: MaintenanceRecord[];
  historyOpen: boolean;
  onOpenHistory?: () => void;
  onCloseHistory?: () => void;
}

export function SceneView({
  bundle,
  rung,
  reduced,
  noyau,
  pulseRegion = null,
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
  quickActions,
  pinnedKeys,
  onTogglePin,
  factsDrawerOpen,
  // onOpenFacts: the driver opens the drawer itself (the "Fiche technique" quick-action chip already
  // calls it before SceneView ever sees the click) — SceneView only needs the open flag + onCloseFacts.
  onOpenFacts,
  onCloseFacts,
  records,
  historyOpen,
  onOpenHistory,
  onCloseHistory,
}: SceneViewProps) {
  // Ephemeral view state (NOT data): which échéance set the ring panels show, and whether the right
  // task dock is pulled open. Both reset naturally on unmount; the driver owns no part of them.
  const [showUpcoming, setShowUpcoming] = useState(false);
  const [dockOpen, setDockOpen] = useState(true);
  const focusKeys = focus?.keys;
  // At rest (no specs focus) the constellation shows PINNED facts only — an empty pin set renders
  // nothing (selectFacts treats an empty `keys` as "show everything", so the empty case is gated here
  // rather than passed through).
  const constellationKeys = focus?.panel === 'specs' ? focusKeys ?? [] : pinnedKeys;
  const constellationTitle = focus?.panel === 'specs' ? focus.title : 'Épinglés';
  const showConstellation = focus?.panel === 'specs' ? true : pinnedKeys.length > 0;

  // "Planifier" (#10): a hotspot forecast row → a concrete planned task pegged to the predicted-due
  // reading. Uses the checklist's add path (so it re-reads), and the vehicle's garage-spelling axis unit
  // (NOT the forecast's maintenance-side spelling) for the target. Absent checklist → no planning.
  const onPlan = checklist
    ? (d: ForecastDue) => {
        const target = d.usage ? { value: Math.round(d.usage.due), unit: checklist.defaultUnit } : null;
        checklist.onAddTask({ type: d.type, target, dueDate: target ? null : d.dueAt ?? d.projectedDueAt ?? null });
      }
    : undefined;

  // The échéances: no longer in the dock (Checklist-only now) — they float around the ring as fixed
  // panels (EcheancePanels). Split into the DUE (overdue) vs UPCOMING sets; a bottom chip toggles which
  // set is shown. Default = dues, falling back to upcoming when nothing is overdue.
  const echeances = bundle
    ? buildEcheances(bundle.forecast.due, bundle.forecast.gaps, bundle.vehicle.plannedTasks ?? [])
    : [];
  const { dues, upcoming } = splitEcheances(echeances);
  const showUpcomingEch = showUpcoming || dues.length === 0;
  const activeEch = showUpcomingEch ? upcoming : dues;
  const canToggleEch = dues.length > 0 && upcoming.length > 0;

  // When a full surface (Données drawer / Le Carnet) is open, the dock slides to the LEFT free column so
  // Le Noyau + its narration + the composer stay visible beside it, and the chips swap to that surface's
  // context (switch to the sibling surface, or close). Opening one surface closes the other (driver).
  const overlayOpen = factsDrawerOpen || historyOpen;
  const contextChips: QuickAction[] = factsDrawerOpen
    ? [
        { label: 'Historique', onClick: () => onOpenHistory?.() },
        { label: 'Fermer', onClick: () => onCloseFacts?.() },
      ]
    : historyOpen
      ? [
          { label: 'Données', onClick: () => onOpenFacts?.() },
          { label: 'Fermer', onClick: () => onCloseHistory?.() },
        ]
      : [];

  // A parts-bearing proposal projects as the rich manifest at the car's right (3D rungs); a plain proposal
  // stays the dock CtaCard. On the canvas-off rung d the CtaCard carries every proposal (no overlay there).
  const manifest = manifestFromCta(cta ?? null);
  const showManifest = !!manifest && rung !== 'd';

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
        // hero: the car is full-bleed and IS the interface. Always-on chrome wraps it (cockpit top-left,
        // moteur constellation left, HUD rings, cardinals, échéances+checklist dock right); on-demand
        // agent surfaces project over it (parts manifest right, spec focus). (option 2a, cockpit revision)
        <>
          <div className="at-scene-stage-full" data-dim={focus ? 'true' : undefined}>
            <Plateau
              vehicle={bundle.vehicle}
              rung={rung}
              due={bundle.forecast.due}
              reduced={reduced}
              scanning={noyau.phase === 'working'}
              pulseRegion={pulseRegion}
            />
          </div>
          {/* health now reads as the glowing base halo on Le Plateau's rim (Plateau → Turntable), colour =
              signal status — the over-car HUD ring was moved down to surround the car's base. */}
          {/* the always-on cockpit: identity nameplate + engine badge + editable kilométrage — never etches
              out (unlike the on-demand overlays below), the operator's at-a-glance facts on every visit. */}
          <div className="at-scene-cockpit" data-dim={focus ? 'true' : undefined}>
            <Cockpit vehicle={bundle.vehicle} edit={edit} />
          </div>
          {/* SANTÉ moved off the top-right HUD into the cockpit slab (Cockpit → HealthStat) — the %
              now captions the rim gauge from the identity band instead of floating alone. */}
          {/* échéances float around the ring's left/bottom-left arc (never rotating with the car) — the
              wrapper spans the whole stage so EcheancePanels' angle layout is relative to the disc centre. */}
          <div className="at-scene-echeances" data-dim={focus ? 'true' : undefined}>
            <EcheancePanels items={activeEch} onPlan={onPlan} reduced={reduced} />
          </div>
          {/* on-demand agent surface: a specs focus fans the vehicle's facts to the car's LEFT as a
              constellation (never a docked panel). At rest, PINNED facts (from the facts drawer) take
              over the same slot — empty pins render nothing, so no duplicate of the cockpit's row. */}
          {showConstellation && (
            <SpecConstellation
              facts={bundle.vehicle.facts ?? []}
              keys={constellationKeys}
              title={constellationTitle}
              reduced={reduced}
              edit={edit?.facts}
            />
          )}
          {/* the cyan scan is now a 3D holo band sweeping THROUGH the car (Plateau `scanning`) — no DOM bar */}
          {/* parts manifest — the rich right-side form of a parts-bearing proposal (contract-free option a) */}
          {showManifest && manifest && onCtaPatch && onCtaDismiss && (
            <PartsManifest model={manifest} reduced={reduced} onAdd={onCtaPatch} onDismiss={onCtaDismiss} />
          )}
          {/* persistent task dock (#F): the open-checklist lives ON the hero, right edge, styled as a
              working scratchpad — échéances moved off the dock onto the ring/panels. Absent `checklist`
              (playground) → no dock. */}
          {checklist && (
            <div className="at-scene-taskdock" data-open={dockOpen ? 'true' : 'false'} data-dim={focus ? 'true' : undefined}>
              <button
                type="button"
                className="at-scene-taskdock-tab"
                aria-label={dockOpen ? "Replier l'entretien en cours" : "Ouvrir l'entretien en cours"}
                aria-expanded={dockOpen}
                onClick={() => setDockOpen((o) => !o)}
              >
                <span className="at-scene-taskdock-tab-glyph" aria-hidden="true">{dockOpen ? '›' : '‹'}</span>
                <span className="at-scene-taskdock-tab-label">Entretien</span>
              </button>
              <div className="at-scene-taskdock-body">
                <Checklist {...checklist} />
              </div>
            </div>
          )}
        </>
      )}

      <div className={`at-scene-dock${factsDrawerOpen ? ' at-scene-dock--top' : historyOpen ? ' at-scene-dock--side' : ''}`}>
        {cta && !showManifest && onCtaInstruction && onCtaPatch && onCtaDismiss && (
          <CtaCard cta={cta} onInstruction={onCtaInstruction} onPatch={onCtaPatch} onDismiss={onCtaDismiss} reduced={reduced} />
        )}
        <div className="at-scene-noyau">
          <Noyau state={noyau} reduced={reduced} onClearEmber={onClearEmber} />
        </div>
        {overlayOpen ? (
          <div className="at-scene-chips">
            {contextChips.map((qa, i) => (
              <button key={i} type="button" className="at-scene-chip" onClick={qa.onClick}>
                {qa.label}
              </button>
            ))}
          </div>
        ) : (
          ((quickActions && quickActions.length > 0) || canToggleEch) && (
            <div className="at-scene-chips">
              {quickActions?.map((qa, i) => (
                <button key={i} type="button" className="at-scene-chip" onClick={qa.onClick} disabled={qa.disabled}>
                  {qa.label}
                </button>
              ))}
              {canToggleEch && (
                <button type="button" className="at-scene-chip" onClick={() => setShowUpcoming((s) => !s)}>
                  {showUpcomingEch ? `Voir échéances dues (${dues.length})` : `Voir prochaines échéances (${upcoming.length})`}
                </button>
              )}
            </div>
          )
        )}
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

      {bundle && (
        <FactsDrawer
          open={factsDrawerOpen}
          onClose={onCloseFacts ?? (() => {})}
          facts={bundle.vehicle.facts ?? []}
          pinnedKeys={pinnedKeys}
          onTogglePin={onTogglePin}
          edit={edit?.facts && { onEditValue: edit.facts.onEditValue, onRemove: edit.facts.onRemove }}
          reduced={reduced}
        />
      )}

      <HistoryLog open={historyOpen} onClose={onCloseHistory ?? (() => {})} records={records} reduced={reduced} />
    </>
  );
}
