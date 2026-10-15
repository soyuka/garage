import { useCallback, useEffect, useMemo, useRef, useState, type MutableRefObject, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { isAuthenticated, login } from './auth';
import { ApiError } from './api';
import { liveApi, type FinderApi, type Trace } from './client';
import { partFamily } from './family';
import { resultSummary } from './followup';
import { historyEntries, type HistoryEntry } from './history';
import { MediaError, pickSharpest, prepareImage, sampleVideo, type Frame } from './media';
import { buildQuestion } from './question';
import { parseRef, type Route } from './route';
import { routeFile, type TextRoute } from './route-input';
import type { AgentPart, Category, Family, Narrowing, Registration, Vehicle, VinDecode } from './types';
import { isBusy } from './agent';
import { carVehicle, rememberCar } from './vehicles';
import { AgentQuestion, AgentStatus, PartSwitcher, WebTitleBlock } from './ui/Agent';
import { Dock, type QuickAction } from './ui/Dock';
import { CardResult, Entry, VinResult } from './ui/Entry';
import { SaveVehicle } from './ui/Garage';
import { History } from './ui/History';
import { useAsync, useMedia, useReducedMotion, useRoute, useWideEnough, type Async, type Navigate } from './ui/hooks';
import { Journal } from './ui/Journal';
import { useAgentTurn, type AgentTurn, type AskOptions } from './ui/useAgentTurn';
import { Sheet } from './ui/Sheet';
import { TitleBlock } from './ui/TitleBlock';
import { NarrowingTrace, QuestionCard } from './ui/Trace';
import { VehicleChip } from './ui/VehicleChip';

const FAMILY_VIEW: Record<string, string> = {
  front: 'VUE 3D — FREIN AVANT',
  rear: 'VUE 3D — FREIN ARRIÈRE',
};

const viewLabel = (family: Family, category: Category | null): string => {
  if (family.kind === 'filter') return `VUE 3D — ${(category?.label ?? 'filtre').toUpperCase()}`;
  if (family.kind === 'brake') return FAMILY_VIEW[family.axle] ?? 'VUE 3D';
  if ((family.kind === 'generic' || family.kind === 'mesh') && category) return `VUE 3D — ${category.label.toUpperCase()}`;
  return category ? category.label.toUpperCase() : 'PLANCHE';
};

const MAX_FRAMES = 3;
const PHOTO_INSTRUCTION = 'Identifie la pièce sur la photo.';
const SUGGESTIONS = [100259, 100260, 100261];
const SHEET_OVERLAY_MIN = 812;

export function App() {
  return <Finder api={liveApi} />;
}

// What the page shows next to the composer besides an agent turn.
type Panel =
  | { kind: 'none' }
  | { kind: 'vin'; decode: VinDecode }
  | { kind: 'card'; registration: Registration }
  | { kind: 'reading'; what: string }
  | { kind: 'login'; why: string }
  | { kind: 'error'; message: string };

// What a follow-up typed in the composer continues: the on-screen result and the open question.
export interface FollowUp {
  summary: string | null;
  pendingContext: string | null;
}

const routeRef = (route: Route): string => (route.view === 'entry' ? '' : route.ref);

function Finder({ api }: { api: FinderApi }) {
  const [route, navigate] = useRoute();
  const authed = isAuthenticated();
  const [panel, setPanel] = useState<Panel>({ kind: 'none' });
  const [journalOpen, setJournalOpen] = useState(false);
  const [slot, setSlot] = useState<HTMLDivElement | null>(null);
  const docked = useMedia('(min-width: 1280px)');
  const journalButton = useRef<HTMLButtonElement>(null);
  const trace = useRef<Trace | null>(null);
  const followUp = useRef<FollowUp | null>(null);
  const ref = routeRef(route);
  const parsed = ref === '' ? null : parseRef(ref);

  const vehicle = useAsync<Vehicle>(ref === '' ? null : ref, () => {
    if (parsed?.kind === 'car') return Promise.resolve(carVehicle(parsed.carId, parsed.carType));
    const t = trace.current ?? { rule: 'url', input: ref };
    trace.current = null;
    return api.vehicle(parsed?.kind === 'plate' ? parsed.plate : ref, t);
  });
  const v = vehicle.status === 'ok' ? vehicle.data : null;
  const categories = useAsync<Category[]>(v?.carId ? `${v.carId}-${v.carType}` : null, () => api.categories(v!.carId!, v!.carType));

  const turn = useAgentTurn(v, ref);
  const turnVehicle = turn.state.vehicle;
  const agentActive = turn.state.phase !== 'idle' || turn.needsLogin !== null;
  // An agent result keeps its own sheet (rationale, several parts) while its route names the part shown.
  const held = turn.state.held !== null;
  const agentOwns =
    agentActive && !held && (route.view !== 'result' || turn.state.parts.some((p) => p.kind === 'catalog' && p.categoryId === route.categoryId));

  const categoryId = route.view === 'result' && !agentOwns ? route.categoryId : null;
  const narrowing = useAsync<Narrowing>(v?.carId && categoryId ? `${v.carId}-${categoryId}` : null, () => {
    const t = trace.current ?? { rule: 'url', input: String(categoryId) };
    trace.current = null;
    return api.narrowed(v!.carId!, v!.carType, categoryId!, v!.make, { ...t, plate: v!.plate });
  });

  // A route the turn does not own (back button, history link, typed URL) closes a finished turn.
  useEffect(() => {
    if (agentActive && !agentOwns && !isBusy(turn.state)) turn.reset();
  }, [route]);

  // The turn's first part replaces the result it was continuing: leave that result's route.
  useEffect(() => {
    if (agentActive && !held && !agentOwns && route.view === 'result' && turn.state.parts.length > 0) navigate({ view: 'vehicle', ref: route.ref });
  }, [held, turn.state.parts]);

  const history = useAsync<HistoryEntry[]>(route.view === 'entry' && !agentActive && authed ? 'history' : null, async () => historyEntries(await api.journal()));

  useEffect(() => {
    if (!turnVehicle) return;
    const next = rememberCar(turnVehicle);
    if (next !== ref && !(v?.carId === turnVehicle.carId && v.carType === turnVehicle.carType)) navigate({ view: 'vehicle', ref: next });
    // Adopting the agent's vehicle must not restart the turn: only the frame drives this.
  }, [turnVehicle]);

  const goVehicle = useCallback(
    (plate: string, rule: string, input: string) => {
      turn.reset();
      setPanel({ kind: 'none' });
      trace.current = { rule, input };
      navigate({ view: 'vehicle', ref: plate });
    },
    [turn, navigate],
  );

  const askAgent = (text: string, options: AskOptions = {}) => {
    setPanel({ kind: 'none' });
    const context = followUp.current;
    turn.ask(text, {
      ...options,
      summary: options.summary ?? context?.summary ?? null,
      pendingContext: options.pendingContext ?? context?.pendingContext ?? null,
      continued: options.continued ?? turn.state.parts.length > 0,
      held: route.view === 'result' && !agentOwns && v?.carId ? { carId: v.carId, carType: v.carType } : null,
    });
  };

  const onText = (r: TextRoute, typed: string) => {
    switch (r.kind) {
      case 'empty':
        return;
      case 'plate':
        return goVehicle(r.plate, r.format === 'siv' ? 'plate' : 'plate-fni', typed);
      case 'vin':
        setPanel({ kind: 'reading', what: 'Décodage du VIN…' });
        turn.reset();
        if (route.view !== 'entry') navigate({ view: 'entry' });
        api
          .vin(r.vin, { rule: 'vin', input: typed })
          .then((decode) => setPanel({ kind: 'vin', decode }))
          .catch((e: unknown) => setPanel({ kind: 'error', message: e instanceof ApiError ? e.message : 'Décodage impossible.' }));
        return;
      case 'category':
        if (!v) return askAgent(typed);
        turn.reset();
        setPanel({ kind: 'none' });
        trace.current = { rule: 'category', input: typed };
        navigate({ view: 'result', ref, categoryId: r.category.id, clusterId: null });
        return;
      case 'reference':
        return askAgent(r.reference, { intent: 'reference' });
      case 'text':
        return askAgent(r.text);
    }
  };

  const readRegistration = (registration: Registration) => {
    turn.reset();
    if (route.view !== 'entry') navigate({ view: 'entry' });
    setPanel({ kind: 'card', registration });
  };

  const onFiles = async (files: File[]) => {
    const first = files[0];
    if (!first) return;
    const kind = routeFile(first);
    if (kind.kind === 'unsupported') {
      setPanel({ kind: 'error', message: kind.reason === 'size' ? 'PDF trop lourd : 10 Mo au maximum.' : 'Format non pris en charge : une photo, une courte vidéo ou un PDF.' });
      return;
    }
    if (!isAuthenticated()) {
      setPanel({ kind: 'login', why: 'La lecture d’une photo ou d’un document demande une connexion. Le fichier est lu puis oublié : il n’est jamais stocké.' });
      return;
    }
    try {
      if (kind.kind === 'pdf') {
        setPanel({ kind: 'reading', what: 'Lecture de la carte grise…' });
        readRegistration(await api.extractRegistration(first));
        return;
      }
      setPanel({ kind: 'reading', what: kind.kind === 'video' ? 'Extraction de trois images de la vidéo…' : 'Préparation de la photo…' });
      let frames: Frame[];
      if (kind.kind === 'video') frames = await sampleVideo(first);
      else frames = await Promise.all(files.filter((f) => routeFile(f).kind === 'image').slice(0, MAX_FRAMES).map(prepareImage));
      frames = frames.slice(0, MAX_FRAMES);
      const sharpest = frames[pickSharpest(frames.map((f) => f.score))]!;
      setPanel({ kind: 'reading', what: 'Lecture de la photo…' });
      const intake = await api.photoIntake(sharpest.blob);
      if (intake.kind === 'carte_grise') return readRegistration(intake.registration);
      if (intake.kind === 'part') return askAgent(PHOTO_INSTRUCTION, { intent: 'photo', images: frames.map((f) => f.dataUrl), photoHints: intake.part });
      if (intake.kind === 'build_label') {
        const code = intake.buildLabel.opr ?? intake.buildLabel.printedCodes.join(' · ');
        if (code === '') {
          setPanel({ kind: 'error', message: 'Aucun code lisible sur cette vignette. Rapprochez-vous, ou tapez le numéro d’organisation (souvent après « DAM »).' });
          return;
        }
        return askAgent(`Code de fabrication relevé sur la vignette : ${code}`, { buildLabel: intake.buildLabel });
      }
      setPanel({ kind: 'error', message: 'Ni une pièce, ni une carte grise, ni une vignette constructeur sur cette photo. Cadrez de plus près.' });
    } catch (e) {
      const message =
        e instanceof MediaError
          ? e.message
          : e instanceof ApiError
            ? e.kind === 'auth'
              ? 'Session expirée : reconnectez-vous.'
              : e.kind === 'upstream'
                ? 'La photo n’a pas pu être lue. Essayez une photo plus nette, bien éclairée.'
                : e.message
            : 'Lecture impossible.';
      setPanel({ kind: 'error', message });
    }
  };

  const closeJournal = useCallback(() => {
    setJournalOpen(false);
    journalButton.current?.focus();
  }, []);

  const busyReading = panel.kind === 'reading';
  const entry = (route.view === 'entry' && !agentActive) || vehicle.status === 'error';
  const cats = categories.status === 'ok' ? categories.data : null;

  const quick: QuickAction[] = [];
  if (!entry && route.view === 'vehicle' && !agentActive && cats) {
    for (const id of SUGGESTIONS) {
      const c = cats.find((k) => k.id === id);
      if (c) quick.push({ label: c.label, run: () => onText({ kind: 'category', category: c }, c.label) });
    }
  }

  const note =
    panel.kind === 'reading' ? (
      <p className="dock-line" role="status">
        <span className="agent-status">
          <span className="agent-dot" aria-hidden="true" />
          {panel.what}
        </span>
      </p>
    ) : panel.kind === 'error' ? (
      <p className="field-error" role="alert">
        {panel.message}
      </p>
    ) : panel.kind === 'login' ? (
      <div className="dock-status">
        <p className="dock-line">{panel.why}</p>
        <button type="button" className="btn pri" onClick={() => void login()}>
          Se connecter
        </button>
      </div>
    ) : null;

  const header = (
    <header className="top">
      <a
        className="logo"
        href="#/"
        onClick={() => {
          turn.reset();
          setPanel({ kind: 'none' });
        }}
      >
        finder
      </a>
      {v && !entry && (
        <div className="top-vehicle">
          <VehicleChip
            vehicle={v}
            onChange={() => {
              turn.reset();
              setPanel({ kind: 'none' });
              navigate({ view: 'entry' });
            }}
          />
          {v.carId !== null && <SaveVehicle key={ref} vehicle={v} api={api} />}
        </div>
      )}
      <button ref={journalButton} type="button" className="journal-btn" aria-expanded={journalOpen} onClick={() => setJournalOpen((o) => !o)}>
        Journal
      </button>
    </header>
  );

  const dock = (
    <Dock
      status={<AgentStatus state={turn.state} needsLogin={turn.needsLogin} onCancel={turn.cancel} onRetry={() => turn.ask(turn.state.instruction)} onSignIn={turn.signIn} />}
      note={note}
      slot={setSlot}
      quick={quick}
      categories={entry ? null : cats}
      loading={categories.status === 'loading'}
      busy={busyReading}
      hasVehicle={!entry && v !== null}
      autoFocus={entry || (route.view === 'vehicle' && !agentActive)}
      onText={onText}
      onFiles={(f) => void onFiles(f)}
    />
  );

  const journal = <Journal api={api} open={journalOpen} authed={authed} docked={docked} onClose={closeJournal} />;
  const shell = (main: ReactNode, mode: 'entry' | 'work') => (
    <div className={['app', `app--${mode}`, journalOpen && docked && 'app--journal'].filter(Boolean).join(' ')}>
      {header}
      <main className="main">{main}</main>
      {dock}
      {journal}
    </div>
  );

  if (entry) {
    const error =
      vehicle.status === 'error'
        ? vehicle.error.kind === 'notFound'
          ? `La plaque ${ref} est introuvable. Vérifiez-la, ou envoyez une photo de la carte grise.`
          : vehicle.error.message
        : null;
    return shell(
      <div className="entry-main">
        <Entry>
          {error && (
            <p className="field-error" role="alert">
              {error}
            </p>
          )}
          {panel.kind === 'vin' && <VinResult decode={panel.decode} />}
          {panel.kind === 'vin' && panel.decode.valid && <p className="entry-note">Ajoutez la plaque, ou une photo de la carte grise : le VIN seul ne donne pas le type exact.</p>}
          {panel.kind === 'card' && <CardResult data={panel.registration} onPlate={(p) => goVehicle(p, 'carte-grise', p)} onReset={() => setPanel({ kind: 'none' })} />}
        </Entry>
        {authed && (
          <History
            entries={history.status === 'ok' ? history.data : []}
            loading={history.status === 'loading'}
            onOpen={(e) => {
              turn.reset();
              setPanel({ kind: 'none' });
              trace.current = { rule: 'history', input: e.input };
            }}
          />
        )}
      </div>,
      'entry',
    );
  }

  return shell(
    agentOwns ? (
      <AgentWorkspace api={api} route={route} navigate={navigate} vehicle={v} categories={cats ?? []} turn={turn} slot={slot} followUp={followUp} onAsk={askAgent} />
    ) : (
      <Workspace
        api={api}
        route={route as Exclude<Route, { view: 'entry' }>}
        vehicle={vehicle}
        categories={categories}
        narrowing={narrowing}
        navigate={navigate}
        slot={slot}
        followUp={followUp}
        turn={held ? turn : null}
        onAsk={askAgent}
      />
    ),
    'work',
  );
}

function useFollowUp(followUp: MutableRefObject<FollowUp | null>, value: FollowUp | null) {
  useEffect(() => {
    followUp.current = value;
  }, [followUp, value?.summary, value?.pendingContext]);
  useEffect(
    () => () => {
      followUp.current = null;
    },
    [followUp],
  );
}

interface WorkspaceProps {
  api: FinderApi;
  route: Exclude<Route, { view: 'entry' }>;
  vehicle: Async<Vehicle> & { retry: () => void };
  categories: Async<Category[]> & { retry: () => void };
  narrowing: Async<Narrowing> & { retry: () => void };
  navigate: Navigate;
  slot: HTMLElement | null;
  followUp: MutableRefObject<FollowUp | null>;
  // A follow-up turn holding this result on screen until it shows parts of its own.
  turn: AgentTurn | null;
  onAsk: (text: string, options?: AskOptions) => void;
}

function Workspace({ api, route, vehicle, categories, narrowing, navigate, slot, followUp, turn, onAsk }: WorkspaceProps) {
  const reducedMotion = useReducedMotion();
  const [wrapRef, wide] = useWideEnough(SHEET_OVERLAY_MIN);
  const v = vehicle.status === 'ok' ? vehicle.data : null;

  const category = route.view === 'result' && categories.status === 'ok' ? (categories.data.find((c) => c.id === route.categoryId) ?? null) : null;
  const n = narrowing.status === 'ok' ? narrowing.data : null;
  const clusters = n?.clusters ?? [];
  const cluster = clusters.find((c) => c.id === (route.view === 'result' ? route.clusterId : null)) ?? clusters[0] ?? null;
  const confirmed = clusters.length === 1 || (route.view === 'result' && route.clusterId !== null && cluster?.id === route.clusterId);
  const question = useMemo(() => (n && category ? buildQuestion(n, category.label) : null), [n, category]);
  const showResult = route.view === 'result' && category !== null && n !== null && n.totalArticles > 0;
  const family: Family = category && (showResult || narrowing.status === 'loading') ? partFamily(category, cluster?.dimensions ?? []) : { kind: 'none' };

  useFollowUp(
    followUp,
    showResult && category && n ? { summary: resultSummary(category.label, n, route.view === 'result' ? route.clusterId : null), pendingContext: question && !confirmed ? `question affichée : ${question.text}` : null } : null,
  );

  if (!v) {
    return (
      <div className="work work--loading" aria-busy="true">
        <div className="sheet-wrap">
          <div className="sheet">
            <div className="sheet-status" role="status">
              <p>
                Identification de <span className="m">{route.ref}</span>…
              </p>
            </div>
          </div>
        </div>
      </div>
    );
  }

  const overlay = wide ? 520 : null;
  const loading = narrowing.status === 'loading' || (route.view === 'result' && categories.status === 'loading');

  let status: ReactNode = null;
  let placeholder: ReactNode = null;
  if (v.carId === null) {
    placeholder = <p className="sheet-message">Ce véhicule n’a pas de type catalogue (kType) : impossible d’en lister les pièces.</p>;
  } else if (route.view === 'vehicle') {
    placeholder = (
      <div className="sheet-message">
        <p className="sheet-big">Quelle pièce ?</p>
        <p>Tapez-la dans le champ en bas : « filtre à huile », « disque de frein », une référence, ou envoyez une photo de la pièce.</p>
      </div>
    );
  } else if (categories.status === 'error') {
    status = <ErrorNote message={categories.error.message} onRetry={categories.retry} />;
  } else if (route.view === 'result' && categories.status === 'ok' && !category) {
    placeholder = <p className="sheet-message">Cette catégorie n’existe pas pour ce véhicule. Cherchez la pièce dans le champ en bas.</p>;
  } else if (narrowing.status === 'error') {
    status = <ErrorNote message={narrowing.error.message} onRetry={narrowing.retry} />;
    placeholder = <p className="sheet-big">{category?.label}</p>;
  } else if (n && n.totalArticles === 0) {
    status = <p className="sheet-message">Aucune pièce de cette catégorie n’est référencée pour ce véhicule.</p>;
  }
  if (loading && family.kind !== 'none') status = <p className="sheet-loading" role="status">Affinage des références…</p>;

  const traceNode = route.view === 'result' && narrowing.status !== 'idle' && narrowing.status !== 'error' ? <NarrowingTrace narrowing={narrowing} remaining={confirmed ? 1 : null} cluster={cluster} /> : null;
  const block = showResult ? <TitleBlock vehicle={v} category={category} narrowing={n} cluster={cluster} confirmed={confirmed} family={family} api={api} /> : loading && route.view === 'result' ? <TitleSkeleton /> : null;

  const ask = turn?.state.ask ?? null;
  const waiting = turn !== null && isBusy(turn.state);

  return (
    <div className={waiting ? 'work work--held' : 'work'} aria-busy={waiting || undefined}>
      <div ref={wrapRef} className={wide ? 'sheet-wrap' : 'sheet-wrap sheet-wrap--stacked'}>
        <Sheet
          family={family}
          viewLabel={viewLabel(family, category)}
          dimensions={cluster?.dimensions ?? []}
          ghost={loading || !cluster}
          reducedMotion={reducedMotion}
          overlayWidth={block ? overlay : null}
          placeholder={placeholder}
          status={status}
          notes={wide ? traceNode : null}
        >
          {wide && block}
        </Sheet>
        {!wide && traceNode}
        {!wide && block}
      </div>
      {slot && ask && createPortal(<AgentQuestion ask={ask} disabled={waiting} onAnswer={(value) => onAsk(value, { pendingContext: ask.context, continued: true })} />, slot)}
      {slot &&
        !ask &&
        question &&
        createPortal(<QuestionCard question={question} confirmed={confirmed} selected={cluster?.id ?? null} onAnswer={(id) => route.view === 'result' && navigate({ ...route, clusterId: id }, { replace: true })} />, slot)}
    </div>
  );
}

function ErrorNote({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="error-note" role="alert">
      <p>{message}</p>
      <button type="button" className="btn" onClick={onRetry}>
        Réessayer
      </button>
    </div>
  );
}

function TitleSkeleton() {
  return (
    <div className="tb tb--skeleton" aria-hidden="true">
      <div className="c s3"><span className="l">Désignation</span><span className="sk" style={{ width: '46%' }} /></div>
      <div className="c s3"><span className="l">Réf. OE</span><span className="sk sk--big" style={{ width: '62%' }} /></div>
      <div className="c s3"><span className="l">Équivalences</span><span className="sk" style={{ width: '88%' }} /></div>
      <div className="c s2"><span className="l">Véhicule</span><span className="sk" style={{ width: '70%' }} /></div>
      <div className="c r"><span className="l">kType</span><span className="sk" style={{ width: '50%' }} /></div>
      <div className="c s2 nb"><span className="l">Cotes</span><span className="sk" style={{ width: '80%' }} /></div>
      <div className="c r nb"><span className="l">Source</span><span className="sk" style={{ width: '60%' }} /></div>
    </div>
  );
}

interface AgentWorkspaceProps {
  api: FinderApi;
  route: Route;
  navigate: Navigate;
  vehicle: Vehicle | null;
  categories: Category[];
  turn: AgentTurn;
  slot: HTMLElement | null;
  followUp: MutableRefObject<FollowUp | null>;
  onAsk: (text: string, options?: AskOptions) => void;
}

function partCategory(part: AgentPart, categories: Category[]): Category {
  const known = part.kind === 'catalog' ? categories.find((c) => c.id === part.categoryId) : undefined;
  return known
    ? { ...known, label: part.label }
    : { id: part.kind === 'catalog' ? part.categoryId : 0, name: part.label, path: part.label, segments: [part.label], label: part.label, leaf: true };
}

function AgentWorkspace({ api, route, navigate, vehicle, categories, turn, slot, followUp, onAsk }: AgentWorkspaceProps) {
  const reducedMotion = useReducedMotion();
  const [wrapRef, wide] = useWideEnough(SHEET_OVERLAY_MIN);
  const { state } = turn;
  const [webKey, setWebKey] = useState<string | null>(null);
  const busy = isBusy(state);
  const ref = routeRef(route);

  // A catalogue part's route is its sheet: shareable, and a reload re-reads the cached narrowing.
  const routed = route.view === 'result' ? state.parts.find((p) => p.kind === 'catalog' && p.categoryId === route.categoryId) : undefined;
  const part = state.parts.find((p) => p.key === webKey) ?? routed ?? state.parts[0] ?? null;
  const category = part ? partCategory(part, categories) : null;
  const n = part?.kind === 'catalog' ? part.narrowing : null;
  const clusters = n?.clusters ?? [];
  const chosen = route.view === 'result' && routed === part ? route.clusterId : null;
  const cluster = clusters.find((c) => c.id === chosen) ?? clusters[0] ?? null;
  const confirmed = clusters.length === 1 || (chosen !== null && cluster?.id === chosen);
  const question = useMemo(() => (n && category ? buildQuestion(n, category.label) : null), [n, category]);
  const family: Family = category && part ? partFamily(category, cluster?.dimensions ?? []) : { kind: 'none' };
  const overlay = wide && part ? 520 : null;

  const openPart = (p: AgentPart, clusterId: string | null = null) => {
    if (p.kind === 'web') {
      setWebKey(p.key);
      if (ref !== '') navigate({ view: 'vehicle', ref }, { replace: true });
      return;
    }
    setWebKey(null);
    if (ref !== '') navigate({ view: 'result', ref, categoryId: p.categoryId, clusterId }, { replace: true });
  };

  const first = state.parts[0];
  useEffect(() => {
    if (first && first.kind === 'catalog' && webKey === null && route.view === 'vehicle' && ref !== '') navigate({ view: 'result', ref, categoryId: first.categoryId, clusterId: null }, { replace: true });
  }, [first?.key, ref, route.view]);

  useFollowUp(
    followUp,
    part === null
      ? { summary: null, pendingContext: state.ask?.context ?? null }
      : {
          summary: part.kind === 'catalog' && category && n ? resultSummary(category.label, n, chosen) : part.kind === 'web' ? `${part.label} : ${part.brand} ${part.reference} (source web, hors catalogue)` : null,
          pendingContext: state.ask?.context ?? (question && !confirmed ? `question affichée : ${question.text}` : null),
        },
  );

  let placeholder: ReactNode = null;
  if (!part && turn.needsLogin !== null) {
    placeholder = (
      <div className="sheet-message">
        <p className="sheet-big">Connexion requise</p>
        <p>L’agent cherche la pièce pour un compte connecté. Connectez-vous au-dessus du champ : la demande repart au retour.</p>
      </div>
    );
  } else if (!part) {
    placeholder = busy ? (
      <div className="sheet-message">
        <p className="sheet-big">Recherche en cours</p>
        <p>{vehicle ? 'L’agent identifie la ou les pièces de votre demande dans le catalogue de ce véhicule.' : 'L’agent identifie d’abord le véhicule, puis la ou les pièces de votre demande.'}</p>
      </div>
    ) : (
      <div className="sheet-message">
        <p className="sheet-big">{state.ask ? 'Une précision' : 'Aucune pièce'}</p>
        <p>{state.ask ? 'Répondez à la question au-dessus du champ pour que l’agent continue.' : 'Reformulez la demande dans le champ en bas.'}</p>
      </div>
    );
  }

  const block = !part ? null : part.kind === 'web' ? (
    <WebTitleBlock vehicle={vehicle} part={part} />
  ) : category && n && vehicle ? (
    <TitleBlock vehicle={vehicle} category={category} narrowing={n} cluster={cluster} confirmed={confirmed} family={family} api={api} rationale={part.rationale} />
  ) : null;
  const traceNode = n ? <NarrowingTrace narrowing={{ status: 'ok', data: n }} remaining={confirmed ? 1 : null} cluster={cluster} /> : null;

  return (
    <div className="work">
      <div ref={wrapRef} className={['sheet-wrap', state.parts.length > 1 && 'sheet-wrap--parts', !wide && 'sheet-wrap--stacked'].filter(Boolean).join(' ')}>
        {state.parts.length > 1 && part && <PartSwitcher parts={state.parts} selected={part.key} onSelect={(key) => { const p = state.parts.find((x) => x.key === key); if (p) openPart(p); }} />}
        <Sheet
          family={family}
          viewLabel={viewLabel(family, category)}
          dimensions={cluster?.dimensions ?? []}
          ghost={!cluster || state.stale}
          reducedMotion={reducedMotion}
          overlayWidth={overlay}
          placeholder={placeholder}
          status={null}
          notes={wide ? traceNode : null}
        >
          {wide && block}
        </Sheet>
        {!wide && traceNode}
        {!wide && block}
      </div>
      {slot &&
        createPortal(
          <>
            {state.ask && <AgentQuestion ask={state.ask} disabled={busy} onAnswer={(value) => onAsk(value, { pendingContext: state.ask?.context ?? null, continued: true })} />}
            {!state.ask && question && part && <QuestionCard question={question} confirmed={confirmed} selected={cluster?.id ?? null} onAnswer={(id) => openPart(part, id)} />}
          </>,
          slot,
        )}
    </div>
  );
}
