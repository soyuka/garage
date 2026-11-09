import { API, authFetch } from './auth';
import { apiUrl, hydraMembers, normalizeIri } from './garage';

// ─────────────────────────────────────────────────────────────────────────────
// Atelier — the agent debug journal (Tier-1 transport re-authored
// fresh from the /agent/turns contract; reuses garage's tested hydra/@id helpers).
//
// The agent is a black box once a turn runs; this is where you see what it actually
// did — the built LLM context, every tool call with arguments + result, the web
// searches, the token usage, the raw payloads. The user's favourite debug surface.
// ─────────────────────────────────────────────────────────────────────────────

/** One journalled tool call, in execution order. */
export interface JournalToolCall {
  seq: number;
  name: string;
  arguments: Record<string, unknown>;
  result: string;
  at: string;
  serverSide: boolean;
}

/** The light listing row (GET /agent/turns) — summary + counts, no heavy payload. */
export interface TurnSummary {
  id: string; // the turn IRI (@id, normalized) — used to GET the detail
  instruction: string;
  status: 'completed' | 'capped' | 'failed';
  error?: string | null;
  provider: string;
  model: string;
  stream: boolean;
  sessionId: string;
  selectedId?: string | null;
  startedAt: string;
  finishedAt?: string | null;
  durationMs?: number | null;
  toolCallCount: number;
  webSearchCount: number;
  totalTokens?: number | null;
}

/** The full detail (GET /agent/turns/{id}) — the summary plus context, tool calls and raw payloads. */
export interface TurnDetail extends TurnSummary {
  context?: string | null;
  summary?: string | null;
  pendingContext?: string | null;
  finalText?: string | null;
  promptTokens?: number | null;
  completionTokens?: number | null;
  webSearchQueries: string[];
  toolCalls: JournalToolCall[];
  rawCalls: Array<Record<string, unknown>>;
}

const LD = 'application/ld+json';

/** GET the journal listing — the owner's turns, newest first. Normalizes each IRI to `id`. */
export async function getTurns(): Promise<TurnSummary[]> {
  const res = await authFetch(`${API}/agent/turns`, { headers: { Accept: LD } });
  if (!res.ok) throw new Error(`journal fetch failed: ${res.status}`);
  return hydraMembers<TurnSummary & { '@id'?: string }>(await res.json()).map((t) => normalizeIri(t));
}

/** GET one turn's full detail by its IRI. */
export async function getTurn(iri: string): Promise<TurnDetail> {
  const res = await authFetch(apiUrl(iri), { headers: { Accept: LD } });
  if (!res.ok) throw new Error(`turn fetch failed: ${res.status}`);
  return normalizeIri((await res.json()) as TurnDetail & { '@id'?: string });
}
