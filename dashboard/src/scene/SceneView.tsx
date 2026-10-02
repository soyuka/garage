import { useCallback, useEffect, useMemo, useReducer, useRef, useState, type ReactNode } from 'react';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { TruthPanel, type TruthPanelEdit } from '../floor/TruthPanel';
import { FlatPlanList } from '../floor/FlatPlanList';
import { Noyau } from '../noyau/Noyau';
import { NoyauVoice } from '../noyau/NoyauVoice';
import { AmbientInbox } from '../noyau/AmbientInbox';
import { NoticeStack } from '../noyau/NoticeStack';
import type { DecisionItem, NoyauState } from '../noyau/state';
import { Plateau, type Rung } from './Plateau';
import { agentLightOf } from './agentLight';
import type { RevealState } from './reveal';
import type { PulseRegion } from './VehicleMesh';
import { Cockpit } from './Cockpit';
import { SpecConstellation } from './SpecConstellation';
import { EntretienRail, type ChecklistHandlers } from './EntretienRail';
import { Composer } from './Composer';
import type { ArmedMicCue } from '../noyau/useCues';
import { buildRail } from './rail';
import { buildVisits } from './visits';
import { buildShoppingList } from './shopping';
import { CtaCard, type Cta } from './CtaCard';
import { PartsManifest } from './PartsManifest';
import { CarnetProjection } from './CarnetProjection';
import { WorklistProjection } from './WorklistProjection';
import { VisitProjection } from './VisitProjection';
import { ShoppingListOverlay } from './ShoppingList';
import { DocumentationPanel, type DocumentationHandlers } from './DocumentationPanel';
import { buildDocumentation } from './documentation';
import { buildWorklist } from './worklist';
import { manifestFromCta } from './parts';
import { rollupCarnet, filterCarnetRecords } from '../carnet';
import { deriveCameraFocus, holdIntent, type CameraFocus } from './cameraRig';
import type { BodyRegion } from './anchors';
import { FactsDrawer } from './FactsDrawer';
import { HistoryLog } from './HistoryLog';
import { visitRingLayout } from './visitRing';
import { factConstellationLayout } from './factConstellation';
import { fluidVolumesLayout } from './fluidVolumes';
import { bodyClassFor } from './CarProxy';
import { legendFocusReducer } from './picking';
import { stationFor } from './stations';
import { selectFacts } from './SpecConstellation';
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
  /** Truthful landing zone for the chip micro-map — where the chip's effect actually renders. Set this
   * ONLY after checking the real render target (never copy a neighbor's value); omit when the
   * destination is ambiguous or rung-dependent — a missing zone renders no map, a wrong one lies. */
  zone?: 'rail' | 'centre' | 'colonne';
  /** Set when the chip posts a real agent turn instead of resolving client-side (CHOREOGRAPHY §
   * "Asking the agent from the UI"). Rendered with the cyan agent mark — cyan is rationed to Le
   * Noyau, so this is the one thing that tells the two chip families apart before either is clicked. */
  agent?: boolean;
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
  /** C1–C4: the region's schematic assembly to raise under a ghosted shell. Anchored to `cameraFocus` —
   * releasing the hold clears it (reveal.ts revealSurvives), so there is no separate dismiss. */
  reveal?: RevealState | null;
  /** Clears cameraFocus — the "vue libre" reset, Escape, or a user drag breaking the hold (via Plateau). */
  onCameraRelease?: () => void;
  /** D1 from the AMBIENT layer: the operator aimed a rail visit card at its body region. Dolly only — no
   * cyan pulse, because Le Noyau did not do this (CHOREOGRAPHY § "the operator clicks a visit card's
   * head"). Absent → the card heads render as plain captions, never dead controls. */
  onHoldRegion?: (region: BodyRegion) => void;
  onClearEmber: () => void;
  /** C2 à-décider inbox: remove a decided queue item (backs "vu" + follows every action). */
  onResolveDecision?: (id: string) => void;
  /** C2: promote a notify item (a due service) to a planned task on its own vehicle. */
  onScheduleDecision?: (item: DecisionItem) => void;
  /** "vu" on a retained notice card. Required — a card the operator cannot dismiss is a dead control. */
  onDismissNotice: (id: string) => void;
  /** Audition: is the Atelier speaking its answers? Ships false. Absent → the affordance is hidden. */
  /** Composer focus toggles Le Noyau's listening cue (a local UI event, not a wire frame). */
  onComposerFocus?: (active: boolean) => void;
  /** Submit an instruction (Enter). Live → posts an agent turn; absent → input is inert (pre-agent). */
  onSubmit?: (instruction: string) => void;
  /** Driver-injected controls rendered before the composer input (the playground's ▶/⚠ sims). */
  composerExtras?: ReactNode;
  /** The composer's mic is open — the caller holds the audition while it is (§ "La dictée"). */
  onListening?: (active: boolean) => void;
  /** A turn is running: the composer's mic is inert, exactly as its submit is. */
  composerBusy?: boolean;
  armMicCue?: () => ArmedMicCue;
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
  /** Dismiss an agent-placed specs focus (clears it). Absent → the projected constellation shows no ✕
   * (playground / resting pins are persistent, never dismissed here). */
  onDismissFocus?: () => void;
  /** Raise the visit ladder (« Plus tard · n » in the rail). Client-synthesized focus — the agent
   * never emits `panel:'visits'`. Absent → the rail renders the count as plain text, never a dead control. */
  onShowLaterVisits?: () => void;
  /** Deterministic quick-action chips shown above the composer (client-side, no agent turn). */
  quickActions?: QuickAction[];
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
  /** Writes from the documentation panel (open / delete / upload). Absent → the panel is read-only. */
  documentation?: DocumentationHandlers;
}

/** The chip destination micro-map: 3 neutral cells in a fixed rail/centre/colonne order, so the eye
 * learns the mapping positionally. Neutral grey only (brand law) — never cyan/orange/signal. */
function ChipZoneMap({ zone }: { zone: 'rail' | 'centre' | 'colonne' }) {
  return (
    <span className="at-scene-chip-map" aria-hidden="true">
      <i className="at-scene-chip-cell" data-active={zone === 'rail' || undefined} />
      <i className="at-scene-chip-cell" data-active={zone === 'centre' || undefined} />
      <i className="at-scene-chip-cell" data-active={zone === 'colonne' || undefined} />
    </span>
  );
}

export function SceneView({
  bundle,
  rung,
  reduced,
  noyau,
  pulseRegion = null,
  cameraFocus = null,
  reveal = null,
  onCameraRelease,
  onHoldRegion,
  onClearEmber,
  onResolveDecision,
  onScheduleDecision,
  onDismissNotice,
  onComposerFocus,
  onSubmit,
  composerExtras,
  onListening,
  composerBusy,
  armMicCue,
  cta,
  onCtaInstruction,
  onCtaPatch,
  onCtaDismiss,
  focus,
  onDismissFocus,
  onShowLaterVisits,
  edit,
  checklist,
  quickActions,
  factsDrawerOpen,
  // onOpenFacts: the driver opens the drawer itself (the "Fiche technique" quick-action chip already
  // calls it before SceneView ever sees the click) — SceneView only needs the open flag + onCloseFacts.
  onOpenFacts,
  onCloseFacts,
  records,
  historyOpen,
  onOpenHistory,
  onCloseHistory,
  documentation,
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
  const [locale] = useLocale();
  const t = useT();
  const consoleRef = useRef<HTMLDivElement>(null);
  const inboxOpenerRef = useRef<HTMLElement | null>(null);
  const inboxWasOpen = useRef(false);
  useEffect(() => {
    if (inboxWasOpen.current && !inboxOpen) {
      const opener = inboxOpenerRef.current;
      if (opener && opener.isConnected) opener.focus();
      // The orb is now `role="img"` with no `tabIndex` (Le Noyau split) — `.focus()` on it is a silent
      // no-op. Fall back to the composer input, always present and focusable: return the operator to
      // the thing they type into rather than stranding focus on <body> (WCAG 2.4.3, never nothing).
      else consoleRef.current?.querySelector<HTMLElement>('.noyau-ember, .at-scene-input')?.focus();
      inboxOpenerRef.current = null;
    }
    inboxWasOpen.current = inboxOpen;
  }, [inboxOpen]);

  // ONE Escape = ONE step back, innermost first: a projected surface (fiche technique / carnet / specs)
  // closes before a camera hold releases. (The Données/Carnet drawers own Escape themselves while open —
  // this handler never sees it first there because they render above and also close on Escape.)
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
  // Which station's BODY belongs on the disc (CHOREOGRAPHY § "Stations under both drivers"): a docked
  // legend (the full Carnet/Fiche drawer) wins outright; otherwise the focus decides (fluids checked
  // before carnet/specs, since a specs focus carrying `nodes` is a fetched surface, never stored facts).
  const station = stationFor({ historyOpen, factsDrawerOpen, focus });

  // `records` rarely changes while the ring holds, so this is cheap to recompute on every render. A
  // carnet FOCUS (the agent's/pilot's `showHistory`/`focusView{panel:'carnet'}`) rolls the ring too —
  // cut to the same keys the legend applies (`filterCarnetRecords`, shared with `rollupCarnet` below),
  // so "the legend shows exactly those, the ring shows exactly those: truth first".
  const visits = useMemo(() => {
    if (historyOpen) return visitRingLayout(records);
    if (station === 'carnet') return visitRingLayout(filterCarnetRecords(records, focusKeys ?? []));
    return null;
  }, [historyOpen, station, records, focusKeys]);
  const ringOpen = visits != null;
  const [hoveredVisit, setHoveredVisit] = useState<string | null>(null);
  // The body → legend pointer (§ "Picking — the body points back"): a body hover/leave/pick funnels
  // through ONE reducer shared by all three stations, scoped by `kind` so they never clear each other's
  // pick. A pick "is a look, not an action" — nothing is selected, nothing opens.
  const [legendFocus, dispatchLegendFocus] = useReducer(legendFocusReducer, null);
  const onHoverVisit = useCallback((key: string | null) => {
    setHoveredVisit(key);
    dispatchLegendFocus({ type: 'hover', kind: 'visit', key });
  }, []);
  const onPickVisit = useCallback((key: string) => dispatchLegendFocus({ type: 'pick', kind: 'visit', key }), []);
  useEffect(() => {
    if (!ringOpen) {
      setHoveredVisit(null);
      dispatchLegendFocus({ type: 'leave', kind: 'visit' });
    }
  }, [ringOpen]);
  // The fact constellation — same on-demand shape as the visit ring: the full sheet while the Fiche
  // drawer is open, or a specs FOCUS's own cut (`selectFacts`, the same helper SpecConstellation uses)
  // when the agent pointed at a few keys without a fetched `nodes` surface. An empty cut is no station,
  // same as the fluids row below.
  const bodyClass = bundle ? bodyClassFor(bundle.vehicle.vehicleType) : null;
  const facts = useMemo(() => {
    if (!bundle || !bodyClass) return null;
    if (factsDrawerOpen) return factConstellationLayout(bundle.vehicle.facts ?? [], bodyClass);
    if (station === 'specs') {
      const selected = selectFacts(bundle.vehicle.facts ?? [], focusKeys ?? []);
      return selected.length > 0 ? factConstellationLayout(selected, bodyClass) : null;
    }
    return null;
  }, [factsDrawerOpen, station, bundle, bodyClass, focusKeys]);
  const constellationOpen = facts != null;
  const [hoveredFact, setHoveredFact] = useState<string | null>(null);
  const onHoverFact = useCallback((key: string | null) => {
    setHoveredFact(key);
    dispatchLegendFocus({ type: 'hover', kind: 'fact', key });
  }, []);
  const onPickFact = useCallback((key: string) => dispatchLegendFocus({ type: 'pick', kind: 'fact', key }), []);
  useEffect(() => {
    if (!constellationOpen) {
      setHoveredFact(null);
      dispatchLegendFocus({ type: 'leave', kind: 'fact' });
    }
  }, [constellationOpen]);
  // The fluid volumes station — third and last: only when no legend (Carnet/fiche) is docked, the specs
  // panel is showing PROJECTED nodes (a fetched surface, never the stored facts), and at least one of
  // those nodes carries a body (§ "Fluids — the volumes", precedence: legends win over the specs column).
  // An empty layout (every node info-only) is treated as no station, same as the ring/constellation.
  const fluidsStationCandidate = !historyOpen && !factsDrawerOpen && focus?.panel === 'specs' && focus.nodes ? focus.nodes : null;
  const fluids = useMemo(() => {
    if (!fluidsStationCandidate) return null;
    const layout = fluidVolumesLayout(fluidsStationCandidate);
    return layout.length > 0 ? layout : null; // an empty layout (every node info-only) is no station.
  }, [fluidsStationCandidate]);
  const fluidsOpen = fluids != null;
  const [hoveredFluid, setHoveredFluid] = useState<string | null>(null);
  const onHoverFluid = useCallback((key: string | null) => {
    setHoveredFluid(key);
    dispatchLegendFocus({ type: 'hover', kind: 'fluid', key });
  }, []);
  const onPickFluid = useCallback((key: string) => dispatchLegendFocus({ type: 'pick', kind: 'fluid', key }), []);
  useEffect(() => {
    if (!fluidsOpen) {
      setHoveredFluid(null);
      dispatchLegendFocus({ type: 'leave', kind: 'fluid' });
    }
  }, [fluidsOpen]);
  // The carnet/specs FOCUS stations are open (for camera purposes) only when their cut is non-empty —
  // same rule as fluids above. History/Data (the drawers) stay unconditionally "open" while docked: the
  // drawer earns its dolly even showing "no records yet", unlike a focus with nothing to show.
  const carnetStationOpen = station === 'carnet' && ringOpen;
  const specsStationOpen = station === 'specs' && constellationOpen;
  // A drag breaking the hold (Plateau's onCameraRelease) must not re-dolly to the station pose on release
  // — tracked separately from the stations' own open flags so the drawer/carnet/column itself can stay
  // open with the camera set free. The stations are mutually exclusive by precedence (`stationFor`), so
  // ONE released flag covers whichever station is open.
  const [stationHoldReleased, setStationHoldReleased] = useState(false);
  useEffect(() => {
    if (historyOpen || factsDrawerOpen || fluidsOpen || carnetStationOpen || specsStationOpen) setStationHoldReleased(false);
  }, [historyOpen, factsDrawerOpen, fluidsOpen, carnetStationOpen, specsStationOpen]);
  const onPlateauCameraRelease = useCallback(() => {
    onCameraRelease?.();
    setStationHoldReleased(true);
  }, [onCameraRelease]);
  const manifest = manifestFromCta(cta ?? null, bundle?.vehicle.plannedTasks ?? []);
  const showManifest = !!manifest && rung !== 'd';
  // Station camera precedence lives in `station` (one tested place, `stations.ts`): legends first, then
  // carnet/specs (history/data pose, projection shift), fluids last.
  const baseCameraFocus =
    station === 'history'
      ? deriveCameraFocus(cameraFocus, true, 'history', stationHoldReleased)
      : station === 'data'
        ? deriveCameraFocus(cameraFocus, true, 'data', stationHoldReleased)
        : station === 'carnet'
          ? deriveCameraFocus(cameraFocus, carnetStationOpen, 'carnet', stationHoldReleased)
          : station === 'specs'
            ? deriveCameraFocus(cameraFocus, specsStationOpen, 'specs', stationHoldReleased)
            : deriveCameraFocus(cameraFocus, fluidsOpen, 'fluids', stationHoldReleased);
  const plateauCameraFocus = reveal && showManifest && baseCameraFocus?.mode === 'reveal' ? { ...baseCameraFocus, avoidProjection: true } : baseCameraFocus;
  // The constellation is a PROJECTED surface only: it exists when the agent (or a chip) answers a spec
  // question, and not otherwise. It used to double as a resting shelf of operator-pinned facts, which
  // gave one surface two meanings and left the column occupied by something nobody had asked for.
  const specsFocused = focus?.panel === 'specs';
  const showConstellation = specsFocused;
  // A carnet focus (the "Carnet · dépenses" wayfinder / the agent's showHistory) rolls the client-held
  // records into the same projection column. Empty history → nothing (the chip is hidden upstream anyway).
  const carnetFocused = focus?.panel === 'carnet';
  // Keys CUT the register to the visits carrying those services — « quand la dernière vidange ? » is a
  // question about ONE service, and every visit is not its answer. Same narrowing the worklist already
  // does for `focusView {panel:'tasks', keys}`.
  const carnet = carnetFocused ? rollupCarnet(records, locale, 3, focusKeys ?? []) : null;
  const showCarnet = !!carnet && carnet.count > 0;
  // A tasks focus (`focusView {panel:'tasks'}` — « qu'est-ce que j'ai à faire ? ») projects the
  // WORKLIST into the same column: the agent's cut of the rail, in the order it answered.
  const tasksFocused = focus?.panel === 'tasks';
  // The visit ladder (« Plus tard · n ») is a side projection like the others — it must NOT dim the
  // stage, so it is resolved here, above `dimScene`.
  const ladderFocused = focus?.panel === 'visits';
  // « Pièces à commander » — the references already chosen across the OPEN checklist, grouped by job. A
  // side projection like the others, so it never dims the stage.
  const shopFocused = focus?.panel === 'parts';
  // « Documentation » — the vehicle's paper (manuals, notes, quotes, bills). Same centred surface as the
  // shopping list, so it never dims the stage either. An empty file still opens: it is where the first
  // document gets added.
  const docsFocused = focus?.panel === 'docs';
  // The carnet, the fiche technique (specs) and the worklist are side-column projections, not blocking
  // surfaces — they read as integrated with the scene, so none of them dims it. Each closes on its own
  // ✕ + Escape.
  //
  // 'tasks' used to fall through to `true` here and dim the scene for a panel that did not exist: the
  // car went to 45 % and NOTHING replaced it, which is what the operator saw on 2026-08-15 (a blurred
  // stage with no answer). A focus that dims must fill the attention it took.
  const dimScene = !!focus && !carnetFocused && !specsFocused && !tasksFocused && !ladderFocused && !shopFocused && !docsFocused;

  // "Planifier" (#10): a hotspot forecast row → a concrete planned task pegged to the predicted-due
  // reading. Uses the checklist's add path (so it re-reads), and the vehicle's garage-spelling axis unit
  // (NOT the forecast's maintenance-side spelling) for the target. Absent checklist → no planning.
  const onPlan = checklist
    ? (d: ForecastDue) => {
        const target = d.usage ? { value: Math.round(d.usage.due), unit: checklist.defaultUnit } : null;
        checklist.onAddTask({ type: d.type, target, dueDate: target ? null : d.dueAt ?? d.projectedDueAt ?? null });
      }
    : undefined;

  // The rail's card heads are a TOGGLE on the earned camera: re-aiming the region already held goes back
  // ("click again to return", D1). The rail stays dumb about the camera — the decision lives in cameraRig
  // and the two callbacks are already here.
  const aimRegion =
    onHoldRegion && onCameraRelease
      ? (region: BodyRegion) => (holdIntent(cameraFocus, region) === 'release' ? onCameraRelease() : onHoldRegion(region))
      : undefined;

  // The rail model: todo ("À faire" — late, red-tagged row by row) / planned / gaps. The rail is the ONE
  // reading surface for échéances (the ring pins were removed — the disc stays clean; zoom via the rows).
  const rail = bundle ? buildRail(bundle.forecast.due, locale, bundle.forecast.gaps, bundle.vehicle.plannedTasks ?? []) : null;
  // The worklist is a CUT of that same rail (never a second source): no keys → the actionable workload,
  // keys → exactly those services plus an honest note for any the vehicle does not track.
  const worklist = tasksFocused && rail ? buildWorklist(rail, focusKeys ?? []) : null;
  const showWorklist = !!worklist && (worklist.items.length > 0 || worklist.missing.length > 0);
  // The visit plan is the SAME rail regrouped by trip to the lift (visits.ts) — the rail renders it
  // instead of the flat buckets, and « Plus tard · n » projects its tail as the ladder.
  const plan = bundle ? buildVisits(bundle.forecast.due, locale, bundle.forecast.gaps, bundle.vehicle.plannedTasks ?? []) : null;
  const showLadder = ladderFocused && !!plan && plan.later.length > 0;
  const shopping = shopFocused && bundle ? buildShoppingList(bundle, locale) : null;
  const showShopping = !!shopping && shopping.groups.length > 0;
  const docs = docsFocused && bundle ? buildDocumentation(bundle.documents, bundle.records, bundle.vehicle.plannedTasks ?? [], locale) : null;

  // When a full surface (Données drawer / Le Carnet) is open, the dock slides to the LEFT free column so
  // Le Noyau + its narration + the composer stay visible beside it, and the chips swap to that surface's
  // context (switch to the sibling surface, or close). Opening one surface closes the other (driver).
  const overlayOpen = factsDrawerOpen || historyOpen;
  const contextChips: QuickAction[] = factsDrawerOpen
    ? [
        { label: t.scene.history, onClick: () => onOpenHistory?.() },
        { label: t.scene.close, onClick: () => onCloseFacts?.() },
      ]
    : historyOpen
      ? [
          { label: t.scene.data, onClick: () => onOpenFacts?.() },
          { label: t.scene.close, onClick: () => onCloseHistory?.() },
        ]
      : [];

  // The chip rail is a single horizontal line; a mouse wheel only emits deltaY, so translate it to
  // horizontal scroll (trackpads already scroll X). The stage is a fixed full-bleed surface with no
  // vertical page scroll, so no preventDefault is needed.
  const railWheel = (e: React.WheelEvent<HTMLDivElement>) => {
    if (e.deltaY) e.currentTarget.scrollLeft += e.deltaY;
  };

  // While Le Noyau works, its live phrase takes the chip rail's slot (same vertical space → the console
  // never shifts). 'done' auto-settles back to idle (useNoyau), so this reverts to the chips on its own.
  const speaking = noyau.phase === 'working' || noyau.phase === 'done';

  return (
    <>
      {!bundle ? (
        <div className="at-scene-main">
          <div className="at-scene-empty">{t.scene.emptyFleet}</div>
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
          <div className="at-scene-stage-full" data-dim={dimScene ? 'true' : undefined}>
            <Plateau
              vehicle={bundle.vehicle}
              rung={rung}
              due={bundle.forecast.due}
              reduced={reduced}
              scanning={noyau.phase === 'working'}
              agent={agentLightOf(noyau)}
              pulseRegion={pulseRegion}
              cameraFocus={plateauCameraFocus}
              reveal={reveal}
              visits={visits}
              hoveredVisit={hoveredVisit}
              onHoverVisit={onHoverVisit}
              onPickVisit={onPickVisit}
              facts={facts}
              hoveredFact={hoveredFact}
              onHoverFact={onHoverFact}
              onPickFact={onPickFact}
              fluids={fluids}
              hoveredFluid={hoveredFluid}
              onHoverFluid={onHoverFluid}
              onPickFluid={onPickFluid}
              onCameraRelease={onPlateauCameraRelease}
            />
          </div>
          {/* D1 "vue libre": while the view is dollied to a region, one click (or Escape) eases back to the
              home framing. Orange outline = operator action; ~44px hit zone. Reduced motion: the dolly was
              a cut, so this is an instant return. */}
          {cameraFocus && onCameraRelease && (
            <button type="button" className="at-vuelibre" onClick={onCameraRelease} aria-label={t.scene.freeView}>
              <span className="at-vuelibre-glyph" aria-hidden="true">⤢</span>
              {t.scene.freeViewShort}
            </button>
          )}
          {/* health now reads as the glowing base halo on Le Plateau's rim (Plateau → Turntable), colour =
              signal status — the over-car HUD ring was moved down to surround the car's base. */}
          {/* the always-on cockpit: identity nameplate + engine badge + editable kilométrage — never etches
              out (unlike the on-demand overlays below), the operator's at-a-glance facts on every visit. */}
          <div className="at-scene-cockpit" data-dim={dimScene ? 'true' : undefined}>
            <Cockpit vehicle={bundle.vehicle} edit={edit} />
          </div>
          {/* SANTÉ moved off the top-right HUD into the cockpit slab (Cockpit → HealthStat) — the %
              now captions the rim gauge from the identity band instead of floating alone. */}
          {/* the PROJECTION COLUMN (B2): the ONE right-edge slot for everything Le Noyau projects — so the
              operator learns a single glance direction for "the agent answered" (the left "Entretien" rail
              never moves). A specs focus fans the facts as the constellation (agent-placed → cyan tick + ✕). A parts-bearing
              proposal stacks the manifest below. Empty → nothing renders. (The cyan scan is a 3D holo band
              sweeping THROUGH the car via Plateau `scanning`, not a DOM bar.) */}
          {(showConstellation || showCarnet || showWorklist || showLadder || (showManifest && manifest && onCtaDismiss && (!manifest.canAdd || onCtaPatch))) && (
            <div className="at-projection">
              {showConstellation && (
                <SpecConstellation
                  facts={bundle.vehicle.facts ?? []}
                  keys={focusKeys ?? []}
                  title={focus?.title}
                  reduced={reduced}
                  // fetched surfaces (fluids / carte grise) carry their OWN formatted nodes → read-only, no edit.
                  edit={specsFocused && focus?.nodes ? undefined : edit?.facts}
                  agentPlaced={specsFocused}
                  onDismiss={specsFocused ? onDismissFocus : undefined}
                  nodes={specsFocused ? focus?.nodes : undefined}
                  // The legend's OWN hover only marks the body (never touches legendFocus — that is the
                  // body→legend direction only, § "Picking — the body points back"). Stored facts (no
                  // `nodes`) mark the constellation body the same way the fluids nodes mark their cylinder.
                  onHoverNode={!specsFocused ? undefined : focus?.nodes ? setHoveredFluid : setHoveredFact}
                  focusKey={
                    !specsFocused
                      ? null
                      : focus?.nodes
                        ? legendFocus?.kind === 'fluid'
                          ? legendFocus.key
                          : null
                        : legendFocus?.kind === 'fact'
                          ? legendFocus.key
                          : null
                  }
                />
              )}
              {showWorklist && worklist && (
                <WorklistProjection model={worklist} reduced={reduced} onDismiss={onDismissFocus} />
              )}
              {showCarnet && carnet && (
                <CarnetProjection
                  model={carnet}
                  reduced={reduced}
                  onDismiss={onDismissFocus}
                  onOpenAll={onOpenHistory}
                  onHoverRecord={setHoveredVisit}
                  focusKey={legendFocus?.kind === 'visit' ? legendFocus.key : null}
                />
              )}
              {showLadder && plan && (
                <VisitProjection
                  visits={plan.later}
                  reduced={reduced}
                  onDismiss={onDismissFocus}
                  onAimRegion={aimRegion}
                  heldRegion={cameraFocus?.region ?? null}
                />
              )}
              {showManifest && manifest && onCtaDismiss && (!manifest.canAdd || onCtaPatch) && (
                <PartsManifest model={manifest} reduced={reduced} onAdd={onCtaPatch} onDismiss={onCtaDismiss} />
              )}
            </div>
          )}
          {/* the persistent left rail ("Entretien"): À faire (late, red-tagged) / Planifié / non suivi —
              checkable tasks, plannable predictions. Screen-locked on the hero's left edge.
              Absent `checklist` → no rail. */}
          {checklist && rail && plan && (
            <EntretienRail
              plan={plan}
              onShowLaterVisits={onShowLaterVisits}
              checklist={checklist}
              onPlan={onPlan!}
              dimmed={dimScene || overlayOpen}
              spotlit={tasksFocused ? focusKeys : undefined}
              heldRegion={cameraFocus?.region ?? null}
              onAimRegion={aimRegion}
              reduced={reduced}
            />
          )}
        </>
      )}

      {showShopping && shopping && (
        <ShoppingListOverlay model={shopping} reduced={reduced} onDismiss={onDismissFocus} />
      )}

      {docs && <DocumentationPanel model={docs} handlers={documentation} reduced={reduced} onDismiss={onDismissFocus} />}

      {/* The dock hides while Le Carnet (a true aria-modal popup) owns the screen — relocating an
          interactive console beside a modal is inconsistent; the modal carries its own close. The
          bottom Données drawer is a sheet, so the dock floats above it (--top). */}
      {!historyOpen && (
        <div
          className="at-scene-dock"
          data-driver={noyau.driver ?? undefined}
          data-quiet={noyau.quiet ? 'true' : undefined}
          data-reduced={reduced ? 'true' : undefined}
        >
          {/* 0. the retained notices — the agent's findings, which outlive the narration heartbeat that
              carried them. Above the CTA so a proposal (which needs a decision now) stays nearest the orb. */}
          <NoticeStack notices={noyau.notices} reduced={reduced} onDismiss={onDismissNotice} />
          {/* 1. the rail slot — holds EITHER the chip rail OR (while the agent works) Le Noyau's live
              phrase, in the SAME vertical space, so swapping never shifts the console below. */}
          <div className="at-scene-rail-slot">
            {speaking ? (
              <NoyauVoice state={noyau} reduced={reduced} />
            ) : overlayOpen ? (
              <div className="at-scene-chip-rail" onWheel={railWheel}>
                <div className="at-scene-chip-rail-track">
                  {contextChips.map((qa, i) => (
                    <button key={i} type="button" className="at-scene-chip" onClick={qa.onClick}>
                      {qa.label}
                    </button>
                  ))}
                </div>
              </div>
            ) : (
              quickActions &&
              quickActions.length > 0 && (
                <div className="at-scene-chip-rail" onWheel={railWheel}>
                  <div className="at-scene-chip-rail-track">
                    {quickActions.map((qa, i) => (
                      <button
                        key={i}
                        type="button"
                        className={qa.agent ? 'at-scene-chip at-scene-chip--agent' : 'at-scene-chip'}
                        onClick={qa.onClick}
                        disabled={qa.disabled}
                      >
                        {qa.agent && <span className="at-scene-chip-agent-tick" aria-hidden="true" />}
                        {qa.label}
                        {qa.zone && <ChipZoneMap zone={qa.zone} />}
                      </button>
                    ))}
                  </div>
                </div>
              )
            )}
          </div>

          {/* CTA rises directly above the console/orb, not above the whole dock */}
          {cta && !showManifest && onCtaInstruction && onCtaPatch && onCtaDismiss && (
            <CtaCard cta={cta} onInstruction={onCtaInstruction} onPatch={onCtaPatch} onDismiss={onCtaDismiss} reduced={reduced} />
          )}

          {/* 2. the console capsule — orb socket + input, one object */}
          <div className="at-scene-console" ref={consoleRef}>
            <div className="at-scene-socket">
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
            <Composer
              onComposerFocus={onComposerFocus}
              onSubmit={onSubmit}
              composerExtras={composerExtras}
              onListening={onListening}
              busy={composerBusy}
              armMicCue={armMicCue}
            />
          </div>
        </div>
      )}

      {bundle && (
        <FactsDrawer
          open={factsDrawerOpen}
          onClose={onCloseFacts ?? (() => {})}
          facts={bundle.vehicle.facts ?? []}
          edit={edit?.facts && { onEditValue: edit.facts.onEditValue, onRemove: edit.facts.onRemove }}
          reduced={reduced}
          onHoverFact={setHoveredFact}
          focusKey={legendFocus?.kind === 'fact' ? legendFocus.key : null}
        />
      )}

      <HistoryLog
        open={historyOpen}
        onClose={onCloseHistory ?? (() => {})}
        records={records}
        reduced={reduced}
        onHoverRecord={setHoveredVisit}
        focusKey={legendFocus?.kind === 'visit' ? legendFocus.key : null}
      />
    </>
  );
}
