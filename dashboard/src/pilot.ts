import { NBSP } from './format';
import { BURST_MS, PILOT_QUIET_MS } from './tokens/motion';
import type { BodyRegion } from './scene/anchors';
import type { Iri, UIAction } from './types';

// ─────────────────────────────────────────────────────────────────────────────
// The pilot session — pure core.
//
// A pilot run (docs/pilot.md) is human-paced: an MCP host narrates a step, goes quiet for two minutes
// while it reads code, then fires four intents in 300ms. Nothing declares the start and nothing but
// `settle_dashboard` declares the end. That burstiness breaks two assumptions the in-app turn could
// make, and this module is the testable answer to both — plus the silence read the console needs to
// stay honest about which of the two a still screen is showing.
//
//  1. THE STAGING GATE. `selectVehicle` navigates, and navigation clears the transient agent surfaces
//     (Atelier's selection effect) — so a `select_vehicle` + `show_fact` pair fired 300ms apart used
//     to lose the fact: the focus was set against the old car and then wiped when the new bundle
//     landed. The gate holds every render intent that arrives while a car is driving in and replays
//     it the moment that car is on screen. The answer lands on the car it is about, always.
//
//  2. THE BURST COALESCER. Four intents in 300ms used to mean four earned-camera dollies, each
//     re-easing from wherever the last one had got to: a camera whipping between regions and never
//     arriving, encoding nothing. Targets inside one burst window collapse to the LAST one — the
//     operator is shown where the pilot's attention landed, not the route it took there.
//
// Both apply to the in-app agent too: it has the same race, only with tool-call latency hiding it
// more often. One grammar, one code path.
// ─────────────────────────────────────────────────────────────────────────────

/** Who is at the controls. `null` = nobody (at rest). */
export type Driver = 'app' | 'pilot';

// ── 1. The staging gate ───────────────────────────────────────────────────────

export interface StageState {
  /** the vehicle a `selectVehicle` is driving in; every other intent waits for it */
  awaiting: Iri | null;
  /** the intents that arrived while it drives, in arrival order */
  held: UIAction[];
  /** clock stamp the drive started at — the abandon deadline reads off it */
  since: number | null;
}

export const STAGE_INIT: StageState = { awaiting: null, held: [], since: null };

export type StageEvent =
  /** a render intent off the wire; `shownIri` is the car currently on screen */
  | { type: 'frame'; action: UIAction; shownIri: Iri | null; at: number }
  /** that car's bundle is now on screen */
  | { type: 'arrived'; iri: Iri; at: number }
  /** the clock moved — drop a stage whose car never arrived */
  | { type: 'expire'; at: number }
  /** the operator navigated / the driver changed: forget everything staged */
  | { type: 'reset' };

export interface StageOut {
  state: StageState;
  /** the intents to apply NOW, in order */
  emit: UIAction[];
  /** intents thrown away because the car they were about will never be on screen (never silent —
   *  the caller narrates it) */
  dropped?: number;
}

/**
 * How long a staged burst waits for its car. Past it the intents are dropped, not replayed against
 * whatever happens to be on screen: an answer about the Kangoo must never be shown over the Clio.
 * Generous next to a bundle read (a GET plus its forecast) and short enough that a bad `@id` from
 * the pilot surfaces as a warning inside one breath rather than silently.
 */
export const ABANDON_MS = 6000;

/**
 * Does this intent PLACE a per-vehicle surface — the kind navigation wipes (Atelier's selection
 * effect clears the CTA, the focus and the camera hold)? Only those need staging. The heartbeat
 * frames (`status`, `toast`, `turn`) must never wait: the pilot narrating while the car drives in is
 * the one thing telling the operator why the stage is moving (never gate an arrival).
 */
function placesSurface(action: UIAction): boolean {
  switch (action.type) {
    case 'showFact':
    case 'focusView':
    case 'showHistory':
    case 'showParts':
    case 'focusRegion':
    case 'proposeActions':
    case 'showDocument':
    case 'documentScan':
      return true;
    default:
      return false;
  }
}

export function stageReduce(state: StageState, ev: StageEvent): StageOut {
  switch (ev.type) {
    case 'frame': {
      if (ev.action.type !== 'selectVehicle') {
        // Nothing driving in, or an intent navigation would not have wiped anyway → straight through.
        if (state.awaiting === null || !placesSurface(ev.action)) return { state, emit: [ev.action] };
        return { state: { ...state, held: [...state.held, ev.action] }, emit: [] };
      }
      // A new selection supersedes any stage in flight: whatever was held was about the other car.
      const dropped = state.held.length;
      const driving = ev.action.id !== ev.shownIri;
      return {
        state: driving ? { awaiting: ev.action.id, held: [], since: ev.at } : STAGE_INIT,
        emit: [ev.action],
        ...(dropped > 0 ? { dropped } : {}),
      };
    }

    case 'arrived':
      if (ev.iri !== state.awaiting) return { state, emit: [] };
      return { state: STAGE_INIT, emit: state.held };

    case 'expire': {
      if (state.awaiting === null || state.since === null || ev.at - state.since < ABANDON_MS) return { state, emit: [] };
      const dropped = state.held.length;
      return { state: STAGE_INIT, emit: [], ...(dropped > 0 ? { dropped } : {}) };
    }

    case 'reset':
      return { state: STAGE_INIT, emit: [] };
  }
}

// ── 2. The burst coalescer ────────────────────────────────────────────────────

export interface BurstState {
  region: BodyRegion | null;
  /** clock stamp of the last target — the window is measured from it, so a burst extends itself */
  at: number;
}

export const BURST_INIT: BurstState = { region: null, at: 0 };

export type BurstEvent =
  | { type: 'target'; region: BodyRegion; at: number }
  /** the window is believed closed — fires only if it really is (a stale timer is inert) */
  | { type: 'flush'; at: number };

export interface BurstOut {
  state: BurstState;
  /** the region to dolly/flare to, once per burst */
  fire: BodyRegion | null;
}

export function burstReduce(state: BurstState, ev: BurstEvent): BurstOut {
  if (ev.type === 'target') return { state: { region: ev.region, at: ev.at }, fire: null };
  if (state.region === null || ev.at - state.at < BURST_MS) return { state, fire: null };
  return { state: BURST_INIT, fire: state.region };
}

// ── 3. The silence read ───────────────────────────────────────────────────────

/** Milliseconds since the last pilot frame; null when no run is open. */
export function silenceMs(lastFrameAt: number | null, now: number): number | null {
  return lastFrameAt === null ? null : now - lastFrameAt;
}

/**
 * True once a pilot run has been silent long enough that calling it "working" would be a claim the
 * dashboard cannot support. The console then reads "en attente" and keeps counting — the honest
 * state for a driver that may simply be thinking (there is no frame that says "still there").
 */
export function isQuiet(lastFrameAt: number | null, now: number): boolean {
  const silence = silenceMs(lastFrameAt, now);
  return silence !== null && silence >= PILOT_QUIET_MS;
}

/** The gap, read at the coarsest resolution that is still true: "4 s" · "9 min" · "3 h 04". */
export function formatSilence(ms: number): string {
  const seconds = Math.floor(Math.max(0, ms) / 1000);
  if (seconds < 60) return `${seconds}${NBSP}s`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}${NBSP}min`;
  return `${Math.floor(minutes / 60)}${NBSP}h${NBSP}${String(minutes % 60).padStart(2, '0')}`;
}
