import { mapNarrowing } from './api';
import { authFetch } from './auth';
import { normalisePlate } from './format';
import { SELECTION_ID } from './route';
import type { AgentPart, AgentSelection, BuildCode, HeldVehicle, BuildLabel, FinderFrame, PartPhotoHints, QuestionOption, TurnIntent, TurnState, TurnVehicle, Vehicle } from './types';

type Json = Record<string, unknown>;

const isObject = (v: unknown): v is Json => typeof v === 'object' && v !== null && !Array.isArray(v);
const text = (v: unknown): v is string => typeof v === 'string';
const filled = (v: unknown): v is string => text(v) && v.trim() !== '';

const NOTHING_FOUND = 'Aucune pièce trouvée. Reformulez la demande ou choisissez une catégorie.';
const CANCELLED = 'Demande interrompue.';
export const TURN_FAILED = 'Recherche interrompue (erreur serveur). Réessayez ou choisissez une catégorie.';
export const SILENT = 'Pas de réponse. Réessayez ou choisissez une catégorie.';
// Last resort when no frame at all arrives: the server ends every failed turn with a failed `turn end`.
export const SILENCE_MS = 90_000;

export const silent = (lastFrameAt: number, now: number): boolean => now - lastFrameAt > SILENCE_MS;

export const initialTurn: TurnState = { phase: 'idle', instruction: '', status: null, parts: [], ask: null, notice: null, vehicle: null, buildCode: null, stale: false, held: null };

function options(raw: unknown): QuestionOption[] | null {
  if (!Array.isArray(raw)) return null;
  return raw.flatMap((o) => (isObject(o) && filled(o.label) && text(o.value) ? [{ label: o.label, value: o.value }] : []));
}

function selection(raw: unknown): AgentSelection | null {
  if (!isObject(raw) || !text(raw.id) || !SELECTION_ID.test(raw.id) || !filled(raw.clusterId)) return null;
  return { id: raw.id, clusterId: raw.clusterId, basis: text(raw.basis) ? raw.basis.trim() : '', setAside: filled(raw.setAside) ? raw.setAside.trim() : null };
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
      if (j.phase === 'end' && j.outcome === 'failed') return { type: 'turn', phase: 'end', outcome: 'failed' };
      return j.phase === 'start' || j.phase === 'end' ? { type: 'turn', phase: j.phase } : null;
    case 'status':
      return filled(j.message) ? { type: 'status', message: j.message, ...(text(j.tool) ? { tool: j.tool } : {}) } : null;
    case 'toast':
      return filled(j.message) && (j.tone === 'info' || j.tone === 'ok' || j.tone === 'warn' || j.tone === 'alarm')
        ? { type: 'toast', tone: j.tone, message: j.message }
        : null;
    case 'showParts':
      return typeof j.categoryId === 'number' && filled(j.categoryLabel) && isObject(j.narrowed)
        ? { type: 'showParts', categoryId: j.categoryId, categoryLabel: j.categoryLabel, rationale: text(j.rationale) ? j.rationale : '', narrowed: j.narrowed, selection: selection(j.selection) }
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
    case 'setBuildCode':
      return filled(j.organisationNumber) && /^\d{4,5}$/.test(j.organisationNumber) && filled(j.date) && /^\d{4}-\d{2}-\d{2}$/.test(j.date)
        ? { type: 'setBuildCode', organisationNumber: j.organisationNumber, date: j.date }
        : null;
    case 'setVehicle': {
      const nullable = (v: unknown): string | null => (filled(v) ? v.trim() : null);
      if (typeof j.carId !== 'number' || !Number.isInteger(j.carId) || j.carId <= 0 || (j.carType !== 'PC' && j.carType !== 'CV')) return null;
      const plate = nullable(j.plate);
      return {
        type: 'setVehicle',
        carId: j.carId,
        carType: j.carType,
        make: nullable(j.make),
        model: nullable(j.model),
        engine: nullable(j.engine),
        plate: plate === null ? null : (normalisePlate(plate) ?? plate.toUpperCase()),
      };
    }
    default:
      return null;
  }
}

export type TurnAction =
  | { type: 'start'; instruction: string; continued?: boolean; held?: HeldVehicle | null }
  | { type: 'frame'; frame: FinderFrame }
  | { type: 'cancel' }
  | { type: 'fail'; message: string }
  | { type: 'reset' };

function upsert(state: TurnState, part: AgentPart): AgentPart[] {
  const parts = state.stale ? [] : state.parts;
  const i = parts.findIndex((p) => p.key === part.key);
  if (i === -1) return [...parts, part];
  const next = parts.slice();
  next[i] = part;
  return next;
}

const failed = (state: TurnState, message: string): TurnState => ({ ...state, phase: 'failed', status: null, notice: message });

function applyFrame(state: TurnState, frame: FinderFrame): TurnState {
  switch (frame.type) {
    case 'turn':
      if (frame.phase === 'start') return { ...state, phase: 'running' };
      if (frame.outcome === 'failed') return failed(state, TURN_FAILED);
      return {
        ...state,
        phase: 'done',
        status: null,
        stale: false,
        notice: state.notice ?? (state.parts.length === 0 && state.ask === null ? NOTHING_FOUND : null),
      };
    case 'status':
      return { ...state, status: frame.message };
    case 'toast':
      return { ...state, notice: frame.message };
    case 'showParts':
      return {
        ...state,
        stale: false,
        held: null,
        parts: upsert(state, {
          kind: 'catalog',
          key: `c${frame.categoryId}`,
          categoryId: frame.categoryId,
          label: frame.categoryLabel,
          rationale: frame.rationale,
          narrowing: mapNarrowing(frame.narrowed),
          selection: frame.selection ?? null,
        }),
      };
    case 'showWebPart':
      return {
        ...state,
        stale: false,
        held: null,
        parts: upsert(state, {
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
    case 'setBuildCode':
      return { ...state, buildCode: { organisationNumber: frame.organisationNumber, date: frame.date } };
    case 'setVehicle': {
      const { type: _type, ...vehicle } = frame;
      const same = state.held !== null && state.held.carId === vehicle.carId && state.held.carType === vehicle.carType;
      return { ...state, vehicle, held: same ? state.held : null };
    }
  }
}

export function turnReducer(state: TurnState, action: TurnAction): TurnState {
  switch (action.type) {
    case 'start': {
      const base = { ...initialTurn, phase: 'sending' as const, instruction: action.instruction, held: action.held ?? null };
      return action.continued && state.parts.length > 0 ? { ...base, parts: state.parts, vehicle: state.vehicle, stale: true } : base;
    }
    case 'frame':
      return state.phase === 'sending' || state.phase === 'running' ? applyFrame(state, action.frame) : state;
    case 'cancel':
      return state.phase === 'sending' || state.phase === 'running' ? { ...state, phase: 'done', status: null, notice: CANCELLED } : state;
    case 'fail':
      return failed(state, action.message);
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

export const turnVehicle = (v: Vehicle | null, buildCode: BuildCode | null = null): TurnVehicle | null =>
  v?.carId
    ? { carId: v.carId, carType: v.carType, make: v.make, model: v.model, engine: v.engineCode, plate: v.plate, ...(buildCode ? { buildCode: { organisationNumber: buildCode.organisationNumber } } : {}) }
    : null;

export interface TurnBody {
  instruction: string;
  sessionId: string;
  surface: 'finder';
  intent: TurnIntent;
  vehicle?: TurnVehicle;
  pendingContext?: string;
  images?: string[];
  photoHints?: PartPhotoHints;
  summary?: string;
  buildLabel?: BuildLabel;
}

export interface TurnRequest {
  instruction: string;
  sessionId: string;
  vehicle: TurnVehicle | null;
  pendingContext?: string | null;
  intent?: TurnIntent;
  images?: string[];
  photoHints?: PartPhotoHints | null;
  summary?: string | null;
  buildLabel?: BuildLabel | null;
}

export function turnBody(r: TurnRequest): TurnBody {
  return {
    instruction: r.instruction,
    sessionId: r.sessionId,
    surface: 'finder',
    intent: r.intent ?? 'text',
    ...(r.vehicle ? { vehicle: r.vehicle } : {}),
    ...(r.pendingContext ? { pendingContext: r.pendingContext } : {}),
    ...(r.images && r.images.length > 0 ? { images: r.images } : {}),
    ...(r.photoHints ? { photoHints: r.photoHints } : {}),
    ...(r.summary ? { summary: r.summary } : {}),
    ...(r.buildLabel ? { buildLabel: r.buildLabel } : {}),
  };
}

export class TurnRequestError extends Error {
  constructor(public readonly kind: 'auth' | 'network' | 'server') {
    super(
      kind === 'auth'
        ? 'Connexion requise pour cette recherche.'
        : kind === 'network'
          ? 'Serveur injoignable. Vérifiez la connexion et réessayez.'
          : 'Recherche non lancée (erreur serveur). Réessayez ou choisissez une catégorie.',
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
