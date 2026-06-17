import type { UIAction } from './types';

const API = import.meta.env.VITE_API_BASE ?? '';

/**
 * One agent turn: POST the user's instruction, get back a UI-action list. No chat transcript —
 * the response is purely actions the reducer applies to the dashboard. Today the endpoint is a
 * stub (ui.md step 3); step 4 makes it the real Symfony AI agent over MCP.
 */
export async function postTurn(instruction: string): Promise<UIAction[]> {
  const res = await fetch(`${API}/agent/turn`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ instruction }),
  });
  if (!res.ok) {
    throw new Error(`agent turn failed: ${res.status}`);
  }
  const data: { actions?: UIAction[] } = await res.json();
  return data.actions ?? [];
}
