import type { CtaAction, Signal, Tone } from '../types';

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
// steady, `error` is an agent-lifecycle failure (amber "échec", NOT a signal-red). The live wire
// carries no tool name; see classifyStep for how the identity + short verb are derived.
export type ToolStepState = 'running' | 'done' | 'error';

export interface ToolStep {
  id: number;
  tool: string; // classified tool key ('unknown' when the narration sentence is unrecognised)
  label: string; // the SHORT chip verb — quieter than the full narration line
  state: ToolStepState;
}

// The "à décider" ambient inbox (C2). The ember stops being a bare alarm count and becomes the badge on
// a QUEUE of decision items — the LangChain ambient-agent triad, human ON the loop:
//   notify   — a concern surfaced hands-off (the §9 overdue crossing, an alarm toast); acknowledge/plan
//   question — the agent needs an answer to proceed (NO live wire source yet — supported, unfed)
//   review   — a proposal that arrived while the operator was elsewhere or was superseded; re-decide it
export type DecisionKind = 'notify' | 'question' | 'review';

export interface DecisionItem {
  id: string; // dedup handle: source/content-derived so the same concern re-arriving is one item
  kind: DecisionKind;
  text: string; // the tier-1 mono line the row shows
  at: number; // the injected clock stamp when it docked (drives the "when" caption)
  serviceType?: string; // notify only: the raw service type → unlocks the "planifier ○" affordance
  vehicleIri?: string; // notify only: which vehicle the concern is about (planifier targets it directly)
  finding?: string; // review only: the proposal's secondary line
  actions?: CtaAction[]; // review only: the deferred proposal's CTA actions (reuse CtaCard wiring)
}

export interface NoyauState {
  phase: NoyauPhase;
  narration: string | null; // the single ticker line — never stacks
  pulseCount: number; // pulses fired this turn (one per narrated status step)
  result: Signal | null; // set on 'done'
  queue: DecisionItem[]; // the à-décider inbox — pending decisions outlive turns; resolve removes
  ember: number; // the badge: pending-decision count (0 while muted; see emberSeen)
  emberSeen: boolean; // true after the inbox was opened → the badge is muted until the next decision
  worstTone: Tone | null; // highest-severity toast this turn → drives the done color
  journal: JournalEntry[];
  steps: ToolStep[]; // the CURRENT turn's tool-step chips; reset on turn/start, cleared on settle
  turnStartedAt: number | null;
  entrySeq: number;
  stepSeq: number; // monotonic id source for steps (never reset → stable React keys)
}

export type NoyauEvent =
  // `failed` (turn/end only) marks the turn as an agent-lifecycle failure (worker died / transport
  // error) — NOT the wire frame; the two client failure paths in Atelier.tsx set it so the last
  // in-flight step chip reads "échec". A normal end (incl. an amber/red finding) omits it.
  | { type: 'turn'; phase: 'start' | 'end'; failed?: boolean }
  | { type: 'status'; message: string }
  | { type: 'toast'; tone: Tone; message: string }
  | { type: 'concern'; message: string; serviceType?: string; vehicleIri?: string } // §9 overdue DOMAIN push (no turn): dock a notify + murmur, hands-off
  | { type: 'defer'; id: string; text: string; finding?: string; actions: CtaAction[] } // a proposal seen elsewhere/superseded → a REVIEW item in the inbox
  | { type: 'resolve'; id: string } // the operator decided a queue item (acted / dismissed) → remove it
  | { type: 'typing'; active: boolean } // local UI cue, not a Mercure frame
  | { type: 'settle' } // local: the done animation finished → back to idle
  | { type: 'clearEmber' }; // local: the inbox was opened → mute the badge (does NOT drain the queue)

export const initialNoyau: NoyauState = {
  phase: 'idle',
  narration: null,
  pulseCount: 0,
  result: null,
  queue: [],
  ember: 0,
  emberSeen: false,
  worstTone: null,
  journal: [],
  steps: [],
  turnStartedAt: null,
  entrySeq: 0,
  stepSeq: 0,
};

// The badge shows the pending-decision count — muted to 0 while `emberSeen` (the inbox was opened) so a
// new decision has to un-mute it. Enqueue dedups by id (same concern re-arriving is one item) and
// re-lights the badge; opening the inbox mutes it WITHOUT draining the queue (decisions outlive it).
function emberOf(queue: DecisionItem[], seen: boolean): number {
  return seen ? 0 : queue.length;
}
function pushDecision(queue: DecisionItem[], item: DecisionItem): DecisionItem[] {
  return queue.some((q) => q.id === item.id) ? queue : [...queue, item];
}

// The live wire carries NO tool name: StepNarrator (api/src/Agent/Ui/StepNarrator.php) pre-maps each
// tool call to a French narration sentence, and only that sentence rides the `status` frame. So we
// reverse-map the sentence to a tool identity + a SHORTER chip verb (the chip is quieter than the
// narration line it echoes). Keyword-matched (not exact) so minor server rewording — and the mock
// scripts (mockStream.ts) — degrade to the right chip instead of the generic fallback. Ordered:
// first match wins, specific before generic.
interface StepRule {
  match: string[];
  tool: string;
  label: string;
}
const STEP_RULES: StepRule[] = [
  { match: ['motul'], tool: 'get_motul_oil', label: 'huile Motul…' },
  { match: ['viscosité', 'huile'], tool: 'get_motul_oil', label: 'huile Motul…' },
  { match: ['catalogue', 'pièces', 'références', 'tecdoc'], tool: 'get_service_parts', label: 'recherche pièces…' },
  { match: ['plaque'], tool: 'lookup_plate', label: 'plaque…' },
  { match: ['programme', "d'entretien"], tool: 'plan_maintenance', label: 'planification…' },
  { match: ['entretiens à venir', 'échéances', 'calcul'], tool: 'list_due_maintenance', label: 'prévision…' },
  { match: ['historique', 'carnet'], tool: 'get_history', label: 'lecture carnet…' },
  { match: ['en ligne'], tool: 'web_search', label: 'recherche web…' },
  { match: ['à jour'], tool: 'update_vehicle', label: 'mise à jour…' },
  { match: ['intervention', 'enregistre'], tool: 'log_maintenance', label: 'enregistrement…' },
  { match: ['garage'], tool: 'list_vehicles', label: 'garage…' },
];

/** Classify a narration sentence into a tool identity + short chip verb (see STEP_RULES). */
export function classifyStep(message: string): { tool: string; label: string } {
  const m = message.toLowerCase();
  for (const rule of STEP_RULES) {
    if (rule.match.some((k) => m.includes(k))) return { tool: rule.tool, label: rule.label };
  }
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

// The done state is announced as a mono label (not color alone — §8): a screen-reader hears the
// outcome on the aria-live line, and green/amber/red stay distinguishable in grayscale.
const RESULT_LABEL: Record<Signal, string> = {
  green: 'posé · tout est à jour',
  amber: 'posé · un service à prévoir',
  red: 'posé · anomalie détectée',
};

function append(state: NoyauState, entry: Omit<JournalEntry, 'id' | 'elapsedMs'>, at: number): JournalEntry[] {
  const elapsedMs = at - (state.turnStartedAt ?? at);
  return [...state.journal, { ...entry, id: state.entrySeq, elapsedMs }];
}

export function reduceNoyau(state: NoyauState, ev: NoyauEvent, at: number): NoyauState {
  switch (ev.type) {
    case 'typing':
      // Busy orb is never interrupted by typing; listening only toggles from rest.
      if (state.phase === 'working' || state.phase === 'done') return state;
      return { ...state, phase: ev.active ? 'listening' : 'idle' };

    case 'turn':
      if (ev.phase === 'start') {
        return {
          ...state,
          phase: 'working',
          narration: null,
          pulseCount: 0,
          result: null,
          worstTone: null,
          steps: [], // a fresh turn starts with no chips
          turnStartedAt: at,
        };
      }
      // phase === 'end'
      {
        const signal = resultFromTone(state.worstTone);
        const label = RESULT_LABEL[signal];
        return {
          ...state,
          phase: 'done',
          result: signal,
          narration: label, // surfaces the outcome as text on the aria-live line
          // resolve the last in-flight chip: 'error' ("échec") only on an agent-lifecycle failure —
          // an amber/red FINDING is a legitimate outcome, so it still resolves to 'done'.
          steps: closeRunning(state.steps, ev.failed ? 'error' : 'done'),
          journal: append(
            { ...state, turnStartedAt: state.turnStartedAt ?? at },
            { kind: 'done', message: label, signal },
            at,
          ),
          entrySeq: state.entrySeq + 1,
        };
      }

    case 'status': {
      // A status implies the agent is working; tolerate a missing turn-start. When it arrives fresh
      // (not already working), it also starts the chip list — same guard as turnStartedAt.
      const fresh = state.phase !== 'working';
      const turnStartedAt = fresh ? at : state.turnStartedAt;
      const base = { ...state, phase: 'working' as const, turnStartedAt };
      const { tool, label } = classifyStep(ev.message);
      // A new tool narrating means the previous one finished → close it, then push the new running step.
      const steps = [...closeRunning(fresh ? [] : state.steps, 'done'), { id: state.stepSeq, tool, label, state: 'running' as const }];
      return {
        ...base,
        narration: ev.message,
        pulseCount: base.pulseCount + 1,
        steps,
        stepSeq: state.stepSeq + 1,
        journal: append(base, { kind: 'pulse', message: ev.message }, at),
        entrySeq: state.entrySeq + 1,
      };
    }

    case 'toast': {
      const worstTone = worse(state.worstTone, ev.tone);
      if (ev.tone === 'alarm') {
        // Real popup + a NOTIFY item in the à-décider inbox; never hijacks the heartbeat narration line.
        const queue = pushDecision(state.queue, { id: `alarm:${ev.message}`, kind: 'notify', text: ev.message, at });
        const emberSeen = queue === state.queue ? state.emberSeen : false; // a new decision un-mutes the badge
        return {
          ...state,
          worstTone,
          queue,
          emberSeen,
          ember: emberOf(queue, emberSeen),
          journal: append(state, { kind: 'alarm', message: ev.message }, at),
          entrySeq: state.entrySeq + 1,
        };
      }
      // Non-alarm toasts feed the narration line (matches the live narration routing).
      return {
        ...state,
        worstTone,
        narration: ev.message,
        journal: append(state, { kind: 'narration', message: ev.message }, at),
        entrySeq: state.entrySeq + 1,
      };
    }

    case 'concern': {
      // A Mercure domain frame (the §9 overdue crossing) — NOT an agent turn. It docks a NOTIFY item in
      // the à-décider inbox and murmurs one concern line on the aria-live narration, but must NOT enter
      // 'working' (no orb lift, no scan-lock, no pulse). phase is preserved (idle stays idle; an in-flight
      // turn keeps working). The item carries its service type + vehicle so the row can offer "planifier".
      const queue = pushDecision(state.queue, {
        id: `concern:${ev.vehicleIri ?? ''}:${ev.serviceType ?? ev.message}`,
        kind: 'notify',
        text: ev.message,
        at,
        serviceType: ev.serviceType,
        vehicleIri: ev.vehicleIri,
      });
      const emberSeen = queue === state.queue ? state.emberSeen : false;
      return {
        ...state,
        narration: ev.message,
        queue,
        emberSeen,
        ember: emberOf(queue, emberSeen),
        worstTone: worse(state.worstTone, 'alarm'),
        journal: append(state, { kind: 'alarm', message: ev.message }, at),
        entrySeq: state.entrySeq + 1,
      };
    }

    case 'defer': {
      // A proposeActions that arrived while the operator was elsewhere (on the parc, no hero to raise the
      // card) or was superseded by a newer proposal → parked as a REVIEW item to re-decide later. No
      // narration, no journal (the inbox IS its surface) — it just docks and lights the badge.
      const queue = pushDecision(state.queue, { id: ev.id, kind: 'review', text: ev.text, at, finding: ev.finding, actions: ev.actions });
      const emberSeen = queue === state.queue ? state.emberSeen : false;
      return { ...state, queue, emberSeen, ember: emberOf(queue, emberSeen) };
    }

    case 'resolve': {
      // The operator decided this item (acted on it or dismissed it) → drop it from the queue. The badge
      // recomputes off the shorter queue (still muted if the inbox was open).
      const queue = state.queue.filter((q) => q.id !== ev.id);
      if (queue.length === state.queue.length) return state; // unknown id — nothing to remove
      return { ...state, queue, ember: emberOf(queue, state.emberSeen) };
    }

    case 'settle':
      // Clear worstTone too: a between-turn alarm push (the §9 overdue crossing — a Mercure domain
      // frame with no turn) must not carry its tone into the next idle period.
      return { ...state, phase: 'idle', result: null, narration: null, worstTone: null, steps: [], turnStartedAt: null };

    case 'clearEmber':
      // Opening the inbox MUTES the badge (acknowledges the pending decisions) but keeps the queue —
      // clearing the badge is not deleting the decisions; only `resolve` removes an item.
      return state.emberSeen && state.ember === 0 ? state : { ...state, emberSeen: true, ember: 0 };
  }
}
