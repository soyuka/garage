import { authFetch } from './auth';

const API = import.meta.env.VITE_API_BASE ?? '';

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
  id: string; // the turn IRI (@id, normalized), used to GET the detail
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

type Raw<T> = T & { '@id'?: string };

/** GET the journal listing — the owner's turns, newest first. Normalizes the IRI to `id`. */
export async function getTurns(): Promise<TurnSummary[]> {
  const res = await authFetch(`${API}/agent/turns`, { headers: { Accept: 'application/ld+json' } });
  if (!res.ok) throw new Error(`journal fetch failed: ${res.status}`);
  const data = (await res.json()) as { member?: Raw<TurnSummary>[]; 'hydra:member'?: Raw<TurnSummary>[] };
  const members = data.member ?? data['hydra:member'] ?? [];
  return members.map((t) => ({ ...t, id: t['@id'] ?? t.id }) as TurnSummary);
}

/** GET one turn's full detail by its IRI. */
export async function getTurn(iri: string): Promise<TurnDetail> {
  const res = await authFetch(`${API}${iri}`, { headers: { Accept: 'application/ld+json' } });
  if (!res.ok) throw new Error(`turn fetch failed: ${res.status}`);
  const t = (await res.json()) as Raw<TurnDetail>;
  return { ...t, id: t['@id'] ?? t.id } as TurnDetail;
}
