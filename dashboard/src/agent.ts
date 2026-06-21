import { authFetch } from './auth';

const API = import.meta.env.VITE_API_BASE ?? '';

export interface TurnContext {
  sessionId: string;
  selectedId?: string;
  // Compact summary of the call-to-action card currently on screen (headline + finding + option
  // labels). Sent so a terse reply to it ("la 2.0 16V") is read by the agent against the question
  // it just asked, instead of being re-routed blind. One bounded line — not a conversation history.
  pendingContext?: string;
}

/**
 * One agent turn: POST the instruction + the session id. There is no action list in the response —
 * the real Symfony AI agent streams its UI intents over the per-session Mercure topic as the loop
 * produces them (and domain writes fan out on the public vehicle topics). The endpoint acks 202;
 * we only surface transport failures here.
 */
export async function postTurn(instruction: string, ctx: TurnContext): Promise<void> {
  const res = await authFetch(`${API}/agent/turn`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      instruction,
      sessionId: ctx.sessionId,
      selectedId: ctx.selectedId,
      pendingContext: ctx.pendingContext,
    }),
  });
  if (!res.ok) {
    throw new Error(`agent turn failed: ${res.status}`);
  }
}

/**
 * Ask the worker to stop the turn running for this session. Cooperative: the endpoint acks 202 and
 * the worker ends the turn at its next tool-call boundary (it cannot cut a single in-flight LLM
 * call), so the busy state clears on the streamed turn-end, not on this response.
 */
export async function cancelTurn(sessionId: string): Promise<void> {
  const res = await authFetch(`${API}/agent/turn/cancel`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ sessionId }),
  });
  if (!res.ok) {
    throw new Error(`agent cancel failed: ${res.status}`);
  }
}
