const API = import.meta.env.VITE_API_BASE ?? '';

export interface TurnContext {
  sessionId: string;
  selectedId?: string;
}

/**
 * One agent turn: POST the instruction + the session id. There is no action list in the response —
 * the real Symfony AI agent streams its UI intents over the per-session Mercure topic as the loop
 * produces them (and domain writes fan out on the public vehicle topics). The endpoint acks 202;
 * we only surface transport failures here.
 */
export async function postTurn(instruction: string, ctx: TurnContext): Promise<void> {
  const res = await fetch(`${API}/agent/turn`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ instruction, sessionId: ctx.sessionId, selectedId: ctx.selectedId }),
  });
  if (!res.ok) {
    throw new Error(`agent turn failed: ${res.status}`);
  }
}
