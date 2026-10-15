import { mapNarrowing } from './api';
import { authFetch } from './auth';
import type { AgentPart, FinderFrame, QuestionOption, TurnState, TurnVehicle } from './types';

type Json = Record<string, unknown>;

const isObject = (v: unknown): v is Json => typeof v === 'object' && v !== null && !Array.isArray(v);
const text = (v: unknown): v is string => typeof v === 'string';
const filled = (v: unknown): v is string => text(v) && v.trim() !== '';

const NOTHING_FOUND = 'Aucune pièce trouvée pour cette demande. Reformulez-la, ou choisissez une catégorie dans la liste.';
const CANCELLED = 'Demande interrompue.';

export const initialTurn: TurnState = { phase: 'idle', instruction: '', status: null, parts: [], ask: null, notice: null };

function options(raw: unknown): QuestionOption[] | null {
  if (!Array.isArray(raw)) return null;
  return raw.flatMap((o) => (isObject(o) && filled(o.label) && text(o.value) ? [{ label: o.label, value: o.value }] : []));
}

export function parseFrame(data: string): FinderFrame | null {
  let j: unknown;
  try {
    j = JSON.parse(data);
  } catch {
    return null;
  }
  if (!isObject(j)) return null;
  switch (j.type) {
    case 'turn':
      return j.phase === 'start' || j.phase === 'end' ? { type: 'turn', phase: j.phase } : null;
    case 'status':
      return filled(j.message) ? { type: 'status', message: j.message, ...(text(j.tool) ? { tool: j.tool } : {}) } : null;
    case 'toast':
      return filled(j.message) && (j.tone === 'info' || j.tone === 'ok' || j.tone === 'warn' || j.tone === 'alarm')
        ? { type: 'toast', tone: j.tone, message: j.message }
        : null;
    case 'showParts':
      return typeof j.categoryId === 'number' && filled(j.categoryLabel) && isObject(j.narrowed)
        ? { type: 'showParts', categoryId: j.categoryId, categoryLabel: j.categoryLabel, rationale: text(j.rationale) ? j.rationale : '', narrowed: j.narrowed }
        : null;
    case 'showWebPart':
      return filled(j.categoryLabel) && filled(j.reference) && text(j.brand) && text(j.sourceUrl) && /^https?:\/\//.test(j.sourceUrl)
        ? {
            type: 'showWebPart',
            categoryLabel: j.categoryLabel,
            reference: j.reference,
            brand: j.brand,
            sourceUrl: j.sourceUrl,
            sourceTitle: text(j.sourceTitle) ? j.sourceTitle : '',
            rationale: text(j.rationale) ? j.rationale : '',
          }
        : null;
    case 'askQuestion': {
      const opts = options(j.options);
      return filled(j.question) && opts !== null && text(j.context) ? { type: 'askQuestion', question: j.question, options: opts, context: j.context } : null;
    }
    default:
      return null;
  }
}

export type TurnAction =
  | { type: 'start'; instruction: string }
  | { type: 'frame'; frame: FinderFrame }
  | { type: 'cancel' }
  | { type: 'fail'; message: string }
  | { type: 'reset' };

function upsert(parts: AgentPart[], part: AgentPart): AgentPart[] {
  const i = parts.findIndex((p) => p.key === part.key);
  if (i === -1) return [...parts, part];
  const next = parts.slice();
  next[i] = part;
  return next;
}

function applyFrame(state: TurnState, frame: FinderFrame): TurnState {
  switch (frame.type) {
    case 'turn':
      if (frame.phase === 'start') return { ...state, phase: 'running' };
      return {
        ...state,
        phase: 'done',
        status: null,
        notice: state.notice ?? (state.parts.length === 0 && state.ask === null ? NOTHING_FOUND : null),
      };
    case 'status':
      return { ...state, status: frame.message };
    case 'toast':
      return { ...state, notice: frame.message };
    case 'showParts':
      return {
        ...state,
        parts: upsert(state.parts, {
          kind: 'catalog',
          key: `c${frame.categoryId}`,
          categoryId: frame.categoryId,
          label: frame.categoryLabel,
          rationale: frame.rationale,
          narrowing: mapNarrowing(frame.narrowed),
        }),
      };
    case 'showWebPart':
      return {
        ...state,
        parts: upsert(state.parts, {
          kind: 'web',
          key: `w${frame.brand}|${frame.reference}`,
          label: frame.categoryLabel,
          reference: frame.reference,
          brand: frame.brand,
          sourceUrl: frame.sourceUrl,
          sourceTitle: frame.sourceTitle,
          rationale: frame.rationale,
        }),
      };
    case 'askQuestion':
      return { ...state, ask: { question: frame.question, options: frame.options, context: frame.context } };
  }
}

export function turnReducer(state: TurnState, action: TurnAction): TurnState {
  switch (action.type) {
    case 'start':
      return { ...initialTurn, phase: 'sending', instruction: action.instruction };
    case 'frame':
      return state.phase === 'sending' || state.phase === 'running' ? applyFrame(state, action.frame) : state;
    case 'cancel':
      return state.phase === 'sending' || state.phase === 'running' ? { ...state, phase: 'done', status: null, notice: CANCELLED } : state;
    case 'fail':
      return { ...state, phase: 'failed', status: null, notice: action.message };
    case 'reset':
      return initialTurn;
  }
}

export const isBusy = (s: TurnState): boolean => s.phase === 'sending' || s.phase === 'running';

export function sessionTopic(base: string, sessionId: string): string {
  return `${base.replace(/\/+$/, '')}/agent/sessions/${sessionId}`;
}

export function hubUrl(hub: string, origin: string, topic: string): string {
  const url = new URL(hub, origin);
  url.searchParams.append('match', topic);
  return url.toString();
}

export interface TurnBody {
  instruction: string;
  sessionId: string;
  surface: 'finder';
  vehicle: TurnVehicle;
  pendingContext?: string;
}

export function turnBody(instruction: string, sessionId: string, vehicle: TurnVehicle, pendingContext: string | null): TurnBody {
  return { instruction, sessionId, surface: 'finder', vehicle, ...(pendingContext ? { pendingContext } : {}) };
}

export class TurnRequestError extends Error {
  constructor(public readonly kind: 'auth' | 'network' | 'server') {
    super(
      kind === 'auth'
        ? 'Connexion requise pour interroger l’agent.'
        : kind === 'network'
          ? 'Le serveur ne répond pas. Vérifiez la connexion puis réessayez.'
          : 'L’agent n’a pas pu démarrer. Réessayez dans un instant, ou choisissez une catégorie dans la liste.',
    );
  }
}

export async function postTurn(body: TurnBody): Promise<void> {
  let res: Response;
  try {
    res = await authFetch('/agent/turn', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  } catch {
    throw new TurnRequestError('network');
  }
  if (res.status === 401 || res.status === 403) throw new TurnRequestError('auth');
  if (!res.ok) throw new TurnRequestError('server');
}

export async function postCancel(sessionId: string): Promise<void> {
  await authFetch('/agent/turn/cancel', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ sessionId }) }).catch(() => undefined);
}
