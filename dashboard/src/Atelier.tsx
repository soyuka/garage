import { useCallback, useEffect, useRef, useState } from 'react';
import './tokens/atelier.css';
import './floor/floor.css';
import './scene/scene.css';
import './scene/parcpage.css';
import './auth.css'; // the error screen reuses the at-auth-* classes — own the dependency explicitly
import { API, getIdentity, logout } from './auth';
import { cancelTurn, dashboardSubscriptions, fetchPilotTopic, postTurn } from './agent';
import {
  addTaskPart,
  archiveTasks,
  createTask,
  createVehicle,
  deleteVehicle,
  deleteVehicleFact,
  getFillVolumes,
  getSivVehicle,
  getVehicle,
  getVehicleBundle,
  getVehicles,
  getVehicleTasks,
  mergeTaskParts,
  patchTaskStatus,
  patchVehicle,
  postVehicleFact,
  removeTaskPart,
  updateTask,
  updateTaskPart,
  type PlannedTaskInput,
  type TaskEdit,
  type TaskPartInput,
  type VehiclePatch,
} from './garage';
import { fluidNodes } from './fluids';
import { carteGriseNodes } from './siv';
import { defaultUsageUnit } from './edit';
import { hashForVehicle, segForIri, useHashPath, vehicleSegFromHash } from './router';
import { isDomainEvent, removeVehicle, upsertVehicle, useMercure } from './useMercure';
import { ParcLot } from './scene/ParcLot';
import { SceneView, type QuickAction } from './scene/SceneView';
import type { TruthPanelEdit } from './floor/TruthPanel';
import { JournalPanel } from './JournalPanel';
import { DossierPanel } from './DossierPanel';
import { maintUnitFor } from './units';
import type { Cta } from './scene/CtaCard';
import { partsCtaFor, pickPartsTask } from './scene/parts';
import { firstNewlyOverdue } from './scene/crossing';
import { regionForFrame, type BodyRegion } from './scene/anchors';
import { PULSE_DURATION } from './scene/holo';
import type { CameraFocus } from './scene/cameraRig';
import type { PulseRegion } from './scene/VehicleMesh';
import { useNoyau } from './noyau/useNoyau';
import { busy } from './noyau/state';
import type { DecisionItem } from './noyau/state';
import { ABANDON_MS, STAGE_INIT, stageReduce, type Driver, type StageState } from './pilot';
import { DWELL, PILOT_QUIET_MS } from './tokens/motion';
import { useRegionBurst } from './scene/useRegionBurst';
import { useReducedMotion } from './scene/useReducedMotion';
import { Credits } from './scene/Attribution';
import { serviceLabel } from './labels';
import { focusForAction, type Focus } from './focus';
import type { Iri, MercureFrame, PlannedPart, PlannedTask, UIAction, VehicleBundle, VehicleSummary } from './types';

// The LIVE Atelier orchestrator (Stage C). Parc-first: the fleet grid at #/, selecting a car drives
// into the hero at #/v/<seg> (reload restores it). It owns the garage reads and Le Noyau's state, and
// renders the SAME pure SceneView the fixture playground does — only the data source differs. Mercure
// (slice 4) and the agent turn (slice 5) feed this orchestrator next; today the composer only listens.

// Pinned fact keys — per-vehicle, like the parc's `mpa.recency` rail (see ParcLot.tsx). Best-effort:
// a private-mode / quota failure just means pins don't survive a reload, never a thrown error.
const pinsKey = (iri: string) => `mpa.pins:${iri}`;
function loadPins(iri: string): string[] {
  try {
    const raw = localStorage.getItem(pinsKey(iri));
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed.filter((k): k is string => typeof k === 'string') : [];
  } catch {
    return [];
  }
}
function savePins(iri: string, keys: string[]) {
  try {
    localStorage.setItem(pinsKey(iri), JSON.stringify(keys));
  } catch {
    // quota / private mode — pins just won't persist across reloads
  }
}

export function Atelier() {
  const path = useHashPath();
  const seg = vehicleSegFromHash(path);
  const [vehicles, setVehicles] = useState<VehicleSummary[]>([]);
  const [bundle, setBundle] = useState<VehicleBundle | null>(null);
  const [loadingParc, setLoadingParc] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const { state, dispatch } = useNoyau();
  const reduced = useReducedMotion();
  // One session per tab — scopes the agent's render-intent topic (slice 5 posts turns onto it).
  const [sessionId] = useState(() => crypto.randomUUID());
  // The owner's PILOT topic: render intents driven from an external MCP host (Claude Code, Goose…),
  // which has no tab and therefore no session. Resolved once from GET /agent/link — until then (and
  // when unauthenticated) the dashboard just runs without that channel.
  const [pilotTopic, setPilotTopic] = useState<string | null>(null);
  // Turn-scoped agent surfaces: a `proposeActions` card + the unified slot focus (which panel is in the
  // centre slot + which keys are spotlit on the car/panel). Driven by `focusView`/`showFact` intents and
  // by car-pin clicks. Cleared on a new turn (its context is captured in pendingContext first) and on nav.
  const [cta, setCta] = useState<Cta | null>(null);
  // The live cta seen inside the memoized Mercure callback (its closure would otherwise capture a stale
  // value) — used to defer a still-pending proposal to the à-décider inbox when a newer one supersedes it.
  const ctaRef = useRef<Cta | null>(cta);
  ctaRef.current = cta;
  const [focus, setFocus] = useState<Focus | null>(null);
  // PULSE verb: the region the agent is currently acting on — a one-shot cyan flare on the car's edges.
  // A monotonic `key` re-triggers the flare even when two consecutive intents target the SAME region; the
  // flare auto-clears back to rest after the envelope plays. Derived best-effort from agent frames.
  const [pulseRegion, setPulseRegion] = useState<PulseRegion | null>(null);
  const pulseKey = useRef(0);
  const pulseTimer = useRef<number | null>(null);
  // D1 earned camera: the region the view is dollied to (null = home framing). Set only by an attributable
  // agent frame (a focusView/showFact that resolves to a region — the Noyau narrates the same turn — or an
  // explicit focusRegion). Cleared on nav, on the "vue libre" reset / Escape, or when a user drag breaks
  // the hold (their input wins). A monotonic key re-triggers a dolly even for a repeat region. Held until
  // released (unlike the one-shot pulse).
  const [cameraFocus, setCameraFocus] = useState<CameraFocus | null>(null);
  const cameraKey = useRef(0);
  const [journalOpen, setJournalOpen] = useState(false); // the agent debug journal drawer
  const [dossierOpen, setDossierOpen] = useState(false); // #11 records + schedule drawer (per-vehicle)
  const [factsDrawerOpen, setFactsDrawerOpen] = useState(false); // #5 "Fiche technique" drawer (per-vehicle)
  const [historyOpen, setHistoryOpen] = useState(false); // #7 "Historique" carnet popup (per-vehicle)
  // Pinned fact keys — persisted per-vehicle (localStorage keyed by IRI, like mpa.recency), so the
  // resting spec constellation still shows something meaningful after the drawer closes.
  const [pinnedKeys, setPinnedKeys] = useState<string[]>([]);
  // #10 checklist: "voir les tâches retirées" pulls a includeDismissed list (the bundle carries open+done).
  const [showDismissed, setShowDismissed] = useState(false);
  const [dismissedTasks, setDismissedTasks] = useState<PlannedTask[]>([]);
  // Pending deterministic-answer timers (the quick-action choreography) — cleared on nav + unmount so a
  // chip's narration can never land on a different vehicle.
  const detTimers = useRef<number[]>([]);
  // Monotonic run token: nav + a superseding chip bump it so an in-flight GET/timer from a prior replay
  // can't land its answer on a newer selection (the timers are also cleared, but a pending fetch is not).
  const detToken = useRef(0);
  // True while a DETERMINISTIC replay (a wayfinder chip) holds Le Noyau in 'working'. A replay settles
  // itself via a 1900ms timer → turn/end; but navigation clears that timer WITHOUT settling, which would
  // strand the orb in 'working' forever (no server turn to cancel, watchdog not armed for chips) and dead-
  // lock the composer + chips. So clearDet settles the orb when it drops a still-working replay. A LIVE
  // agent turn (send) never sets this flag — its lifecycle is owned by the watchdog + server frames.
  const detWorking = useRef(false);
  const clearDet = useCallback(() => {
    detToken.current += 1;
    detTimers.current.forEach((t) => window.clearTimeout(t));
    detTimers.current = [];
    if (detWorking.current) {
      detWorking.current = false;
      dispatch({ type: 'settle' }); // return to veille — a dropped replay never delivers its own turn/end
    }
  }, [dispatch]);
  useEffect(() => clearDet, [clearDet]);

  // The pilot topic — asked for once on mount (it is a keyed digest server-side, not derivable here).
  useEffect(() => {
    let live = true;
    fetchPilotTopic().then((topic) => live && setPilotTopic(topic));
    return () => {
      live = false;
    };
  }, []);

  // The parc collection — loaded once on mount; Mercure (slice 4) keeps it fresh thereafter.
  useEffect(() => {
    let live = true;
    getVehicles()
      .then((vs) => live && setVehicles(vs))
      .catch((e) => live && setError((e as Error).message))
      .finally(() => live && setLoadingParc(false));
    return () => {
      live = false;
    };
  }, []);

  // Resolve the selected seg to a full IRI against the loaded collection, then load its bundle. A
  // direct load of #/v/<seg> waits for the collection to arrive before it can resolve the IRI.
  const selectedIri = seg ? (vehicles.find((v) => segForIri(v['@id']) === seg)?.['@id'] ?? null) : null;
  useEffect(() => {
    setError(null); // a fresh selection recovers from a prior vehicle's load failure
    clearDet(); // drop any in-flight deterministic-answer timers from the prior vehicle
    // A navigation to some OTHER car than the one the gate is waiting for is the operator overriding
    // the pilot: their staged answers will never be relevant, so forget them. Navigating TO the awaited
    // car is the gate working as intended — leave it alone (the `arrived` replay is still coming).
    if (stage.current.awaiting !== null && stage.current.awaiting !== selectedIri) stage.current = stageReduce(stage.current, { type: 'reset' }).state;
    setCta(null); // the prior vehicle's transient agent surfaces don't linger across navigation
    setFocus(null);
    setCameraFocus(null); // a fresh car starts at the home framing
    setShowDismissed(false); // each vehicle's checklist starts collapsed (open+done only)
    setDismissedTasks([]);
    setDossierOpen(false); // the dossier is per-vehicle — close it on navigation
    setFactsDrawerOpen(false); // the facts drawer is per-vehicle too — close it on navigation
    setHistoryOpen(false); // the carnet is per-vehicle too — close it on navigation
    setPinnedKeys(selectedIri ? loadPins(selectedIri) : []);
    if (!selectedIri) {
      setBundle(null);
      return;
    }
    let live = true;
    getVehicleBundle(selectedIri)
      .then((b) => live && setBundle(b))
      .catch((e) => live && setError((e as Error).message));
    return () => {
      live = false;
    };
  }, [selectedIri]);

  // Only render the bundle when it belongs to the CURRENT selection: a car→car nav (browser back/forward
  // between two #/v/ hashes, or a future Mercure/CTA selectVehicle) changes selectedIri while the old
  // bundle is still in state — gating on the @id match shows "Chargement" instead of the previous car.
  const heroBundle = bundle && selectedIri && bundle.vehicle['@id'] === selectedIri ? bundle : null;

  // The live cascade. The publisher fans every garage write to the collection topic with the FULL
  // read-model in `resource`, so we reconcile state directly — no follow-up fetch (identical whether
  // the write came from this tab, another tab, or the agent: the room is shared). Session-topic frames
  // drive Le Noyau (the agent posts onto this topic in slice 5). The forecast-bound deep visuals
  // (rim/hotspot live-refresh) + the §9 crossing are wired in the tail.
  const selectedIriRef = useRef<string | null>(null);
  selectedIriRef.current = selectedIri;
  // Refs for the async cascade: the current bundle (to diff old→new forecast for the §9 crossing) and
  // the current Noyau phase (so a between-turn crossing murmur auto-settles without breaking a live turn).
  const bundleRef = useRef<VehicleBundle | null>(null);
  bundleRef.current = bundle;
  const phaseRef = useRef(state.phase);
  phaseRef.current = state.phase;
  // The console-lock read, seen from inside the memoized callbacks (a closure would capture it stale).
  const busyRef = useRef(false);
  busyRef.current = busy(state);
  const crossTimer = useRef<number | null>(null);

  // Liveness watchdog: the turn runs async on the worker, so an optimistic turn/start could otherwise
  // hang Le Noyau in 'working' forever if the worker dies / a frame is dropped after the 202 (the
  // cooperative cancel can't rescue a dead worker either). Any session frame re-arms it (a live worker
  // never trips it); only true silence settles the orb with a warn.
  const watchdog = useRef<number | null>(null);
  const clearWatchdog = useCallback(() => {
    if (watchdog.current != null) {
      window.clearTimeout(watchdog.current);
      watchdog.current = null;
    }
  }, []);
  const armWatchdog = useCallback(() => {
    clearWatchdog();
    watchdog.current = window.setTimeout(() => {
      // toast BEFORE turn/end so the done filament settles amber (worstTone), not a false green.
      dispatch({ type: 'toast', tone: 'warn', message: "Le tour ne répond plus — réessayez." });
      dispatch({ type: 'turn', phase: 'end', failed: true });
    }, DWELL.watchdog);
  }, [clearWatchdog, dispatch]);
  useEffect(() => clearWatchdog, [clearWatchdog]); // clear on unmount

  // The pilot's counterpart to the watchdog — and deliberately NOT one. A pilot run is human-paced:
  // its host can narrate a step, then read code for two minutes before the next intent. Silence there
  // is not a fault the client can diagnose (it cannot tell "thinking" from "closed the laptop"), so
  // saying "ne répond plus" would be a lie. Instead the console stops claiming the run is working and
  // starts reading how long it has been silent — the operator judges. Only settle_dashboard ends it.
  const quietTimer = useRef<number | null>(null);
  const clearQuiet = useCallback(() => {
    if (quietTimer.current != null) {
      window.clearTimeout(quietTimer.current);
      quietTimer.current = null;
    }
  }, []);
  const armQuiet = useCallback(() => {
    clearQuiet();
    quietTimer.current = window.setTimeout(() => dispatch({ type: 'quiet' }), PILOT_QUIET_MS);
  }, [clearQuiet, dispatch]);
  useEffect(() => clearQuiet, [clearQuiet]);

  /** A frame arrived: whoever sent it now holds the console, under that driver's liveness policy. */
  const openRun = useCallback(
    (driver: Driver) => {
      if (driver === 'pilot') {
        clearWatchdog(); // the pilot took over — the in-app turn's fault timer no longer applies
        armQuiet();
      } else {
        clearQuiet();
        armWatchdog();
      }
    },
    [armQuiet, armWatchdog, clearQuiet, clearWatchdog],
  );
  const closeRun = useCallback(() => {
    clearWatchdog();
    clearQuiet();
  }, [clearQuiet, clearWatchdog]);
  useEffect(
    () => () => {
      if (crossTimer.current != null) window.clearTimeout(crossTimer.current);
      if (pulseTimer.current != null) window.clearTimeout(pulseTimer.current);
    },
    [],
  );

  // Apply an updater to the open bundle ONLY while it still belongs to `iri` — the guard every async
  // reconcile shares, so a GET/write result landing after navigation can't overwrite the now-open vehicle.
  const patchCurrentBundle = useCallback((iri: string, updater: (b: VehicleBundle) => VehicleBundle) => {
    setBundle((prev) => (prev && prev.vehicle['@id'] === iri ? updater(prev) : prev));
  }, []);

  // PULSE + D1 earned camera in one gesture: flare the region's edges cyan (one-shot, monotonic key
  // re-fires a repeat region) and dolly the view to it (held until released). Shared by the region an
  // agent frame resolves to and an explicit focusRegion intent.
  const flareRegion = useCallback((region: BodyRegion) => {
    pulseKey.current += 1;
    setPulseRegion({ region, key: pulseKey.current });
    if (pulseTimer.current != null) window.clearTimeout(pulseTimer.current);
    pulseTimer.current = window.setTimeout(() => setPulseRegion(null), (PULSE_DURATION + 0.3) * 1000);
    cameraKey.current += 1;
    setCameraFocus({ region, key: cameraKey.current });
  }, []);

  // The OPERATOR aiming a rail visit card at its region: the same D1 dolly, WITHOUT the cyan flare. Cyan
  // says "Le Noyau is acting" and it did not — the operator moved their own camera (CHOREOGRAPHY.md
  // § "the operator clicks a visit card's head"). Not coalesced either: one click is already one gesture.
  const holdRegion = useCallback((region: BodyRegion) => {
    cameraKey.current += 1;
    setCameraFocus({ region, key: cameraKey.current });
  }, []);

  // Region targets inside one burst are ONE gesture — the camera dollies once, to where the burst
  // ended (useRegionBurst / pilot.ts). Only the dolly waits for the window; the panels those same
  // frames open land immediately (hard rule 1: motion may overlap an arrival, never gate one).
  const requestFlare = useRegionBurst(flareRegion);

  // ── The render-intent staging gate (pilot.ts) ───────────────────────────────
  // A `select_vehicle` + `show_fact` pair fired 300ms apart used to lose the answer: the surfaces are
  // per-vehicle, so they were placed against the car still on screen (and a proposal fired before the
  // hero resolved was parked in the à-décider inbox instead of rising as a card). The gate holds every
  // surface-placing intent until the car it is about is on screen, then replays it in order.
  const stage = useRef<StageState>(STAGE_INIT);
  const expireTimer = useRef<number | null>(null);

  // The two on-demand surfaces are mutually exclusive — opening one closes the other.
  const openFacts = useCallback(() => { setHistoryOpen(false); setFactsDrawerOpen(true); }, []);
  const openHistory = useCallback(() => { setFactsDrawerOpen(false); setHistoryOpen(true); }, []);

  /**
   * Apply ONE render intent to the workshop — the choreography map made code (CHOREOGRAPHY.md). Same
   * function whichever driver produced it: the two share a vocabulary, so they share this code path.
   * Called either straight off the wire or replayed by the staging gate once the car has arrived.
   */
  const applyRender = useCallback(
    (frame: UIAction) => {
      // PULSE + D1 earned camera: if this frame targets a car region (the agent focusing/acting on a
      // service type), flare that region's edges cyan once and dolly the view to it. Best-effort — a
      // frame that maps to no region (a spec question, a toast) returns null and moves nothing. The
      // request is coalesced over the burst window, so a burst dollies once, to its conclusion.
      const pulsed = regionForFrame(frame);
      if (pulsed) requestFlare(pulsed);
      if (frame.type === 'selectVehicle') {
        window.location.hash = hashForVehicle(frame.id); // drive-forward into the named car
      } else if (frame.type === 'focusView' || frame.type === 'showFact' || frame.type === 'showHistory') {
        // All raise the single slot. showFact is the specs-only subset; showHistory raises the carnet
        // projection — the SAME surfaces the "Carnet"/"Infos moteur" wayfinder chips use (see focusForAction).
        setFocus(focusForAction(frame));
      } else if (frame.type === 'showParts') {
        // The agent's parts manifest → the SAME deterministic surface the "Pièces dispo" chip projects.
        // Absent `parts` → itemise the target task's own planned parts (option a); the "Ajouter" merge is
        // idempotent (server dedups by ref). No parts anywhere → nothing to project.
        const parts = frame.parts ?? (bundleRef.current?.vehicle.plannedTasks ?? []).find((t) => t.id === frame.taskId)?.parts ?? [];
        if (parts.length > 0) setCta(partsCtaFor(frame.service, frame.taskId, parts));
      } else if (frame.type === 'focusRegion') {
        // D1 explicit camera intent (contract seed — the server may not emit it yet): dolly to the region
        // + flare the pulse so the move is announced, matching the focusView-derived path above.
        requestFlare(frame.region);
      } else if (frame.type === 'proposeActions') {
        const next: Cta = { headline: frame.headline, finding: frame.finding, actions: frame.actions };
        const defer = (c: Cta) => dispatch({ type: 'defer', id: `review:${c.headline}`, text: c.headline, finding: c.finding, actions: c.actions });
        // a still-pending prior proposal is SUPERSEDED by this one → park it in the à-décider inbox.
        if (ctaRef.current) defer(ctaRef.current);
        // no hero open (the operator is on the parc — no cradle to raise the card) → straight to the inbox.
        if (!selectedIriRef.current) defer(next);
        else setCta(next);
      } else if (frame.type === 'setVehicles') {
        setVehicles(frame.vehicles);
        if (frame.preferredId) window.location.hash = hashForVehicle(frame.preferredId);
      }
    },
    [dispatch, requestFlare],
  );

  /** Run the gate over an event and apply whatever it releases; narrate anything it had to drop. */
  const runStage = useCallback(
    (ev: Parameters<typeof stageReduce>[1]) => {
      const out = stageReduce(stage.current, ev);
      stage.current = out.state;
      out.emit.forEach(applyRender);
      if (out.dropped) {
        // Never a silent failure: an answer about a car that never arrived is dropped, not shown over
        // whichever car happens to be on screen — and the operator is told it happened.
        dispatch({ type: 'toast', tone: 'warn', message: `Réponse abandonnée : le véhicule n’est pas arrivé (${out.dropped}).` });
      }
      if (expireTimer.current != null) window.clearTimeout(expireTimer.current);
      if (stage.current.awaiting !== null) expireTimer.current = window.setTimeout(() => runStage({ type: 'expire', at: performance.now() }), ABANDON_MS);
    },
    [applyRender, dispatch],
  );

  const onFrame = useCallback(
    (frame: MercureFrame, driver: Driver) => {
      if (isDomainEvent(frame)) {
        if (frame.event === 'deleted') {
          setVehicles((prev) => removeVehicle(prev, frame.iri));
          if (frame.iri === selectedIriRef.current) {
            setBundle(null);
            window.location.hash = '#/'; // the open car is gone — fall back to the parc
          }
          return;
        }
        setVehicles((prev) => upsertVehicle(prev, frame.resource));
        if (frame.resource['@id'] === selectedIriRef.current) {
          // INSTANT cascade: merge the fresh scalars/health/facts (health bars + cardinals animate now).
          setBundle((prev) => (prev ? { ...prev, vehicle: { ...prev.vehicle, ...frame.resource } } : prev));
          // DEEP refresh (open vehicle only — the parc stays fetch-free): the frame omits forecast/
          // records/plannedTasks, so re-read them so the rim/hotspots/établi/échéances reflect the write.
          const iri = frame.resource['@id'];
          const prevDue = bundleRef.current?.forecast.due ?? [];
          getVehicleBundle(iri)
            .then((b) => {
              if (selectedIriRef.current !== iri) return; // navigated away — drop the stale refetch
              // §9 hands-off wow: a service that just crossed into overdue docks an ember + murmurs on
              // Le Noyau (NOT an agent turn). The red bodywork (rim/hotspot/panel) rides the new bundle.
              const crossed = firstNewlyOverdue(prevDue, b.forecast.due);
              setBundle(b);
              if (crossed) {
                // carry the raw type + vehicle so the à-décider notify row can offer "planifier ○"
                dispatch({ type: 'concern', message: `${serviceLabel(crossed)} en retard`, serviceType: crossed, vehicleIri: iri });
                if (crossTimer.current != null) window.clearTimeout(crossTimer.current);
                crossTimer.current = window.setTimeout(() => {
                  if (phaseRef.current !== 'working') dispatch({ type: 'settle' }); // don't cut a live turn
                }, DWELL.concern);
              }
            })
            .catch(() => undefined);
        }
        return;
      }
      // A session/pilot frame (a UIAction). `driver` is the stream it arrived on (agent.ts): the two
      // drivers speak the SAME vocabulary and drive the same choreography, but their LIFECYCLES differ
      // — an in-app turn is bracketed and bounded (a silent worker is a fault), a pilot run declares no
      // start and may go quiet for minutes (silence is a resting state). `openRun` picks the policy.
      //
      // The heartbeat (turn/status/toast) is never staged: it is what tells the operator why the stage
      // is moving, so it lands the instant it arrives even while a car is still driving in. Everything
      // that PLACES a per-vehicle surface goes through the gate. Hidden chat — never a transcript.
      if (frame.type === 'turn') {
        dispatch({ type: 'turn', phase: frame.phase, driver });
        if (frame.phase === 'end') closeRun();
        else openRun(driver);
      } else if (frame.type === 'status') {
        dispatch({ type: 'status', message: frame.message, driver });
        openRun(driver);
      } else if (frame.type === 'toast') {
        dispatch({ type: 'toast', tone: frame.tone, message: frame.message, driver });
        openRun(driver);
      } else {
        runStage({ type: 'frame', action: frame, shownIri: selectedIriRef.current, at: performance.now() });
      }
    },
    [closeRun, dispatch, openRun, runStage],
  );
  useMercure(dashboardSubscriptions(API, sessionId, pilotTopic), onFrame);

  // The car the gate was waiting for is on screen → replay what it held, in order. Guarded on the
  // awaited IRI so the effect is inert on every other bundle change (the live cascade re-runs it).
  const heroIri = heroBundle?.vehicle['@id'] ?? null;
  useEffect(() => {
    if (heroIri && stage.current.awaiting === heroIri) runStage({ type: 'arrived', iri: heroIri, at: performance.now() });
  }, [heroIri, runStage]);
  useEffect(
    () => () => {
      if (expireTimer.current != null) window.clearTimeout(expireTimer.current);
    },
    [],
  );

  // Post an agent turn. The worker is async (POST acks 202), so the Noyau goes to work optimistically;
  // the real turn/start frame re-confirms and turn/end settles it. A transport failure (never queued)
  // clears the optimistic working state and surfaces an alarm. `summary` is a one-line on-screen state
  // (NOT a transcript); pendingContext (the CTA being replied to) is wired with the CTA layer in slice 6.
  const send = (instruction: string) => {
    if (busy(state)) return; // one driver at a time — the ✕ cancels first (a QUIET pilot run does not hold it)
    // Carry the CTA being replied to as one bounded line (NOT a transcript), so a terse reply lands in
    // context against the question the agent just asked. Capture BEFORE clearing the surfaces.
    const pendingContext = cta
      ? [cta.headline, cta.finding, cta.actions.length ? `Options : ${cta.actions.map((a) => a.label).join(', ')}` : '']
          .filter(Boolean)
          .join(' — ')
      : undefined;
    setCta(null);
    setFocus(null);
    dispatch({ type: 'turn', phase: 'start' });
    armWatchdog();
    const v = heroBundle?.vehicle;
    const summary = v
      ? `${v.make} ${v.model}${v.plate ? ` · ${v.plate}` : ''}${v.health ? ` · ${v.health.status}` : ''}`
      : undefined;
    postTurn(instruction, { sessionId, selectedId: selectedIri ?? undefined, summary, pendingContext }).catch((e) => {
      clearWatchdog();
      // toast BEFORE turn/end so the done filament settles red (worstTone), not a false green.
      dispatch({ type: 'toast', tone: 'alarm', message: `Échec de la requête : ${(e as Error).message}` });
      dispatch({ type: 'turn', phase: 'end', failed: true });
    });
  };

  // ── Deterministic wayfinders (CHOREOGRAPHY "Deterministic-first"). A quick-action chip resolves
  // client-side or via a plain GET — NO postTurn, NO tokens — and replays the Noyau choreography the agent
  // would (turn:start → the two status steps → answer + turn:end), then applies the same focus/cta. It runs
  // at DATA speed: the steps narrate immediately and the answer lands the moment `resolve` settles (instant
  // for a local read). A rejection narrates honestly (the thrown French line) and lands NO surface.
  const runLocalAnswer = useCallback(
    // `effect` runs at the answer beat (never `then` — a thenable return would recurse the promise chain):
    // the seam for answers that OPEN a surface (Données / Le Carnet) instead of projecting a focus/cta.
    (opts: { steps: [string, string]; failMessage: string; resolve: () => Promise<{ focus?: Focus | null; cta?: Cta | null; effect?: () => void }> }) => {
      if (busyRef.current) return; // one driver at a time — composer + chips share the rule
      clearDet(); // supersede any in-flight replay (bumps detToken → drops a pending fetch's late apply)
      const token = detToken.current;
      const iri = selectedIriRef.current;
      const live = () => detToken.current === token && selectedIriRef.current === iri;
      setCta(null);
      setFocus(null);
      detWorking.current = true; // this replay now owns the orb; clearDet settles it if nav supersedes us
      dispatch({ type: 'turn', phase: 'start' });
      const outcome = opts.resolve();

      const apply = (o: { focus?: Focus | null; cta?: Cta | null; effect?: () => void } | null) => {
        if (!live()) return; // superseded by nav/newer replay — clearDet already settled the orb
        detWorking.current = false; // we deliver our own turn/end below, so clearDet must not re-settle
        if (o?.focus !== undefined) setFocus(o.focus ?? null);
        if (o?.cta !== undefined) setCta(o.cta ?? null);
        o?.effect?.();
        dispatch({ type: 'turn', phase: 'end', failed: !o });
      };
      const settle = () =>
        outcome.then(
          (o) => apply(o),
          (e) => {
            if (!live()) return;
            const msg = e instanceof Error && e.message ? e.message : opts.failMessage;
            dispatch({ type: 'toast', tone: 'warn', message: msg }); // honest, transient narration
            detTimers.current.push(window.setTimeout(() => apply(null), DWELL.warn)); // let the warn paint, then settle — no surface
          },
        );

      // No paced beats: a deterministic answer has its data already (or one GET away), so it lands as
      // fast as it resolves — the panel's own CSS transition is the only motion. The narration steps
      // still fire (the heartbeat says what happened) but they no longer GATE the answer: padding a
      // local read out to ~1.9s was pure theatre and read as the app "talking" instead of opening.
      dispatch({ type: 'status', message: opts.steps[0] });
      dispatch({ type: 'status', message: opts.steps[1] });
      void settle();
    },
    [clearDet, dispatch],
  );

  // The deterministic wayfinder chips (above the composer). Each replays the choreography via
  // runLocalAnswer; a chip is hidden when its data prerequisite is absent (never a dead control).
  // Two families: the OWNED-data surfaces open as full editable popups (Données = the car's database of
  // facts — read/edit/delete everything; Historique = every visit), the FETCHED external reads project
  // into the agent's right column. Built only when a hero bundle is open (see the hero route).
  const buildQuickActions = (b: VehicleBundle): QuickAction[] => {
    const locked = busy(state);
    const chips: QuickAction[] = [];
    chips.push({
      label: 'Données',
      disabled: locked,
      onClick: () =>
        runLocalAnswer({
          steps: ['ouverture du dossier…', 'lecture des données véhicule…'],
          failMessage: 'données indisponibles',
          resolve: async () => ({ effect: openFacts }),
        }),
    });
    if (b.records.length > 0) {
      chips.push({
        label: 'Historique',
        disabled: locked,
        zone: 'centre',
        onClick: () =>
          runLocalAnswer({
            steps: ['ouverture du carnet…', 'relecture des visites…'],
            failMessage: 'carnet indisponible',
            resolve: async () => ({ effect: openHistory }),
          }),
      });
    }
    if (b.vehicle.kType != null) {
      const kType = b.vehicle.kType;
      chips.push({
        label: 'Capacités fluides',
        disabled: locked,
        zone: 'colonne',
        onClick: () =>
          runLocalAnswer({
            steps: ['consultation des capacités…', 'lecture des specs fluides…'],
            failMessage: 'le catalogue ne répond pas',
            resolve: async () => {
              let vols;
              try {
                vols = await getFillVolumes(kType);
              } catch {
                throw new Error('le catalogue ne répond pas');
              }
              const nodes = fluidNodes(vols);
              if (nodes.length === 0) throw new Error('aucune capacité fluide connue pour ce véhicule');
              return { focus: { panel: 'specs', keys: [], title: 'Capacités fluides', nodes } };
            },
          }),
      });
    }
    const partsTask = pickPartsTask(b);
    if (partsTask) {
      chips.push({
        label: `Pièces dispo · ${serviceLabel(partsTask.type)}`,
        disabled: locked,
        onClick: () =>
          runLocalAnswer({
            steps: ['ouverture de la tâche…', 'liste des pièces…'],
            failMessage: 'pièces indisponibles',
            resolve: async () => ({ cta: partsCtaFor(serviceLabel(partsTask.type), partsTask.id, partsTask.parts) }),
          }),
      });
    }
    if (b.vehicle.plate) {
      const plate = b.vehicle.plate;
      chips.push({
        label: 'Fiche technique',
        disabled: locked,
        zone: 'colonne',
        onClick: () =>
          runLocalAnswer({
            steps: ['lecture de la carte grise…', 'préparation de la fiche technique…'],
            failMessage: "l'immatriculation ne répond pas",
            resolve: async () => {
              let siv;
              try {
                siv = await getSivVehicle(plate);
              } catch {
                throw new Error("l'immatriculation ne répond pas");
              }
              const nodes = carteGriseNodes(siv);
              if (nodes.length === 0) throw new Error('aucune donnée SIV pour cette plaque');
              return { focus: { panel: 'specs', keys: [], title: 'Fiche technique', nodes } };
            },
          }),
      });
    }
    if (b.records.length > 0) {
      chips.push({
        label: 'Carnet · dépenses',
        disabled: locked,
        zone: 'colonne',
        onClick: () =>
          runLocalAnswer({
            steps: ['lecture du carnet…', 'calcul des dépenses…'],
            failMessage: 'carnet indisponible',
            resolve: async () => ({ focus: { panel: 'carnet', keys: [] } }),
          }),
      });
    }
    return chips;
  };

  // CTA action paths. `instruction` re-runs the agent (a fresh turn, supersedes the card). `patchTask`
  // is a DETERMINISTIC parts merge — no agent turn — then re-reads the open bundle so the établi/
  // échéances reflect the merge (the domain frame omits plannedTasks; see PROVENANCE).
  const onCameraRelease = useCallback(() => setCameraFocus(null), []); // stable → keeps the memo'd Plateau idle on typing renders

  const onCtaInstruction = (instruction: string) => send(instruction);
  const onCtaPatch = (patch: { id: Iri; parts: PlannedPart[] }) => {
    setCta(null);
    const iri = selectedIri;
    if (!iri) return;
    mergeTaskParts(patch.id, patch.parts)
      .then(() => getVehicleBundle(iri))
      // Guard POST-await so a re-read landing after navigation can't overwrite the now-open vehicle.
      .then((b) => patchCurrentBundle(iri, () => b))
      .catch((e) => dispatch({ type: 'toast', tone: 'alarm', message: `Échec de l’ajout : ${(e as Error).message}` }));
  };

  // ── C2 à-décider inbox handlers. `resolve` drops a decided item from the queue (a "vu" dismiss or the
  // tail of any action). `schedule` promotes a notify item (a due service) to a planned task on ITS OWN
  // vehicle — createTask targets the item's vehicleIri directly, so it is correct even if the operator has
  // since navigated to another car (the concern persists across nav). Only refresh the open bundle if it
  // matches. Review actions reuse the CTA paths (onCtaInstruction / onCtaPatch) unchanged.
  const onResolveDecision = (id: string) => dispatch({ type: 'resolve', id });
  const onScheduleDecision = (item: DecisionItem) => {
    if (!item.serviceType || !item.vehicleIri) return;
    const vIri = item.vehicleIri;
    createTask(vIri, { type: item.serviceType })
      .then(() => {
        if (selectedIriRef.current === vIri) afterTaskWrite(vIri);
      })
      .catch((e) => alarm(`Échec de la planification : ${(e as Error).message}`));
  };

  // ── #9 Vehicle CRUD + facts (the manual surfaces; the agent does the same via NL). Every write also
  // fans a Mercure domain frame (other tabs cascade too); here we update optimistically + re-read the
  // bundle for the subresources/forecast a frame omits. Errors surface as an alarm on Le Noyau, never
  // a silent failure. selectedIriRef guards an async result that lands after navigation.
  const alarm = (message: string) => dispatch({ type: 'toast', tone: 'alarm', message });

  const onAddVehicle = () => {
    createVehicle()
      .then((v) => {
        setVehicles((prev) => upsertVehicle(prev, v)); // Mercure 'created' also fans it; optimistic so the nav resolves now
        window.location.hash = hashForVehicle(v['@id']); // drive into the blank car to fill it in
      })
      .catch((e) => alarm(`Échec de la création : ${(e as Error).message}`));
  };

  const onPatchVehicle = (patch: VehiclePatch) => {
    const iri = selectedIri;
    if (!iri) return;
    patchCurrentBundle(iri, (prev) => ({ ...prev, vehicle: { ...prev.vehicle, ...patch } })); // optimistic
    patchVehicle(iri, patch)
      .then(() => getVehicleBundle(iri)) // re-read: a usage change shifts the forecast
      // Guard POST-await (the updater pattern, like the facts re-reads): a bundle that resolves after a
      // nav must NOT overwrite the now-open vehicle (defeats the heroBundle @id gate → stuck loading).
      .then((b) => patchCurrentBundle(iri, () => b))
      .catch((e) => {
        alarm(`Échec de la mise à jour : ${(e as Error).message}`);
        getVehicleBundle(iri)
          .then((b) => patchCurrentBundle(iri, () => b)) // reconcile the optimistic merge
          .catch(() => undefined);
      });
  };

  const onDeleteVehicle = () => {
    const iri = selectedIri;
    if (!iri) return;
    deleteVehicle(iri)
      .then(() => {
        setVehicles((prev) => removeVehicle(prev, iri));
        setBundle(null);
        window.location.hash = '#/'; // the car is gone — back to the parc
      })
      .catch((e) => alarm(`Échec de la suppression : ${(e as Error).message}`));
  };

  const onAddFact = (fact: { key: string; value: string; unit?: string | null }) => {
    const iri = selectedIri;
    if (!iri) return;
    postVehicleFact(iri, fact)
      .then((fresh) => patchCurrentBundle(iri, (prev) => ({ ...prev, vehicle: { ...prev.vehicle, facts: fresh.facts, factCount: fresh.factCount } })))
      .catch((e) => alarm(`Échec de l’ajout : ${(e as Error).message}`));
  };

  const onRemoveFact = (key: string) => {
    const iri = selectedIri;
    if (!iri) return;
    patchCurrentBundle(iri, (prev) => ({ ...prev, vehicle: { ...prev.vehicle, facts: (prev.vehicle.facts ?? []).filter((f) => f.key !== key) } })); // optimistic
    deleteVehicleFact(iri, key)
      // Re-read: a deleted user fact may unmask a projected (part-derived/catalog) value for the same key.
      .then(() =>
        selectedIriRef.current === iri
          ? getVehicle(iri).then((v) => patchCurrentBundle(iri, (prev) => ({ ...prev, vehicle: { ...prev.vehicle, facts: v.facts, factCount: v.factCount } })))
          : undefined,
      )
      .catch((e) => {
        alarm(`Échec de la suppression : ${(e as Error).message}`);
        if (selectedIriRef.current === iri)
          getVehicle(iri).then((v) => patchCurrentBundle(iri, (prev) => ({ ...prev, vehicle: { ...prev.vehicle, facts: v.facts } }))).catch(() => undefined);
      });
  };

  // Editing a fact value re-records the key as a source "user" fact (postVehicleFact upserts by key).
  const onEditFact = (key: string, value: string, unit?: string | null) => onAddFact({ key, value, unit });

  const edit: TruthPanelEdit = {
    onPatch: onPatchVehicle,
    onDelete: onDeleteVehicle,
    facts: { onAdd: onAddFact, onRemove: onRemoveFact, onEditValue: onEditFact },
  };

  // #5 facts drawer + pinning — client-side only (no garage write): pins persist per-vehicle.
  const onTogglePin = (key: string) => {
    const iri = selectedIri;
    if (!iri) return;
    setPinnedKeys((prev) => {
      const next = prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key];
      savePins(iri, next);
      return next;
    });
  };

  // ── #10 Task checklist. Task writes hit the /planned-tasks subresource; the domain frame OMITS
  // plannedTasks (see PROVENANCE), so each write re-reads the bundle explicitly (guarded post-await),
  // plus the includeDismissed list when the "voir les retirées" view is open.
  const showDismissedRef = useRef(showDismissed);
  showDismissedRef.current = showDismissed;

  const afterTaskWrite = (iri: string) => {
    getVehicleBundle(iri)
      .then((b) => patchCurrentBundle(iri, () => b))
      .catch(() => undefined);
    if (showDismissedRef.current)
      getVehicleTasks(iri, true)
        .then((ts) => selectedIriRef.current === iri && setDismissedTasks(ts))
        .catch(() => undefined);
  };

  // Run a task write against the open vehicle, then refresh; guards a null selection BEFORE the op fires.
  const taskWrite = (op: (vehicleIri: string) => Promise<unknown>, failMsg: string) => {
    const iri = selectedIri;
    if (!iri) return;
    op(iri)
      .then(() => afterTaskWrite(iri))
      .catch((e) => alarm(`${failMsg} : ${(e as Error).message}`));
  };

  const onToggleDismissed = () => {
    const iri = selectedIri;
    const next = !showDismissed;
    setShowDismissed(next);
    if (next && iri)
      getVehicleTasks(iri, true)
        .then((ts) => selectedIriRef.current === iri && setDismissedTasks(ts))
        .catch((e) => alarm(`Échec du chargement : ${(e as Error).message}`));
  };

  const checklist = {
    showDismissed,
    onToggleDismissed,
    onSetStatus: (taskIri: string, status: 'open' | 'done' | 'dismissed') => taskWrite(() => patchTaskStatus(taskIri, status), 'Échec de la mise à jour'),
    onEditTask: (taskIri: string, fields: TaskEdit) => taskWrite(() => updateTask(taskIri, fields), 'Échec de la mise à jour'),
    onAddTask: (input: PlannedTaskInput) => taskWrite((iri) => createTask(iri, input), 'Échec de l’ajout'),
    onAddPart: (taskIri: string, part: TaskPartInput) => taskWrite(() => addTaskPart(taskIri, part), 'Échec de l’ajout'),
    onEditPart: (taskIri: string, partId: string, fields: TaskPartInput) => taskWrite(() => updateTaskPart(taskIri, partId, fields), 'Échec de la mise à jour'),
    onRemovePart: (taskIri: string, partId: string) => taskWrite(() => removeTaskPart(taskIri, partId), 'Échec de la suppression'),
    onArchiveDone: () => taskWrite((iri) => archiveTasks(iri), 'Échec de l’archivage'),
  };

  if (error) {
    return (
      <div className="at-auth-screen">
        <div className="at-auth-card">
          <h1>Erreur de chargement</h1>
          <p>{error}</p>
          <div style={{ display: 'flex', gap: 'var(--at-space-3)' }}>
            <button type="button" className="at-auth-button" onClick={() => location.reload()}>
              Réessayer
            </button>
            <button
              type="button"
              className="at-auth-button"
              onClick={() => {
                setError(null);
                window.location.hash = '#/';
              }}
            >
              ← Le Parc
            </button>
          </div>
        </div>
      </div>
    );
  }

  // ── Hero route (#/v/<seg>) ──────────────────────────────────────────────────
  if (seg) {
    const vehicleMissing = !selectedIri && !loadingParc && vehicles.length > 0;
    return (
      <div className="at-scene">
        <div className="at-scene-top">
          <a href="#/" className="at-scene-back">← Le Parc</a>
          <Identity onJournal={() => setJournalOpen(true)} onDossier={heroBundle ? () => setDossierOpen(true) : undefined} />
          <span style={{ flex: 1 }} />
        </div>

        <JournalPanel open={journalOpen} onClose={() => setJournalOpen(false)} />
        {heroBundle && (
          <DossierPanel
            open={dossierOpen}
            onClose={() => setDossierOpen(false)}
            vehicleIri={heroBundle.vehicle['@id']}
            records={heroBundle.records}
            defaultUnit={maintUnitFor(heroBundle.vehicle.usageUnit)}
            onChanged={() => {
              const iri = selectedIriRef.current;
              if (iri) getVehicleBundle(iri).then((b) => patchCurrentBundle(iri, () => b)).catch(() => undefined);
            }}
            onError={alarm}
          />
        )}

        {vehicleMissing ? (
          <div className="at-scene-main">
            <div className="at-scene-empty">Véhicule introuvable — <a href="#/" className="at-scene-back">retour au parc</a></div>
          </div>
        ) : !heroBundle ? (
          <div className="at-scene-main">
            <div className="at-scene-empty">Chargement du véhicule…</div>
          </div>
        ) : (
          <SceneView
            bundle={heroBundle}
            rung="b"
            reduced={reduced}
            noyau={state}
            pulseRegion={pulseRegion}
            cameraFocus={cameraFocus}
            onCameraRelease={onCameraRelease}
            onHoldRegion={holdRegion}
            onClearEmber={() => dispatch({ type: 'clearEmber' })}
            onResolveDecision={onResolveDecision}
            onScheduleDecision={onScheduleDecision}
            onComposerFocus={(active) => dispatch({ type: 'typing', active })}
            onSubmit={send}
            cta={cta}
            onCtaInstruction={onCtaInstruction}
            onCtaPatch={onCtaPatch}
            onCtaDismiss={() => setCta(null)}
            focus={focus}
            onDismissFocus={() => setFocus(null)}
            onShowLaterVisits={() => setFocus({ panel: 'visits', keys: [] })}
            quickActions={buildQuickActions(heroBundle)}
            pinnedKeys={pinnedKeys}
            onTogglePin={onTogglePin}
            factsDrawerOpen={factsDrawerOpen}
            onOpenFacts={openFacts}
            onCloseFacts={() => setFactsDrawerOpen(false)}
            records={heroBundle.records}
            historyOpen={historyOpen}
            onOpenHistory={openHistory}
            onCloseHistory={() => setHistoryOpen(false)}
            edit={edit}
            checklist={{
              ...checklist,
              tasks: showDismissed ? dismissedTasks : heroBundle.vehicle.plannedTasks ?? [],
              defaultUnit: heroBundle.vehicle.usageUnit ?? defaultUsageUnit(heroBundle.vehicle.vehicleType),
            }}
            composerExtras={
              busy(state) && state.driver !== 'pilot' ? (
                <button
                  type="button"
                  className="at-scene-sim"
                  title="Interrompre le tour"
                  aria-label="Interrompre le tour"
                  onClick={() => void cancelTurn(sessionId).catch(() => undefined)}
                >
                  ✕
                </button>
              ) : undefined
            }
          />
        )}
      </div>
    );
  }

  // ── Parc landing (#/) ───────────────────────────────────────────────────────
  return (
    <div className="at-parc-page">
      <div className="at-parc-page-top">
        <h1 className="at-parc-page-title" style={{ margin: 0 }}>Le Parc</h1>
        <span style={{ flex: 1 }} />
        <Identity onJournal={() => setJournalOpen(true)} />
      </div>
      <JournalPanel open={journalOpen} onClose={() => setJournalOpen(false)} />
      {loadingParc ? (
        <p className="at-parc-empty">Chargement du parc…</p>
      ) : (
        <ParcLot vehicles={vehicles} reduced={reduced} onSelect={(iri) => (window.location.hash = hashForVehicle(iri))} onAddVehicle={onAddVehicle} />
      )}
    </div>
  );
}

/** The signed-in identity + the dossier (hero only) + the journal + sign-out (top-right chrome). */
function Identity({ onJournal, onDossier }: { onJournal: () => void; onDossier?: () => void }) {
  const id = getIdentity();
  const chip: React.CSSProperties = {
    appearance: 'none',
    background: 'transparent',
    border: '1px solid var(--at-hairline)',
    color: 'var(--at-text-dim)',
    borderRadius: 5,
    padding: '2px 8px',
    cursor: 'pointer',
    font: 'inherit',
  };
  return (
    <span className="at-scene-back" style={{ display: 'inline-flex', gap: 10, alignItems: 'center' }}>
      <span>{id.name ?? id.email ?? 'connecté'}</span>
      {onDossier && (
        <button type="button" onClick={onDossier} className="at-identity-chip" title="Dossier du véhicule" aria-label="Ouvrir le dossier du véhicule">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path
              d="M2 4.5c0-.55.45-1 1-1h3.2l1.1 1.4h5.7c.55 0 1 .45 1 1v6.6c0 .55-.45 1-1 1H3c-.55 0-1-.45-1-1V4.5z"
              stroke="currentColor"
              strokeWidth="1.3"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
          dossier
        </button>
      )}
      <button type="button" onClick={onJournal} className="at-identity-chip" title="Journal de l'agent" aria-label="Ouvrir le journal de l'agent">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <path
            d="M3 2.8c0-.44.36-.8.8-.8h7.9c.44 0 .8.36.8.8v10.9l-1.9-1.1-1.9 1.1-1.9-1.1-1.9 1.1-1.9-1.1V2.8z"
            stroke="currentColor"
            strokeWidth="1.3"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
          <path d="M5.3 5.4h5.4M5.3 7.8h5.4M5.3 10.2h3.2" stroke="currentColor" strokeWidth="1.1" strokeLinecap="round" />
        </svg>
        journal
      </button>
      <Credits />
      <button type="button" onClick={() => logout()} style={chip}>
        Déconnexion
      </button>
    </span>
  );
}
