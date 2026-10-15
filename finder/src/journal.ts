import { authFetch } from './auth';

// The finder's journal: GET /agent/turns?surface=finder lists agent turns and deterministic searches
// (kind "search") newest first, GET /agent/turns/{id} inlines one row. Server side:
// api/src/ApiResource/AgentTurn.php. Patterns follow dashboard/src/journal.ts (copied, not imported).

type Json = Record<string, unknown>;

const isObject = (v: unknown): v is Json => typeof v === 'object' && v !== null && !Array.isArray(v);
const obj = (v: unknown): Json => (isObject(v) ? v : {});
const list = (v: unknown): unknown[] => (Array.isArray(v) ? v : []);
const str = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : null);
const num = (v: unknown): number | null => (typeof v === 'number' && Number.isFinite(v) ? v : null);

export type RowStatus = 'completed' | 'capped' | 'failed' | 'noop';

export interface SearchInfo {
  rule: string;
  carId: number | null;
  categoryId: number | null;
  clusterCount: number | null;
  totalArticles: number | null;
  unclassifiedCount: number | null;
  outcome: 'ok' | 'degraded' | 'not_found' | 'error';
  httpStatus: number | null;
}

// Server-computed (api AgentTurn::summaryOf): the finder hash path that restores the row's result.
export interface RowVehicle {
  plate: string | null;
  carId: number | null;
  carType: string | null;
  make: string | null;
  model: string | null;
}

export interface JournalRow {
  id: string;
  instruction: string;
  kind: 'agent' | 'search';
  status: RowStatus;
  error: string | null;
  startedAt: string;
  durationMs: number | null;
  toolCallCount: number;
  webSearchCount: number;
  totalTokens: number | null;
  cacheReadTokens: number | null;
  cacheCreationTokens: number | null;
  estimatedCostUsd: number | null;
  partsApiCalls: number | null;
  partsApiCallsByMethod: Record<string, number>;
  images: { mimeType: string; bytes: number }[];
  search: SearchInfo | null;
  route: string | null;
  categoryLabel: string | null;
  vehicle: RowVehicle | null;
}

export interface JournalToolCall {
  seq: number;
  name: string;
  arguments: Json;
  result: string;
  at: string;
  serverSide: boolean;
}

export interface JournalDetail extends JournalRow {
  promptTokens: number | null;
  completionTokens: number | null;
  context: string | null;
  pendingContext: string | null;
  finalText: string | null;
  webSearchQueries: string[];
  toolCalls: JournalToolCall[];
}

const STATUSES: RowStatus[] = ['completed', 'capped', 'failed', 'noop'];
const OUTCOMES: SearchInfo['outcome'][] = ['ok', 'degraded', 'not_found', 'error'];

function mapSearch(raw: unknown): SearchInfo | null {
  if (!isObject(raw)) return null;
  const outcome = OUTCOMES.find((o) => o === raw.outcome) ?? 'error';
  return {
    rule: str(raw.rule) ?? '?',
    carId: num(raw.carId),
    categoryId: num(raw.categoryId),
    clusterCount: num(raw.clusterCount),
    totalArticles: num(raw.totalArticles),
    unclassifiedCount: num(raw.unclassifiedCount),
    outcome,
    httpStatus: num(raw.httpStatus),
  };
}

function mapRow(raw: unknown): JournalRow | null {
  const j = obj(raw);
  const id = str(j['@id']);
  const startedAt = str(j.startedAt);
  if (id === null || startedAt === null) return null;
  const byMethod: Record<string, number> = {};
  for (const [method, n] of Object.entries(obj(j.partsApiCallsByMethod))) {
    if (typeof n === 'number' && n > 0) byMethod[method] = n;
  }
  return {
    id,
    instruction: str(j.instruction) ?? '',
    kind: j.kind === 'search' ? 'search' : 'agent',
    status: STATUSES.find((s) => s === j.status) ?? 'failed',
    error: str(j.error),
    startedAt,
    durationMs: num(j.durationMs),
    toolCallCount: num(j.toolCallCount) ?? 0,
    webSearchCount: num(j.webSearchCount) ?? 0,
    totalTokens: num(j.totalTokens),
    cacheReadTokens: num(j.cacheReadTokens),
    cacheCreationTokens: num(j.cacheCreationTokens),
    estimatedCostUsd: num(j.estimatedCostUsd),
    partsApiCalls: num(j.partsApiCalls),
    partsApiCallsByMethod: byMethod,
    images: list(j.images).flatMap((i) => {
      const mimeType = str(obj(i).mimeType);
      const bytes = num(obj(i).bytes);
      return mimeType && bytes !== null ? [{ mimeType, bytes }] : [];
    }),
    search: mapSearch(j.search),
    route: str(j.route),
    categoryLabel: str(j.categoryLabel),
    vehicle: isObject(j.vehicle)
      ? { plate: str(j.vehicle.plate), carId: num(j.vehicle.carId), carType: str(j.vehicle.carType), make: str(j.vehicle.make), model: str(j.vehicle.model) }
      : null,
  };
}

export function mapRows(raw: unknown): JournalRow[] {
  const j = obj(raw);
  return list(j.member ?? j['hydra:member']).flatMap((r) => mapRow(r) ?? []);
}

export function mapDetail(raw: unknown): JournalDetail {
  const j = obj(raw);
  const row = mapRow(raw) ?? mapRow({ ...j, '@id': '?', startedAt: new Date(0).toISOString() })!;
  return {
    ...row,
    promptTokens: num(j.promptTokens),
    completionTokens: num(j.completionTokens),
    context: str(j.context),
    pendingContext: str(j.pendingContext),
    finalText: str(j.finalText),
    webSearchQueries: list(j.webSearchQueries).filter((q): q is string => typeof q === 'string'),
    toolCalls: list(j.toolCalls)
      .flatMap((c): JournalToolCall[] => {
        const t = obj(c);
        const name = str(t.name);
        return name === null
          ? []
          : [{ seq: num(t.seq) ?? 0, name, arguments: obj(t.arguments), result: typeof t.result === 'string' ? t.result : '', at: str(t.at) ?? '', serverSide: t.serverSide === true }];
      })
      .sort((a, b) => a.seq - b.seq),
  };
}

const FRAME_TOOLS: Record<string, { type: string; label: (args: Json) => string }> = {
  set_vehicle: { type: 'setVehicle', label: (a) => [a.make, a.model, a.engine].filter((v) => typeof v === 'string' && v !== '').join(' ') },
  show_parts: { type: 'showParts', label: (a) => String(a.categoryLabel ?? '') },
  show_web_part: { type: 'showWebPart', label: (a) => [a.brand, a.reference].filter((v) => typeof v === 'string' && v !== '').join(' ') },
  ask_question: { type: 'askQuestion', label: (a) => String(a.question ?? '') },
};

export function framesOf(calls: readonly JournalToolCall[]): { type: string; label: string }[] {
  return calls.flatMap((c) => {
    const tool = FRAME_TOOLS[c.name];
    if (!tool || /"status"\s*:\s*"refused"/.test(c.result)) return [];
    return [{ type: tool.type, label: tool.label(c.arguments) }];
  });
}

const sameDay = (a: Date, b: Date): boolean => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();

export function todayUsage(rows: readonly JournalRow[], now: Date): { byMethod: Record<string, number>; total: number; busiest: { method: string; calls: number } | null } {
  const byMethod: Record<string, number> = {};
  for (const row of rows) {
    if (!sameDay(new Date(row.startedAt), now)) continue;
    for (const [method, n] of Object.entries(row.partsApiCallsByMethod)) byMethod[method] = (byMethod[method] ?? 0) + n;
  }
  const entries = Object.entries(byMethod);
  const top = entries.reduce<[string, number] | null>((best, e) => (best === null || e[1] > best[1] ? e : best), null);
  return { byMethod, total: entries.reduce((s, [, n]) => s + n, 0), busiest: top ? { method: top[0], calls: top[1] } : null };
}

export function formatCost(usd: number | null): string | null {
  if (usd === null) return null;
  const digits = usd < 1 ? 3 : 2;
  return `≈ ${new Intl.NumberFormat('fr-FR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(usd)} $`;
}

export function excerpt(result: string, max = 240): { bytes: number; text: string; cut: boolean } {
  const bytes = new TextEncoder().encode(result).length;
  return result.length > max ? { bytes, text: result.slice(0, max), cut: true } : { bytes, text: result, cut: false };
}

export function outcomeOf(row: JournalRow): { label: string; tone: 'ok' | 'warn' | 'alarm' } {
  if (row.kind === 'search' && row.search) {
    switch (row.search.outcome) {
      case 'not_found':
        return { label: 'introuvable', tone: 'warn' };
      case 'error':
        return { label: 'erreur', tone: 'alarm' };
      case 'degraded':
        return { label: 'partiel', tone: 'warn' };
      case 'ok':
        if (row.search.clusterCount === null) return { label: 'trouvé', tone: 'ok' };
        return { label: `${row.search.clusterCount} pièce${row.search.clusterCount > 1 ? 's' : ''}`, tone: 'ok' };
    }
  }
  switch (row.status) {
    case 'completed':
      return { label: 'terminé', tone: 'ok' };
    case 'noop':
      return { label: 'sans résultat', tone: 'warn' };
    case 'capped':
      return { label: 'plafonné', tone: 'warn' };
    case 'failed':
      return { label: 'échec', tone: 'alarm' };
  }
}

const LD = { Accept: 'application/ld+json' };

export async function getJournal(): Promise<JournalRow[]> {
  const res = await authFetch('/agent/turns?surface=finder', { headers: LD });
  if (!res.ok) throw new Error(res.status === 401 ? 'Connexion requise pour lire le journal.' : `Journal indisponible (HTTP ${res.status}).`);
  return mapRows(await res.json());
}

// The home page's recent searches: the same rows minus what the owner cleared or hid. The journal
// drawer keeps using getJournal, which never hides anything.
export async function getHistory(): Promise<JournalRow[]> {
  const res = await authFetch('/agent/turns?surface=finder&history=1', { headers: LD });
  if (!res.ok) throw new Error(`Historique indisponible (HTTP ${res.status}).`);
  return mapRows(await res.json());
}

export interface HistoryCursor {
  clearedAt: string | null;
  previous: string | null;
}

// No argument: hide everything up to now. With one, put the cursor back to that value (null = never
// cleared): the undo of a clear, fed with the `previous` it returned.
export async function clearHistory(restore?: string | null): Promise<HistoryCursor> {
  const res = await authFetch('/agent/turns/history/clear', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(restore === undefined ? {} : { restore }),
  });
  if (!res.ok) throw new Error(`Historique indisponible (HTTP ${res.status}).`);
  const j = obj(await res.json());
  return { clearedAt: str(j.clearedAt), previous: str(j.previous) };
}

export async function hideFromHistory(id: string): Promise<void> {
  const res = await authFetch(`${id}/hide`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: '{}' });
  if (!res.ok) throw new Error(`Historique indisponible (HTTP ${res.status}).`);
}

export async function getJournalDetail(id: string): Promise<JournalDetail> {
  const res = await authFetch(id, { headers: LD });
  if (!res.ok) throw new Error(`Entrée indisponible (HTTP ${res.status}).`);
  return mapDetail(await res.json());
}
