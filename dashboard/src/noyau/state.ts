import { DEFAULT_LOCALE, type Locale } from '../i18n/locale';
import { messages, type Messages } from '../i18n/messages';
import type { Driver } from '../pilot';
import type { Signal, Tone } from '../types';

// The frame→state machine for Le Noyau. It speaks the REAL session-frame shapes (turn/status/toast)
// plus two local UI events (typing, settle) that have no wire frame — so Stage C is a transport swap,
// not a rewrite. Pure + clock-injected (timestamps passed in) so it is fully unit-testable.

export type NoyauPhase = 'idle' | 'listening' | 'working' | 'done';

export interface JournalEntry {
  id: number;
  kind: 'pulse' | 'narration' | 'alarm' | 'done';
  message: string;
  elapsedMs: number; // since the current turn started
  signal?: Signal; // on a 'done' entry
}

// A visible step per tool call while Le Noyau works (the C1 chips). `running` shimmers, `done` is
// steady, `error` is an agent-lifecycle failure (amber "échec", NOT a signal-red). The frame carries
// the tool name; see classifyStep for how the chip identity + short verb are resolved from it.
export type ToolStepState = 'running' | 'done' | 'error';

export interface ToolStep {
  id: number;
  tool: string; // classified tool key ('unknown' when the narration sentence is unrecognised)
  label: string; // the SHORT chip verb — quieter than the full narration line
  state: ToolStepState;
}

// A retained toast card. The narration line is a HEARTBEAT — the
// next status overwrites it and `settle` clears it — so a finding pushed as a toast used to scroll
// past at the speed of the next tool call. A notice is the same message with a surface that stays.
export interface Notice {
  id: string; // `${tone}:${text}` — the same finding re-arriving is one card, not a wall
  tone: Tone;
  text: string;
  at: number; // injected clock stamp (drives the "when" caption)
}

/** How many notices stay on screen. The journal keeps every toast, which is what makes the cap honest. */
export const NOTICE_CAP = 3;

export interface NoyauState {
  phase: NoyauPhase;
  narration: string | null; // the single ticker line — never stacks
  /**
   * The current ticker line is the opening GREETING, which is said once per session and must never be
   * re-spoken (`voice.ts` `Utterance.once`). Every other narration — a tool step, a toast, a concern —
   * leaves this false, keeping the documented ticker rule: shown again later, it is news again.
   */
  narrationOnce: boolean;
  notices: Notice[]; // the retained toast cards — outlive the turn; only "vu" removes one
  pulseCount: number; // pulses fired this turn (one per narrated status step)
  result: Signal | null; // set on 'done'
  worstTone: Tone | null; // highest-severity toast this turn → drives the done color
  journal: JournalEntry[];
  steps: ToolStep[]; // the CURRENT turn's tool-step chips; reset on turn/start, cleared on settle
  turnStartedAt: number | null;
  entrySeq: number;
  stepSeq: number; // monotonic id source for steps (never reset → stable React keys)
  // ── who is at the controls (docs/pilot.md) ──
  // The in-app turn and an external MCP pilot share ONE orb, and only one of them should be driving.
  // `driver` is whoever last spoke: the frames are identical on the wire, so take-over is last-frame-
  // wins by construction. Null at rest.
  driver: Driver | null;
  /** clock stamp of the last frame of the open run — the silence caption reads off it. Null at rest. */
  lastFrameAt: number | null;
  /**
   * A PILOT run that has stopped producing frames (see `quiet` event). Still attached, no longer
   * acting — the state an in-app turn cannot have, because a turn is bracketed and bounded while a
   * pilot session is human-paced. Never a failure: the client cannot tell "reading code" from "gone",
   * so it says how long it has been silent and lets the operator judge.
   */
  quiet: boolean;
}

export type NoyauEvent =
  // `failed` (turn/end only) marks the turn as an agent-lifecycle failure (worker died / transport
  // error) — NOT the wire frame; the two client failure paths in Atelier.tsx set it so the last
  // in-flight step chip reads "échec". A normal end (incl. an amber/red finding) omits it.
  // `driver` says who produced the frame (the subscription it arrived on — Atelier tags it). Absent
  // → the in-app turn, so every existing dispatch keeps its meaning unchanged.
  // `resultText` (end only): the closing sentence a deterministic wayfinder supplies for what it just
  // did — used INSTEAD of whatever was last narrated.
  // A real agent turn omits it: its own `toast` already sits on `narration`, and that is the answer —
  // "nothing generic" is achieved by simply not overwriting it (see the 'end' branch below).
  | { type: 'turn'; phase: 'start' | 'end'; failed?: boolean; driver?: Driver; resultText?: string }
  | { type: 'status'; message?: string; tool?: string; driver?: Driver }
  | { type: 'toast'; tone: Tone; message: string; driver?: Driver }
  // local: the pilot run has been silent past PILOT_QUIET_MS — stop claiming it is working
  | { type: 'quiet' }
  | { type: 'concern'; message: string } // §9 overdue DOMAIN push (no turn): murmur, hands-off
  | { type: 'listening'; active: boolean } // local: the MICROPHONE is open — outranks a finished turn's lamp, never a running one
  | { type: 'greet'; message: string } // local: the opening ticker line — a murmur with no turn, no notice card
  | { type: 'dismissNotice'; id: string } // local: "vu" on a retained toast card → drop that card only
  | { type: 'typing'; active: boolean } // local UI cue, not a Mercure frame
  | { type: 'settle' }; // local: the done animation finished → back to idle

export const initialNoyau: NoyauState = {
  phase: 'idle',
  narration: null,
  narrationOnce: false,
  notices: [],
  pulseCount: 0,
  result: null,
  worstTone: null,
  journal: [],
  steps: [],
  turnStartedAt: null,
  entrySeq: 0,
  stepSeq: 0,
  driver: null,
  lastFrameAt: null,
  quiet: false,
};

/**
 * Does a driver hold the console right now? Gates the composer, the ✕ and the wayfinder chips (one
 * turn at a time). A QUIET pilot run does not: it may stay open for minutes while its host thinks,
 * and locking the operator out of their own dashboard for that long would be the tail wagging the
 * dog. Typing then takes the controls back (last-frame-wins, see `driver`).
 */
export function busy(state: NoyauState): boolean {
  return state.phase === 'working' && !(state.driver === 'pilot' && state.quiet);
}

// Dedup by id, plus a cap: the stack is a working set the operator reads at a glance,
// not a log. Returns the SAME array when the card is already up, so a repeating pilot costs no render.
function pushNotice(notices: Notice[], item: Notice): Notice[] {
  if (notices.some((n) => n.id === item.id)) return notices;
  return [...notices, item].slice(-NOTICE_CAP);
}

// The chip vocabulary, keyed by the tool the server ran. `get_history` is a CHIP identity, not a tool
// name: two backend tools (facts + history) both read the carnet and earn the same chip. The label is
// the SHORT verb — quieter than the full narration line it echoes.
const CHIP: Record<string, { tool: string; label: keyof Messages['chipVerb'] }> = {
  // Synthetic — the click-time acknowledgement `send()` dispatches itself (Atelier.tsx), not a server tool.
  acknowledge: { tool: 'acknowledge', label: 'acknowledged' },
  get_motul_oil: { tool: 'get_motul_oil', label: 'motulOil' },
  get_service_parts: { tool: 'get_service_parts', label: 'parts' },
  list_part_categories: { tool: 'get_service_parts', label: 'parts' },
  lookup_plate: { tool: 'lookup_plate', label: 'plate' },
  plan_maintenance: { tool: 'plan_maintenance', label: 'planning' },
  list_due_maintenance: { tool: 'list_due_maintenance', label: 'forecast' },
  get_maintenance_history: { tool: 'get_history', label: 'carnet' },
  get_vehicle_facts: { tool: 'get_history', label: 'carnet' },
  get_history: { tool: 'get_history', label: 'carnet' },
  web_search: { tool: 'web_search', label: 'webSearch' },
  update_vehicle: { tool: 'update_vehicle', label: 'updating' },
  log_maintenance: { tool: 'log_maintenance', label: 'recording' },
  list_vehicles: { tool: 'list_vehicles', label: 'garage' },
};

function chip(entry: { tool: string; label: keyof Messages['chipVerb'] }, locale: Locale): { tool: string; label: string } {
  return { tool: entry.tool, label: messages(locale).chipVerb[entry.label] };
}

// Fallback path only. StepNarrator (api/src/Agent/Ui/StepNarrator.php) sends the tool key alone (no
// sentence since 2026-11-09), so the prose no longer carries the identity. These keyword rules stay for
// frames without the field — a server mid-deploy, and the mock scripts (mockStream.ts) — and they are
// French-only by nature, which is exactly why they must not be load-bearing. Ordered: first match
// wins, specific before generic.
const STEP_RULES: Array<{ match: string[]; tool: string }> = [
  { match: ['motul'], tool: 'get_motul_oil' },
  { match: ['viscosité', 'huile'], tool: 'get_motul_oil' },
  { match: ['catalogue', 'pièces', 'références', 'tecdoc'], tool: 'get_service_parts' },
  { match: ['plaque'], tool: 'lookup_plate' },
  { match: ['programme', "d'entretien"], tool: 'plan_maintenance' },
  { match: ['entretiens à venir', 'échéances', 'calcul'], tool: 'list_due_maintenance' },
  { match: ['historique', 'carnet'], tool: 'get_history' },
  { match: ['en ligne'], tool: 'web_search' },
  { match: ['à jour'], tool: 'update_vehicle' },
  { match: ['intervention', 'enregistre'], tool: 'log_maintenance' },
  { match: ['garage'], tool: 'list_vehicles' },
];

/**
 * Resolve a narrated step to a chip identity + short verb.
 *
 * `tool` is the server's own key and wins outright when we recognise it. An UNRECOGNISED key (a tool
 * added server-side before this table knew about it) falls through to the prose rather than to
 * `unknown` — the sentence is then still the best evidence available.
 */
export function classifyStep(message: string | undefined, tool?: string, locale: Locale = DEFAULT_LOCALE): { tool: string; label: string } {
  if (tool && CHIP[tool]) return chip(CHIP[tool], locale);
  // A bare key the table does not know: a generic verb, never the raw tool name.
  if (message === undefined || message === '') return { tool: 'unknown', label: messages(locale).chipVerb.working };

  const m = message.toLowerCase();
  for (const rule of STEP_RULES) {
    if (rule.match.some((k) => m.includes(k))) return chip(CHIP[rule.tool], locale);
  }
  // Unrecognised: the server's own sentence is the only label there is. It is prose in whatever
  // language the server wrote it — which is the honest thing to show, not a guess.
  return { tool: 'unknown', label: message };
}

/** Resolve every still-running step (there is at most one) to `to` — the prior step is done when the
 *  next tool narrates, and the last open step is done/error at turn end. */
function closeRunning(steps: ToolStep[], to: ToolStepState): ToolStep[] {
  return steps.map((s) => (s.state === 'running' ? { ...s, state: to } : s));
}

const TONE_RANK: Record<Tone, number> = { info: 0, ok: 1, warn: 2, alarm: 3 };

function worse(a: Tone | null, b: Tone): Tone {
  if (a === null) return b;
  return TONE_RANK[b] > TONE_RANK[a] ? b : a;
}

/** The done filament color, derived honestly from the worst toast seen this turn. */
function resultFromTone(tone: Tone | null): Signal {
  if (tone === 'alarm') return 'red';
  if (tone === 'warn') return 'amber';
  return 'green';
}

// LAST-RESORT fallback only: a
// tone-keyed sentence used to announce every turn end regardless of what happened, which both claimed
// an unchecked finding ("tout est à jour" from a turn that read nothing) and read as nonsense once
// spoken aloud. Real closing sentences now come from the turn itself — a deterministic wayfinder's own
// `resultText`, or a real agent turn's own `toast` already sitting on `narration` — so this only fires
// when a turn ends having narrated NOTHING at all. The signal itself (green/amber/red) is unaffected
// and stays a separate carrier: `t.noyau.signal[result]` on the orb's aria-label (Noyau.tsx:63) and the
// journal done row's `signal` field.
function append(state: NoyauState, entry: Omit<JournalEntry, 'id' | 'elapsedMs'>, at: number): JournalEntry[] {
  const elapsedMs = at - (state.turnStartedAt ?? at);
  return [...state.journal, { ...entry, id: state.entrySeq, elapsedMs }];
}

/**
 * `locale` defaults rather than being required — unlike format.ts, which forces it. There is ONE
 * production call site (useNoyau) and the reducer is otherwise locale-free; a required argument here
 * would only churn ~200 test dispatches without catching anything a reader would miss.
 */
export function reduceNoyau(state: NoyauState, ev: NoyauEvent, at: number, locale: Locale = DEFAULT_LOCALE): NoyauState {
  switch (ev.type) {
    case 'typing':
      // Busy orb is never interrupted by typing; listening only toggles from rest. A finished turn's
      // RESULT is protected here too — a caret landing in the composer must not cut the done filament
      // short. The microphone is the exception, and it has its own event for exactly that reason.
      if (state.phase === 'working' || state.phase === 'done') return state;
      return { ...state, phase: ev.active ? 'listening' : 'idle' };

    case 'listening':
      // An open microphone is a state the operator has a right to SEE, so it outranks the done dwell
      // and puts out its result lamp with it (`result` is half the fault-lamp's CSS key). It does not
      // outrank a running turn: that turn owns the orb and its own motion, and the mic is inert while
      // busy anyway (`Composer.tsx`) — only a pilot-driven turn can even reach that case.
      //
      // Split from `typing` on 2026-09-11: both used to dispatch `typing`, so pressing the mic inside
      // the 1.6 s done dwell showed no cue at all and the orb then settled itself to veille with the
      // microphone still open. Reported live, the same day a transcript started submitting itself —
      // which is what made "finish a turn, press the mic again" the ordinary path.
      if ('working' === state.phase) return state;
      if (ev.active) return { ...state, phase: 'listening', result: null };

      // The close and the turn's start are one gesture now (`dictation.ts` `submission`): if the turn
      // is already under way, the mic closing must not drag the orb back to veille.
      return 'listening' === state.phase ? { ...state, phase: 'idle' } : state;

    case 'turn':
      if (ev.phase === 'start') {
        return {
          ...state,
          phase: 'working',
          narration: null,
          narrationOnce: false,
          pulseCount: 0,
          result: null,
          worstTone: null,
          steps: [], // a fresh turn starts with no chips
          turnStartedAt: at,
          driver: ev.driver ?? 'app',
          lastFrameAt: at,
          quiet: false,
        };
      }
      // phase === 'end'
      {
        const signal = resultFromTone(state.worstTone);
        // The wayfinder's own closing sentence, else whatever is already narrated (a real agent
        // turn's `toast`), else NOTHING. There is
        // deliberately no generic fallback: `resultFromTone(null)` is 'green', so one would have a
        // turn that did nothing announce « Tout est à jour. » — a verdict on the vehicle invented by
        // an action that never looked at it. The signal still reaches text via the orb's aria-label
        // and the journal's done row, which is what the a11y fix actually required.
        const narration = ev.resultText ?? state.narration ?? null;
        return {
          ...state,
          phase: 'done',
          result: signal,
          narration,
          narrationOnce: false,
          // resolve the last in-flight chip: 'error' ("échec") only on an agent-lifecycle failure —
          // an amber/red FINDING is a legitimate outcome, so it still resolves to 'done'.
          steps: closeRunning(state.steps, ev.failed ? 'error' : 'done'),
          journal: append(
            { ...state, turnStartedAt: state.turnStartedAt ?? at },
            // A silent turn still gets a journal row, and it says the SIGNAL word ('vert'/'ambre'/
            // 'rouge') — a fact about the outcome — never an invented sentence about the vehicle.
            { kind: 'done', message: narration ?? messages(locale).noyau.signal[signal], signal },
            at,
          ),
          entrySeq: state.entrySeq + 1,
          // the done filament still belongs to whoever ran the turn; `settle` hands the controls back
          driver: ev.driver ?? state.driver ?? 'app',
          lastFrameAt: at,
          quiet: false,
        };
      }

    case 'status': {
      // A status implies the agent is working; tolerate a missing turn-start. When it arrives fresh
      // (not already working), it also starts the chip list — same guard as turnStartedAt.
      const fresh = state.phase !== 'working';
      const turnStartedAt = fresh ? at : state.turnStartedAt;
      // A pilot run has no `turn:start` at all — this IS its start (see NarrateStepProcessor).
      const base = { ...state, phase: 'working' as const, turnStartedAt, driver: ev.driver ?? 'app', lastFrameAt: at, quiet: false };
      const { tool, label } = classifyStep(ev.message, ev.tool, locale);
      // A new tool narrating means the previous one finished → close it, then push the new running step.
      const steps = [...closeRunning(fresh ? [] : state.steps, 'done'), { id: state.stepSeq, tool, label, state: 'running' as const }];
      // A tool-only step (no `message`) leaves the ticker alone: it carries only what the model said.
      const said = ev.message || null;
      return {
        ...base,
        ...(said !== null ? { narration: said, narrationOnce: false } : {}),
        pulseCount: base.pulseCount + 1,
        steps,
        stepSeq: state.stepSeq + 1,
        journal: append(base, { kind: 'pulse', message: said ?? label }, at),
        entrySeq: state.entrySeq + 1,
      };
    }

    case 'toast': {
      const worstTone = worse(state.worstTone, ev.tone);
      // A toast proves the run is alive, but never OPENS one: an alarm pushed between turns (the §9
      // crossing) must not lift the orb. Only refresh liveness when a run is already open.
      const live = 'working' === state.phase ? { driver: ev.driver ?? state.driver, lastFrameAt: at, quiet: false } : {};
      // Every tone retains a card — the toast IS the agent's answer, and an answer must outlive the
      // heartbeat that carried it. The tone-specific routing below is unchanged on top of that.
      const notices = pushNotice(state.notices, { id: `${ev.tone}:${ev.message}`, tone: ev.tone, text: ev.message, at });
      if (ev.tone === 'alarm') {
        // Real popup; never hijacks the heartbeat narration line.
        return {
          ...state,
          ...live,
          worstTone,
          notices,
          journal: append(state, { kind: 'alarm', message: ev.message }, at),
          entrySeq: state.entrySeq + 1,
        };
      }
      // Non-alarm toasts feed the narration line (matches the live narration routing).
      return {
        ...state,
        ...live,
        worstTone,
        notices,
        narration: ev.message,
        narrationOnce: false,
        journal: append(state, { kind: 'narration', message: ev.message }, at),
        entrySeq: state.entrySeq + 1,
      };
    }

    case 'concern': {
      // A Mercure domain frame (the §9 overdue crossing) — NOT an agent turn. It murmurs one concern line
      // on the aria-live narration, but must NOT enter 'working' (no orb lift, no scan-lock, no pulse).
      // phase is preserved (idle stays idle; an in-flight turn keeps working).
      return {
        ...state,
        narration: ev.message,
        narrationOnce: false,
        worstTone: worse(state.worstTone, 'alarm'),
        journal: append(state, { kind: 'alarm', message: ev.message }, at),
        entrySeq: state.entrySeq + 1,
      };
    }

    case 'greet':
      // The opening line: a murmur on the
      // ticker only. Unlike `status` it must NOT enter 'working' (there is no turn to end, so nothing
      // would ever settle it back down) and unlike `toast` it mints no notice card — "Bonjour" is not
      // an answer to keep. phase and worstTone are untouched.
      return {
        ...state,
        narration: ev.message,
        narrationOnce: true,
        journal: append(state, { kind: 'narration', message: ev.message }, at),
        entrySeq: state.entrySeq + 1,
      };

    case 'dismissNotice': {
      // "vu" on a card: drop that card only.
      const notices = state.notices.filter((n) => n.id !== ev.id);
      return notices.length === state.notices.length ? state : { ...state, notices };
    }

    case 'quiet':
      // Only a PILOT run can go quiet: an in-app turn is bracketed and bounded, and its silence is a
      // liveness FAULT the watchdog owns, not a resting state. Identity when it does not apply, so a
      // stray tick costs no render.
      if (state.phase !== 'working' || state.driver !== 'pilot' || state.quiet) return state;
      return { ...state, quiet: true };

    case 'settle':
      // Clear worstTone too: a between-turn alarm push (the §9 overdue crossing — a Mercure domain
      // frame with no turn) must not carry its tone into the next idle period.
      return { ...state, phase: 'idle', result: null, narration: null, worstTone: null, steps: [], turnStartedAt: null, driver: null, lastFrameAt: null, quiet: false };
  }
}
