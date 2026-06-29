import { API, authFetch } from './auth';

// ─────────────────────────────────────────────────────────────────────────────
// Atelier — the agent turn (see PROVENANCE: Tier-1 transport re-authored fresh from
// the /agent/turn request contract, no import path back).
//
// A turn is async: POST acks 202 and the reply arrives as session frames over the
// per-session Mercure topic (turn/status/toast + the UIAction union). There is NO
// action list in the response. The chat is hidden — the reply IS the state mutations.
// ─────────────────────────────────────────────────────────────────────────────

export interface TurnContext {
  sessionId: string;
  /** The selected vehicle IRI, so a vehicle-scoped instruction lands on the right car. */
  selectedId?: string;
  /** A compact one-line snapshot of the on-screen state (NOT a transcript). */
  summary?: string;
  /** The CTA card being replied to (headline + finding + option labels), one bounded line. */
  pendingContext?: string;
}

export interface TurnBody {
  instruction: string;
  sessionId: string;
  selectedId?: string;
  summary?: string;
  pendingContext?: string;
}

/** Assemble the POST body, omitting any absent optional (so an unset field never serializes as null). */
export function buildTurnBody(instruction: string, ctx: TurnContext): TurnBody {
  const body: TurnBody = { instruction, sessionId: ctx.sessionId };
  if (ctx.selectedId !== undefined) body.selectedId = ctx.selectedId;
  if (ctx.summary !== undefined) body.summary = ctx.summary;
  if (ctx.pendingContext !== undefined) body.pendingContext = ctx.pendingContext;
  return body;
}

/**
 * Post one agent turn. The endpoint acks 202 (the turn runs on the worker); the reply streams over the
 * session topic. We only surface a transport failure (the caller clears the optimistic busy state).
 */
export async function postTurn(instruction: string, ctx: TurnContext): Promise<void> {
  const res = await authFetch(`${API}/agent/turn`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(buildTurnBody(instruction, ctx)),
  });
  if (!res.ok) throw new Error(`agent turn failed: ${res.status}`);
}

/**
 * Ask the worker to stop this session's turn. Cooperative: the endpoint acks 202 and the worker ends
 * the turn at its next tool boundary, so the busy state clears on the streamed turn-end, not this ack.
 */
export async function cancelTurn(sessionId: string): Promise<void> {
  const res = await authFetch(`${API}/agent/turn/cancel`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ sessionId }),
  });
  if (!res.ok) throw new Error(`agent cancel failed: ${res.status}`);
}
