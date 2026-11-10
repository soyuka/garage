import { regionForFrame, type BodyRegion } from '../scene/anchors';
import { findLoggedHit } from './docScan';
import { topicForService, topicForText, type FocusTopic } from './focusTopic';
import type { CtaAction, DocScanHit, DocumentSpec, Iri, PanelKind, PlannedPart, SpecNode, UIAction } from '../types';

export type Section = 'entretien' | 'pieces' | 'documents' | 'caracteristiques';

export const SECTIONS: readonly Section[] = ['entretien', 'pieces', 'documents', 'caracteristiques'];

export interface CtaState {
  headline: string;
  finding?: string;
  actions: CtaAction[];
}

export type DocRef = DocumentSpec;

/** One documentation search in the log; `key` is new per run. `hits: []` = no match. */
export interface DocSearch {
  query: string;
  hits: DocScanHit[];
  key: number;
}

export type Projection =
  | { kind: 'facts'; keys: string[]; title?: string; nodes?: SpecNode[] }
  | { kind: 'panel'; panel: Exclude<PanelKind, 'carnet'>; keys: string[] }
  | { kind: 'history' }
  // `parts: null` = the frame named no parts; the renderer itemises the task's own list.
  | { kind: 'parts'; service: string; taskId: Iri; parts: PlannedPart[] | null }
  | { kind: 'assembly'; region: BodyRegion; partKeys: string[] }
  | { kind: 'document' };

export interface ViewState {
  section: Section;
  focusRegion: BodyRegion | null;
  /** The service type the ring's label names, when one is known. */
  focusTopic: string | null;
  /** Set by a frame that aims the ring itself; derived rings never move it until reset. */
  focusExplicit: boolean;
  projection: Projection | null;
  /** Bumped on every summon, a repeat included, so the slot replays its arrival. Never reset. */
  projectionKey: number;
  proposal: CtaState | null;
  document: DocRef | null;
  /** Bumped each time the ring is aimed somewhere new: the car stage points once per value. Never reset. */
  focusSeq: number;
  /** This turn's documentation searches, oldest first, until a new turn, the operator opening a document, or a settle. */
  searches: DocSearch[];
  /** The logged hit a `showDocument` picked: the log ticks it, then the viewer opens (`endScan`). */
  settled: { key: number; hit: number } | null;
  /** Source of `DocSearch.key`, counted by the turn cues. Never reset. */
  scanSeq: number;
}

export type ViewAction =
  | UIAction
  | { type: 'section'; section: Section }
  | { type: 'reset' }
  | { type: 'dismissProjection' }
  | { type: 'clearProposal' }
  | { type: 'endScan' };

export const initialView: ViewState = {
  section: 'entretien',
  focusRegion: null,
  focusTopic: null,
  focusExplicit: false,
  projection: null,
  projectionKey: 0,
  proposal: null,
  document: null,
  focusSeq: 0,
  searches: [],
  settled: null,
  scanSeq: 0,
};

const PANEL_SECTION: Record<PanelKind, Section> = {
  specs: 'caracteristiques',
  tasks: 'entretien',
  carnet: 'entretien',
  visits: 'entretien',
  parts: 'pieces',
  docs: 'documents',
};

function derive(state: ViewState, found: FocusTopic | null): Partial<ViewState> {
  if (state.focusExplicit || !found) return {};
  return { focusRegion: found.region, focusTopic: found.topic };
}

function project(state: ViewState, projection: Projection, patch: Partial<ViewState> = {}): ViewState {
  return { ...state, ...patch, projection, projectionKey: state.projectionKey + 1 };
}

function keyTopic(keys: string[], region: BodyRegion | null): FocusTopic | null {
  return region ? { region, topic: keys[0] ?? '' } : null;
}

export function reduceView(state: ViewState, action: ViewAction): ViewState {
  const next = reduce(state, action);
  if (next === state || next.focusRegion === null) return next;
  const aimed = next.focusRegion !== state.focusRegion || next.focusTopic !== state.focusTopic || action.type === 'revealAssembly';
  return aimed ? { ...next, focusSeq: state.focusSeq + 1 } : next;
}

function reduce(state: ViewState, action: ViewAction): ViewState {
  switch (action.type) {
    case 'section':
      return state.section === action.section ? state : { ...state, section: action.section };

    case 'reset':
      return { ...initialView, projectionKey: state.projectionKey, proposal: state.proposal, focusSeq: state.focusSeq, scanSeq: state.scanSeq };

    case 'endScan':
      return state.searches.length > 0 || state.settled ? { ...state, searches: [], settled: null } : state;

    case 'turn':
      return action.phase === 'start' && (state.searches.length > 0 || state.settled) ? { ...state, searches: [], settled: null } : state;

    case 'dismissProjection':
      return { ...state, projection: null };

    case 'clearProposal':
      return state.proposal ? { ...state, proposal: null } : state;

    case 'showFact': {
      const projection: Projection = { kind: 'facts', keys: action.keys };
      if (action.title !== undefined) projection.title = action.title;
      if (action.nodes) projection.nodes = action.nodes;
      return project(state, projection, { section: 'caracteristiques', ...derive(state, keyTopic(action.keys, regionForFrame(action))) });
    }

    case 'focusView': {
      const keys = action.keys ?? [];
      const projection: Projection = action.panel === 'carnet' ? { kind: 'history' } : { kind: 'panel', panel: action.panel, keys };
      return project(state, projection, { section: PANEL_SECTION[action.panel], ...derive(state, keyTopic(keys, regionForFrame(action))) });
    }

    case 'showHistory':
      return project(state, { kind: 'history' }, { section: 'entretien' });

    case 'showParts':
      return project(state, { kind: 'parts', service: action.service, taskId: action.taskId, parts: action.parts ?? null }, { section: 'pieces', ...derive(state, topicForService(action.service)) });

    case 'revealAssembly':
      return project(
        state,
        { kind: 'assembly', region: action.region, partKeys: action.partKeys ?? [] },
        { section: 'entretien', focusRegion: action.region, focusTopic: action.partKeys?.[0] ?? null, focusExplicit: true },
      );

    case 'focusRegion':
      return state.focusExplicit && state.focusRegion === action.region ? state : { ...state, focusRegion: action.region, focusTopic: null, focusExplicit: true };

    case 'status': {
      const patch = derive(state, topicForText(action.message ?? ''));
      if (patch.focusRegion === undefined || (patch.focusRegion === state.focusRegion && patch.focusTopic === state.focusTopic)) return state;
      return { ...state, ...patch };
    }

    case 'showDocument': {
      const { type: _type, ...doc } = action;
      const settled = state.settled ? null : findLoggedHit(state.searches, doc.url, doc.page);
      return project(state, { kind: 'document' }, { section: 'documents', document: doc, settled, searches: settled ? state.searches : [] });
    }

    case 'documentScan': {
      const search: DocSearch = { query: action.query, hits: action.hits, key: state.scanSeq + 1 };
      const searches = state.settled ? [search] : [...state.searches, search];
      return { ...state, section: 'documents', searches, settled: null, scanSeq: search.key };
    }

    case 'proposeActions': {
      const proposal: CtaState = { headline: action.headline, actions: action.actions };
      if (action.finding !== undefined) proposal.finding = action.finding;
      const words = [action.headline, action.finding, ...action.actions.map((a) => a.label), ...action.actions.flatMap((a) => a.patchTask?.parts.map((p) => p.label ?? '') ?? [])]
        .filter(Boolean)
        .join(' · ');
      return { ...state, proposal, ...derive(state, topicForText(words)) };
    }

    default:
      return state;
  }
}
