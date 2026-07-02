import { useEffect, useRef, useState, type ReactNode } from 'react';
import { TruthPanel, type TruthPanelEdit } from '../floor/TruthPanel';
import { FlatPlanList } from '../floor/FlatPlanList';
import { Noyau } from '../noyau/Noyau';
import { AmbientInbox } from '../noyau/AmbientInbox';
import type { DecisionItem, NoyauState } from '../noyau/state';
import { Plateau, type Rung } from './Plateau';
import type { PulseRegion } from './VehicleMesh';
import { Cockpit } from './Cockpit';
import { SpecConstellation } from './SpecConstellation';
import { EntretienRail, type ChecklistHandlers } from './EntretienRail';
import { buildRail } from './rail';
import { CtaCard, type Cta } from './CtaCard';
import { PartsManifest } from './PartsManifest';
import { CarnetProjection } from './CarnetProjection';
import { manifestFromCta } from './parts';
import { rollupCarnet } from '../carnet';
import type { BodyRegion } from './anchors';
import type { CameraFocus } from './cameraRig';
import { FactsDrawer } from './FactsDrawer';
import { HistoryLog } from './HistoryLog';
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
  /** D1 earned camera: the region the view is dollied to (null/absent = home framing / playground). */
  cameraFocus?: CameraFocus | null;
  /** Clears cameraFocus — the "vue libre" reset, Escape, or a user drag breaking the hold (via Plateau). */
  onCameraRelease?: () => void;
  onClearEmber: () => void;
  /** C2 à-décider inbox: remove a decided queue item (backs "vu" + follows every action). */
  onResolveDecision?: (id: string) => void;
  /** C2: promote a notify item (a due service) to a planned task on its own vehicle. */
  onScheduleDecision?: (item: DecisionItem) => void;
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
  /** Dismiss an agent-placed specs focus (clears it). Absent → the projected constellation shows no ✕
   * (playground / resting pins are persistent, never dismissed here). */
  onDismissFocus?: () => void;
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
  cameraFocus = null,
  onCameraRelease,
  onFocusRegion,
  onClearEmber,
  onResolveDecision,
  onScheduleDecision,
  onComposerFocus,
  onSubmit,
  composerExtras,
  cta,
  onCtaInstruction,
  onCtaPatch,
  onCtaDismiss,
  focus,
  onDismissFocus,
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
  // C2: the à-décider inbox opens from the ember. Auto-closes once the queue drains (last item resolved).
  const [inboxOpen, setInboxOpen] = useState(false);
  const queueLen = noyau.queue.length;
  useEffect(() => {
    if (queueLen === 0) setInboxOpen(false);
  }, [queueLen]);
  // Focus management for the inbox dialog (WCAG 2.4.3): the leaf focuses itself on open and unmounts on
  // close, so without restoration a keyboard/AT user is dumped onto <body>. Save the opener at open time,
  // and on close return focus to it if still connected, else to a stable Le Noyau anchor (the muted ember
  // re-entry chip if the queue survives, otherwise the orb) — never nothing.
  const noyauWrapRef = useRef<HTMLDivElement>(null);
  const inboxOpenerRef = useRef<HTMLElement | null>(null);
  const inboxWasOpen = useRef(false);
  useEffect(() => {
    if (inboxWasOpen.current && !inboxOpen) {
      const opener = inboxOpenerRef.current;
      if (opener && opener.isConnected) opener.focus();
      else noyauWrapRef.current?.querySelector<HTMLElement>('.noyau-ember, .noyau-orb')?.focus();
      inboxOpenerRef.current = null;
    }
    inboxWasOpen.current = inboxOpen;
  }, [inboxOpen]);

  // D1: the region the view is dollied to — the active rail-row cue (orange = operator focus) lights it.
  // ONE Escape = ONE step back, innermost first: a projected surface (fiche technique / carnet / specs)
  // closes before a camera hold releases. (The Données/Carnet drawers own Escape themselves while open —
  // this handler never sees it first there because they render above and also close on Escape.)
  const cameraRegion = cameraFocus?.region ?? null;
  const focusOpen = !!focus;
  const drawerOwnsEscape = factsDrawerOpen || historyOpen;
  useEffect(() => {
    if (drawerOwnsEscape) return; // an open Données/Carnet drawer is the innermost layer — it handles Escape
    if (!(focusOpen && onDismissFocus) && !(cameraFocus && onCameraRelease)) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return;
      if (focusOpen && onDismissFocus) onDismissFocus();
      else onCameraRelease?.();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [drawerOwnsEscape, focusOpen, onDismissFocus, cameraFocus, onCameraRelease]);
  const focusKeys = focus?.keys;
  // At rest (no specs focus) the constellation shows PINNED facts only — an empty pin set renders
  // nothing (selectFacts treats an empty `keys` as "show everything", so the empty case is gated here
  // rather than passed through).
  const specsFocused = focus?.panel === 'specs';
  const constellationKeys = specsFocused ? focusKeys ?? [] : pinnedKeys;
  const constellationTitle = specsFocused ? focus.title : 'Épinglés';
  const showConstellation = specsFocused ? true : pinnedKeys.length > 0;
  // A carnet focus (the "Carnet · dépenses" wayfinder / the agent's showHistory) rolls the client-held
  // records into the same projection column. Empty history → nothing (the chip is hidden upstream anyway).
  const carnetFocused = focus?.panel === 'carnet';
  const carnet = carnetFocused ? rollupCarnet(records) : null;
  const showCarnet = !!carnet && carnet.count > 0;

  // "Planifier" (#10): a hotspot forecast row → a concrete planned task pegged to the predicted-due
  // reading. Uses the checklist's add path (so it re-reads), and the vehicle's garage-spelling axis unit
  // (NOT the forecast's maintenance-side spelling) for the target. Absent checklist → no planning.
  const onPlan = checklist
    ? (d: ForecastDue) => {
        const target = d.usage ? { value: Math.round(d.usage.due), unit: checklist.defaultUnit } : null;
        checklist.onAddTask({ type: d.type, target, dueDate: target ? null : d.dueAt ?? d.projectedDueAt ?? null });
      }
    : undefined;

  // The rail model: todo ("À faire" — late, red-tagged row by row) / planned / gaps. The rail is the ONE
  // reading surface for échéances (the ring pins were removed — the disc stays clean; zoom via the rows).
  const rail = bundle ? buildRail(bundle.forecast.due, bundle.forecast.gaps, bundle.vehicle.plannedTasks ?? []) : null;

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
              cameraFocus={cameraFocus}
              onCameraRelease={onCameraRelease}
            />
          </div>
          {/* D1 "vue libre": while the view is dollied to a region, one click (or Escape) eases back to the
              home framing. Orange outline = operator action; ~44px hit zone. Reduced motion: the dolly was
              a cut, so this is an instant return. */}
          {cameraFocus && onCameraRelease && (
            <button type="button" className="at-vuelibre" onClick={onCameraRelease} aria-label="Vue libre — revenir à la vue d’ensemble">
              <span className="at-vuelibre-glyph" aria-hidden="true">⤢</span>
              vue libre
            </button>
          )}
          {/* health now reads as the glowing base halo on Le Plateau's rim (Plateau → Turntable), colour =
              signal status — the over-car HUD ring was moved down to surround the car's base. */}
          {/* the always-on cockpit: identity nameplate + engine badge + editable kilométrage — never etches
              out (unlike the on-demand overlays below), the operator's at-a-glance facts on every visit. */}
          <div className="at-scene-cockpit" data-dim={focus ? 'true' : undefined}>
            <Cockpit vehicle={bundle.vehicle} edit={edit} />
          </div>
          {/* SANTÉ moved off the top-right HUD into the cockpit slab (Cockpit → HealthStat) — the %
              now captions the rim gauge from the identity band instead of floating alone. */}
          {/* the PROJECTION COLUMN (B2): the ONE right-edge slot for everything Le Noyau projects — so the
              operator learns a single glance direction for "the agent answered" (the left "Entretien" rail
              never moves). A specs focus fans the facts as the constellation (agent-placed → cyan tick + ✕);
              at rest, PINNED facts take the same column (operator's own pins → no tick, no ✕). A parts-bearing
              proposal stacks the manifest below. Empty → nothing renders. (The cyan scan is a 3D holo band
              sweeping THROUGH the car via Plateau `scanning`, not a DOM bar.) */}
          {(showConstellation || showCarnet || (showManifest && manifest && onCtaPatch && onCtaDismiss)) && (
            <div className="at-projection">
              {showConstellation && (
                <SpecConstellation
                  facts={bundle.vehicle.facts ?? []}
                  keys={constellationKeys}
                  title={constellationTitle}
                  reduced={reduced}
                  // fetched surfaces (fluids / carte grise) carry their OWN formatted nodes → read-only, no edit.
                  edit={specsFocused && focus?.nodes ? undefined : edit?.facts}
                  agentPlaced={specsFocused}
                  onDismiss={specsFocused ? onDismissFocus : undefined}
                  nodes={specsFocused ? focus?.nodes : undefined}
                />
              )}
              {showCarnet && carnet && (
                <CarnetProjection model={carnet} reduced={reduced} onDismiss={onDismissFocus} onOpenAll={onOpenHistory} />
              )}
              {showManifest && manifest && onCtaPatch && onCtaDismiss && (
                <PartsManifest model={manifest} reduced={reduced} onAdd={onCtaPatch} onDismiss={onCtaDismiss} />
              )}
            </div>
          )}
          {/* the persistent left rail ("Entretien"): À faire (late, red-tagged) / Planifié / non suivi —
              checkable tasks, plannable predictions. Screen-locked on the hero's left edge. Clicking a
              row's empty area dollies to its region (again = release). Absent `checklist` → no rail. */}
          {checklist && rail && (
            <EntretienRail
              model={rail}
              checklist={checklist}
              onPlan={onPlan!}
              onActivateRegion={onFocusRegion}
              cameraRegion={cameraRegion}
              dimmed={!!focus || overlayOpen}
            />
          )}
        </>
      )}

      <div className={`at-scene-dock${factsDrawerOpen ? ' at-scene-dock--top' : historyOpen ? ' at-scene-dock--side' : ''}`}>
        {cta && !showManifest && onCtaInstruction && onCtaPatch && onCtaDismiss && (
          <CtaCard cta={cta} onInstruction={onCtaInstruction} onPatch={onCtaPatch} onDismiss={onCtaDismiss} reduced={reduced} />
        )}
        <div className="at-scene-noyau" ref={noyauWrapRef}>
          <Noyau
            state={noyau}
            reduced={reduced}
            onClearEmber={onClearEmber}
            onEmberActivate={() => {
              // Capture the opener BEFORE the state mutation so focus can return here on close.
              inboxOpenerRef.current = document.activeElement as HTMLElement | null;
              setInboxOpen(true);
              onClearEmber(); // opening the inbox mutes the badge (but keeps the queue)
            }}
          />
          <AmbientInbox
            open={inboxOpen}
            queue={noyau.queue}
            reduced={reduced}
            onClose={() => setInboxOpen(false)}
            onResolve={(id) => onResolveDecision?.(id)}
            onReviewInstruction={onCtaInstruction}
            onReviewPatch={onCtaPatch}
            onSchedule={onScheduleDecision}
          />
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
          quickActions && quickActions.length > 0 && (
            <div className="at-scene-chips">
              {quickActions.map((qa, i) => (
                <button key={i} type="button" className="at-scene-chip" onClick={qa.onClick} disabled={qa.disabled}>
                  {qa.label}
                </button>
              ))}
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
