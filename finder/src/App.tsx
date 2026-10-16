import { useCallback, useEffect, useMemo, useReducer, useRef, useState, type MutableRefObject, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { isAuthenticated, login } from './auth';
import { ApiError } from './api';
import { liveApi, newTrace, type FinderApi, type Trace } from './client';
import { partFamily } from './family';
import { sheetPhoto } from './photo';
import { resultSummary } from './followup';
import { historyEntries, historyFor, type HistoryEntry } from './history';
import { STARTERS } from './home';
import { listMyVehicles, pickTarget, type MyVehicle } from './my-vehicles';
import { addAttachments } from './attachments';
import { buildCodeFor, garageMatch, rememberBuildCode } from './buildcode';
import { MediaError } from './media';
import { readAttachment } from './read-attachment';
import { runSubmission, type PendingAsk } from './submit';
import { buildQuestion } from './question';
import { relaunchText } from './result';
import { parseRef, type ClusterChoice, type Route } from './route';
import type { TextRoute } from './route-input';
import type { AgentPart, AgentVehicle, BuildCode, Category, Family, Narrowing, Vehicle, VinDecode } from './types';
import { isBusy } from './agent';
import { rememberCar, resolveCar } from './vehicles';
import { pickChoice, questionOpen, selectVariant } from './variants';
import { AgentQuestion, AgentStatus, PartSwitcher, WebTitleBlock } from './ui/Agent';
import { Dock, type QuickAction } from './ui/Dock';
import { VinResult } from './ui/Entry';
import { SaveVehicle } from './ui/Garage';
import { History } from './ui/History';
import { Home, Showcase } from './ui/Home';
import { useAsync, useMedia, useReducedMotion, useRoute, useWideEnough, type Async, type Navigate } from './ui/hooks';
import { Journal } from './ui/Journal';
import { useAgentTurn, type AgentTurn, type AskOptions } from './ui/useAgentTurn';
import { Sheet } from './ui/Sheet';
import { ResultActions } from './ui/ResultActions';
import { TitleBlock } from './ui/TitleBlock';
import { QuestionCard } from './ui/Trace';
import { VehicleChip } from './ui/VehicleChip';

const FAMILY_VIEW: Record<string, string> = {
  front: 'VUE 3D — FREIN AVANT',
  rear: 'VUE 3D — FREIN ARRIÈRE',
};

const viewLabel = (family: Family, category: Category | null): string => {
  if (family.kind === 'filter') return `VUE 3D — ${(category?.label ?? 'filtre').toUpperCase()}`;
  if (family.kind === 'brake') return FAMILY_VIEW[family.axle] ?? 'VUE 3D';
  if (family.kind === 'mesh' && category) return `VUE 3D — ${category.label.toUpperCase()}`;
  return category ? category.label.toUpperCase() : '';
};

const SHEET_OVERLAY_MIN = 812;
const BLOCK_WIDTH = 520;
const BLOCK_WIDTH_WIDE = 600;

export function App() {
  return <Finder api={liveApi} />;
}

// What the page shows next to the composer besides an agent turn.
type Panel =
  | { kind: 'none' }
  | { kind: 'vin'; decode: VinDecode }
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
  const [attachments, setAttachments] = useState<File[]>([]);
  // The turn typed with a carte grise, run once the vehicle it opens is loaded.
  const [queued, setQueued] = useState<{ ref: string; ask: PendingAsk | null } | null>(null);
  const [journalOpen, setJournalOpen] = useState(false);
  const [slot, setSlot] = useState<HTMLDivElement | null>(null);
  const [actionsSlot, setActionsSlot] = useState<HTMLDivElement | null>(null);
  const docked = useMedia('(min-width: 1280px)');
  const journalButton = useRef<HTMLButtonElement>(null);
  const trace = useRef<Trace | null>(null);
  const followUp = useRef<FollowUp | null>(null);
  const ref = routeRef(route);
  const parsed = ref === '' ? null : parseRef(ref);

  const vehicle = useAsync<Vehicle>(ref === '' ? null : ref, () => {
    if (parsed?.kind === 'car') return resolveCar(parsed.carId, parsed.carType, api.car);
    const t = trace.current ?? undefined;
    trace.current = null;
    return api.vehicle(parsed?.kind === 'plate' ? parsed.plate : ref, t);
  });
  const v = vehicle.status === 'ok' ? vehicle.data : null;
  const categories = useAsync<Category[]>(v?.carId ? `${v.carId}-${v.carType}` : null, () => api.categories(v!.carId!, v!.carType));

  const [codeVersion, codeChanged] = useReducer((n: number) => n + 1, 0);
  const buildCode = useMemo(() => (v ? buildCodeFor(v) : null), [v, codeVersion]);
  const turn = useAgentTurn(v, ref, buildCode);
  const turnVehicle = turn.state.vehicle;
  const agentActive = turn.state.phase !== 'idle' || turn.needsLogin !== null;
  // An agent result keeps its own sheet (rationale, several parts) while its route names the part shown.
  const held = turn.state.held !== null;
  const agentOwns =
    agentActive && !held && (route.view !== 'result' || turn.state.parts.some((p) => p.kind === 'catalog' && p.categoryId === route.categoryId));

  const categoryId = route.view === 'result' && !agentOwns ? route.categoryId : null;
  const productGroupId = route.view === 'result' ? route.productGroupId : null;
  const narrowing = useAsync<Narrowing>(v?.carId && categoryId ? `${v.carId}-${categoryId}-${productGroupId ?? ''}` : null, () => {
    const t = trace.current;
    trace.current = null;
    return api.narrowed(v!.carId!, v!.carType, categoryId!, v!.make, productGroupId, t ? { ...t, plate: v!.plate, vehicle: v } : undefined);
  });
  // What the user typed for the search a history row reopens: a restored mixed category relaunches it.
  const [restoredInput, setRestoredInput] = useState<string | null>(null);

  // A route the turn does not own (back button, history link, typed URL) closes a finished turn.
  useEffect(() => {
    if (agentActive && !agentOwns && !isBusy(turn.state)) turn.reset();
  }, [route]);

  // The turn's first part replaces the result it was continuing: leave that result's route.
  useEffect(() => {
    if (agentActive && !held && !agentOwns && route.view === 'result' && turn.state.parts.length > 0) navigate({ view: 'vehicle', ref: route.ref });
  }, [held, turn.state.parts]);

  const history = useAsync<HistoryEntry[]>((route.view === 'entry' || route.view === 'vehicle') && !agentActive && authed ? 'history' : null, async () => historyEntries(await api.history()));
  const mine = useAsync<MyVehicle[]>(route.view === 'entry' && !agentActive && authed ? 'mine' : null, listMyVehicles);

  // The decoded code de fabrication is the car's: kept for the tab, and on its garage row when it has one.
  useEffect(() => {
    const code = turn.state.buildCode;
    const car = turnVehicle ?? v;
    if (!code || !car) return;
    rememberBuildCode(car, code);
    codeChanged();
    if (authed) void recordBuildCode(api, car, code);
  }, [turn.state.buildCode]);

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
      trace.current = newTrace(rule, input);
      navigate({ view: 'vehicle', ref: plate });
    },
    [turn, navigate],
  );

  // The vehicle in context is the route's: opening a car scopes the composer to it, leaving it unscopes.
  const openVehicle = (ref: string) => {
    turn.reset();
    setPanel({ kind: 'none' });
    trace.current = null;
    navigate(ref === '' ? { view: 'entry' } : { view: 'vehicle', ref });
  };

  const pickMine = (picked: MyVehicle) => {
    const target = pickTarget(picked);
    openVehicle(target.car ? rememberCar(target.car) : target.ref);
  };

  const [plateRequest, setPlateRequest] = useState(0);
  const askPlate = () => setPlateRequest((n) => n + 1);
  const [labelRequest, setLabelRequest] = useState(0);
  const askLabel = () => setLabelRequest((n) => n + 1);

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

  const onText = (r: TextRoute, typed: string): void | Promise<boolean> => {
    if (attachments.length > 0) return submitWithFiles(r);
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
          .vin(r.vin, newTrace('vin', typed))
          .then((decode) => setPanel({ kind: 'vin', decode }))
          .catch((e: unknown) => setPanel({ kind: 'error', message: e instanceof ApiError ? e.message : 'Décodage du VIN impossible. Vérifiez la case E de la carte grise.' }));
        return;
      case 'reference':
        return askAgent(r.reference, { intent: 'reference' });
      case 'text':
        return askAgent(r.text);
    }
  };

  const attach = (files: File[]) => {
    const next = addAttachments(attachments, files);
    setAttachments(next.files);
    setPanel(next.error ? { kind: 'error', message: next.error } : { kind: 'none' });
  };
  const detach = (index: number) => setAttachments((current) => current.filter((_, i) => i !== index));

  const askPending = (ask: PendingAsk) => {
    const { text, ...options } = ask;
    askAgent(text, options);
  };

  // Chercher with attachments: read them, then open the car a carte grise names and run the typed search on it.
  // Returns false when the typed text must stay in the composer.
  const submitWithFiles = async (route: TextRoute): Promise<boolean> => {
    if (!isAuthenticated()) {
      setPanel({ kind: 'login', why: 'Connexion requise pour lire un fichier. Le fichier n’est pas conservé.' });
      return false;
    }
    try {
      const outcome = await runSubmission(
        { route, files: attachments },
        { api, read: (file) => readAttachment(api, file), status: (what) => setPanel(what ? { kind: 'reading', what } : { kind: 'none' }) },
      );
      if (outcome.kind === 'stopped') {
        setPanel({ kind: 'error', message: outcome.message });
        return !outcome.keepText;
      }
      setAttachments([]);
      if (outcome.kind === 'ask') {
        askPending(outcome.ask);
        return true;
      }
      turn.reset();
      trace.current = null;
      setQueued({ ref: outcome.ref, ask: outcome.ask });
      if (!outcome.saved) setPanel({ kind: 'error', message: 'Enregistrement au garage impossible. Le véhicule reste utilisable ici.' });
      navigate({ view: 'vehicle', ref: outcome.ref });
      return true;
    } catch (e) {
      const message =
        e instanceof MediaError
          ? e.message
          : e instanceof ApiError
            ? e.kind === 'auth'
              ? 'Session expirée : reconnectez-vous.'
              : e.kind === 'upstream'
                ? 'Lecture de la photo impossible. Reprenez-la plus nette, avec plus de lumière.'
                : e.message
            : 'Lecture du fichier impossible.';
      setPanel({ kind: 'error', message });
      return false;
    }
  };

  useEffect(() => {
    if (!queued || queued.ref !== ref || !v?.carId) return;
    setQueued(null);
    if (queued.ask) askPending(queued.ask);
    else setPanel((p) => (p.kind === 'reading' ? { kind: 'none' } : p));
  }, [queued, ref, v]);

  const closeJournal = useCallback(() => {
    setJournalOpen(false);
    journalButton.current?.focus();
  }, []);

  const busyReading = panel.kind === 'reading';
  const entry = (route.view === 'entry' && !agentActive) || vehicle.status === 'error';
  // Leaving the home unmounts its composer: the docked one takes the focus so typing carries on.
  const wasEntry = useRef(entry);
  const handoff = wasEntry.current && !entry;
  useEffect(() => {
    wasEntry.current = entry;
  }, [entry]);
  const reducedMotion = useReducedMotion();
  const cats = categories.status === 'ok' ? categories.data : null;

  const quick: QuickAction[] = [];
  if (!entry && route.view === 'result' && v?.carId && !isBusy(turn.state)) quick.push({ label: 'Chercher une autre pièce sur ce véhicule', run: () => openVehicle(ref) });

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
          <VehicleChip vehicle={v} buildCode={buildCode} onChange={() => openVehicle('')} />
          {v.carId !== null && <SaveVehicle key={ref} vehicle={v} buildCode={buildCode} api={api} />}
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
      actions={setActionsSlot}
      quick={quick}
      context={v}
      onClearContext={() => openVehicle('')}
      categories={cats}
      loading={categories.status === 'loading'}
      busy={busyReading}
      hasVehicle={v !== null}
      autoFocus={handoff || (route.view === 'vehicle' && !agentActive)}
      plateRequest={plateRequest}
      labelRequest={labelRequest}
      onText={onText}
      attachments={attachments}
      onAttach={attach}
      onDetach={detach}
    />
  );

  const openHistory = (entry: HistoryEntry) => {
    turn.reset();
    setPanel({ kind: 'none' });
    trace.current = null;
    setRestoredInput(entry.input);
  };
  const historyList = history.status === 'ok' ? history.data : [];

  const journal = <Journal api={api} open={journalOpen} authed={authed} docked={docked} onClose={closeJournal} />;
  const shell = (main: ReactNode, mode: 'entry' | 'work') => (
    <div className={['app', `app--${mode}`, journalOpen && docked && 'app--journal'].filter(Boolean).join(' ')}>
      {header}
      <main className="main">{main}</main>
      {mode === 'work' && dock}
      {journal}
    </div>
  );

  if (entry) {
    const error =
      vehicle.status === 'error'
        ? vehicle.error.kind === 'notFound'
          ? `Plaque ${ref} introuvable. Vérifiez-la ou envoyez la carte grise en photo.`
          : vehicle.error.message
        : null;
    const showHistory = authed && (history.status === 'loading' || historyList.length > 0);
    return shell(
      <Home
        starters={STARTERS}
        mine={mine.status === 'ok' ? mine.data : []}
        mineLoading={authed && mine.status === 'loading'}
        busy={busyReading}
        note={note}
        panel={
          error || panel.kind === 'vin' ? (
            <>
              {error && (
                <p className="field-error" role="alert">
                  {error}
                </p>
              )}
              {panel.kind === 'vin' && <VinResult decode={panel.decode} />}
            </>
          ) : null
        }
        below={showHistory ? <History api={api} entries={historyList} loading={history.status === 'loading'} onOpen={openHistory} /> : <Showcase reducedMotion={reducedMotion} />}
        onPick={pickMine}
        onText={onText}
        attachments={attachments}
        onAttach={attach}
        onDetach={detach}
      />,
      'entry',
    );
  }

  return shell(
    agentOwns ? (
      <AgentWorkspace api={api} route={route} navigate={navigate} vehicle={v} categories={cats ?? []} turn={turn} slot={slot} actionsSlot={actionsSlot} followUp={followUp} onAsk={askAgent} onPlate={askPlate} onLabel={askLabel} />
    ) : (
      <Workspace
        api={api}
        route={route as Exclude<Route, { view: 'entry' }>}
        vehicle={vehicle}
        categories={categories}
        narrowing={narrowing}
        navigate={navigate}
        slot={slot}
        actionsSlot={actionsSlot}
        followUp={followUp}
        turn={held ? turn : null}
        recent={authed && route.view === 'vehicle' ? historyFor(historyList, route.ref) : []}
        restoredInput={restoredInput}
        onOpenHistory={openHistory}
        onAsk={askAgent}
        onPlate={askPlate}
        onLabel={askLabel}
      />
    ),
    'work',
  );
}

async function recordBuildCode(api: FinderApi, car: Vehicle | AgentVehicle, code: BuildCode): Promise<void> {
  const iri = garageMatch(await listMyVehicles(), car);
  if (!iri) return;
  await api.saveVehicle({ make: car.make, model: car.model, carId: car.carId, plate: car.plate, vin: null, vehicle: iri, buildCode: code.organisationNumber }).catch(() => undefined);
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
  actionsSlot: HTMLElement | null;
  followUp: MutableRefObject<FollowUp | null>;
  // A follow-up turn holding this result on screen until it shows parts of its own.
  turn: AgentTurn | null;
  // Searches already made on this vehicle, offered on its empty sheet.
  recent: HistoryEntry[];
  restoredInput: string | null;
  onOpenHistory: (entry: HistoryEntry) => void;
  onAsk: (text: string, options?: AskOptions) => void;
  onPlate: () => void;
  onLabel: () => void;
}

function Workspace({ api, route, vehicle, categories, narrowing, navigate, slot, actionsSlot, followUp, turn, recent, restoredInput, onOpenHistory, onAsk, onPlate, onLabel }: WorkspaceProps) {
  const reducedMotion = useReducedMotion();
  const [wrapRef, wide] = useWideEnough(SHEET_OVERLAY_MIN);
  const roomy = useMedia('(min-width: 1280px)');
  const v = vehicle.status === 'ok' ? vehicle.data : null;

  const n = narrowing.status === 'ok' ? narrowing.data : null;
  const node = route.view === 'result' && categories.status === 'ok' ? (categories.data.find((c) => c.id === route.categoryId) ?? null) : null;
  // The title is the TecDoc product group narrowed, a far more precise name than the tree node.
  const category = node && n?.productGroup ? { ...node, label: n.productGroup.name } : node;
  const relaunch = n && node ? relaunchText(n, restoredInput, node.label) : null;
  const choice = route.view === 'result' ? route.cluster : null;
  const selection = selectVariant(n, choice);
  const { cluster } = selection;
  const decided = selection.status !== 'open';
  const asking = questionOpen(selection);
  const choose = (next: ClusterChoice) => route.view === 'result' && navigate({ ...route, cluster: next }, { replace: true });
  const question = useMemo(() => (n && category ? buildQuestion(n, category.label) : null), [n, category]);
  const showResult = route.view === 'result' && category !== null && n !== null && n.totalArticles > 0 && relaunch === null;
  const family: Family = category && (showResult || narrowing.status === 'loading') ? partFamily(category, cluster?.dimensions ?? [], n?.productGroup?.name ?? null) : { kind: 'none' };

  useFollowUp(
    followUp,
    showResult && category && n ? { summary: resultSummary(category.label, n, decided ? choice : null), pendingContext: question && !decided && asking ? `question affichée : ${question.text}` : null } : null,
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

  const overlay = wide ? (roomy ? BLOCK_WIDTH_WIDE : BLOCK_WIDTH) : null;
  const loading = narrowing.status === 'loading' || (route.view === 'result' && categories.status === 'loading');

  let status: ReactNode = null;
  let placeholder: ReactNode = null;
  if (v.carId === null) {
    placeholder = <p className="sheet-message">Pas de kType pour ce véhicule : aucune pièce au catalogue.</p>;
  } else if (route.view === 'vehicle') {
    placeholder = (
      <div className="sheet-message">
        <p className="sheet-big">Quelle pièce ?</p>
        {recent.length > 0 && <History api={api} entries={recent.slice(0, 5)} loading={false} onOpen={onOpenHistory} title="Recherches sur ce véhicule" compact />}
      </div>
    );
  } else if (categories.status === 'error') {
    status = <ErrorNote message={categories.error.message} onRetry={categories.retry} />;
  } else if (route.view === 'result' && categories.status === 'ok' && !category) {
    placeholder = <p className="sheet-message">Catégorie absente pour ce véhicule. Cherchez la pièce par son nom.</p>;
  } else if (narrowing.status === 'error') {
    status = <ErrorNote message={narrowing.error.message} onRetry={narrowing.retry} />;
    placeholder = <p className="sheet-big">{category?.label}</p>;
  } else if (relaunch !== null) {
    placeholder = (
      <div className="sheet-message">
        <p className="sheet-big">{node?.label}</p>
        <p>Cette catégorie regroupe plusieurs pièces différentes.</p>
        <button type="button" className="btn pri" onClick={() => onAsk(relaunch)}>
          Relancer la recherche
        </button>
      </div>
    );
  } else if (n && n.totalArticles === 0) {
    status = <p className="sheet-message">Aucune référence dans cette catégorie pour ce véhicule.</p>;
  }
  if (loading && family.kind !== 'none') status = <p className="sheet-loading" role="status">Affinage des références…</p>;

  const block = showResult ? <TitleBlock vehicle={v} category={category} narrowing={n} selection={selection} onPick={(id) => choose(pickChoice(selection, id))} /> : loading && route.view === 'result' ? <TitleSkeleton /> : null;

  const ask = turn?.state.ask ?? null;
  const waiting = turn !== null && isBusy(turn.state);

  return (
    <div className={waiting ? 'work work--held' : 'work'} aria-busy={waiting || undefined}>
      <div ref={wrapRef} className={wide ? 'sheet-wrap' : 'sheet-wrap sheet-wrap--stacked'}>
        {!wide && block}
        <Sheet
          family={family}
          viewLabel={viewLabel(family, category)}
          dimensions={cluster?.dimensions ?? []}
          photo={family.kind === 'photo' ? sheetPhoto(n, cluster, v.carType) : null}
          ghost={loading || !cluster}
          reducedMotion={reducedMotion}
          overlayWidth={block ? overlay : null}
          placeholder={placeholder}
          status={status}
        >
          {wide && block}
        </Sheet>
      </div>
      {slot && ask && createPortal(<AgentQuestion ask={ask} disabled={waiting} onAnswer={(value) => onAsk(value, { pendingContext: ask.context, continued: true })} onPlate={onPlate} onLabel={onLabel} />, slot)}
      {actionsSlot && showResult && category && n && createPortal(<ResultActions vehicle={v} category={category} family={family} cluster={cluster} clusters={n.clusters} api={api} />, actionsSlot)}
      {slot &&
        !ask &&
        question &&
        asking &&
        createPortal(<QuestionCard question={question} answered={selection.status === 'answer' ? (cluster?.id ?? null) : null} onAnswer={(id) => choose({ id, by: 'answer' })} />, slot)}
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
      <div className="c s3 nb"><span className="l">Cotes</span><span className="sk" style={{ width: '80%' }} /></div>
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
  actionsSlot: HTMLElement | null;
  followUp: MutableRefObject<FollowUp | null>;
  onAsk: (text: string, options?: AskOptions) => void;
  onPlate: () => void;
  onLabel: () => void;
}

function partCategory(part: AgentPart, categories: Category[]): Category {
  const known = part.kind === 'catalog' ? categories.find((c) => c.id === part.categoryId) : undefined;
  return known
    ? { ...known, label: part.label }
    : { id: part.kind === 'catalog' ? part.categoryId : 0, name: part.label, path: part.label, segments: [part.label], label: part.label, leaf: true };
}

const agentChoice = (p: AgentPart): ClusterChoice | null =>
  p.kind === 'catalog' && p.selection ? { id: p.selection.clusterId, by: 'agent', basis: p.selection.basis, ...(p.selection.setAside ? { setAside: p.selection.setAside } : {}) } : null;

function AgentWorkspace({ api, route, navigate, vehicle, categories, turn, slot, actionsSlot, followUp, onAsk, onPlate, onLabel }: AgentWorkspaceProps) {
  const reducedMotion = useReducedMotion();
  const [wrapRef, wide] = useWideEnough(SHEET_OVERLAY_MIN);
  const roomy = useMedia('(min-width: 1280px)');
  const { state } = turn;
  const [webKey, setWebKey] = useState<string | null>(null);
  const busy = isBusy(state);
  const ref = routeRef(route);

  // A catalogue part's route is its sheet: shareable, and a reload re-reads the cached narrowing.
  const routed =
    route.view === 'result'
      ? state.parts.find((p) => p.kind === 'catalog' && p.categoryId === route.categoryId && (route.productGroupId === null || p.narrowing.productGroup?.id === route.productGroupId))
      : undefined;
  const part = state.parts.find((p) => p.key === webKey) ?? routed ?? state.parts[0] ?? null;
  const category = part ? partCategory(part, categories) : null;
  const n = part?.kind === 'catalog' ? part.narrowing : null;
  const chosen = route.view === 'result' && routed === part ? route.cluster : null;
  const selection = selectVariant(n, chosen);
  const { cluster } = selection;
  const decided = selection.status !== 'open';
  const asking = questionOpen(selection);
  const question = useMemo(() => (n && category ? buildQuestion(n, category.label) : null), [n, category]);
  const family: Family = category && part ? partFamily(category, cluster?.dimensions ?? [], n?.productGroup?.name ?? null) : { kind: 'none' };
  const overlay = wide && part ? (roomy ? BLOCK_WIDTH_WIDE : BLOCK_WIDTH) : null;

  const openPart = (p: AgentPart, cluster: ClusterChoice | null = null) => {
    if (p.kind === 'web') {
      setWebKey(p.key);
      if (ref !== '') navigate({ view: 'vehicle', ref }, { replace: true });
      return;
    }
    setWebKey(null);
    if (ref !== '') navigate({ view: 'result', ref, categoryId: p.categoryId, productGroupId: p.narrowing.productGroup?.id ?? null, cluster }, { replace: true });
  };

  const first = state.parts[0];
  useEffect(() => {
    if (first && first.kind === 'catalog' && webKey === null && route.view === 'vehicle' && ref !== '')
      navigate({ view: 'result', ref, categoryId: first.categoryId, productGroupId: first.narrowing.productGroup?.id ?? null, cluster: agentChoice(first) }, { replace: true });
  }, [first?.key, ref, route.view]);

  // A redraw that retains a variant (show_parts clusterId) opens it: the route carries the choice and its basis.
  const retained = part?.kind === 'catalog' ? part.selection : null;
  useEffect(() => {
    if (part && retained && webKey === null) openPart(part, agentChoice(part));
  }, [retained?.clusterId, retained?.basis]);

  useFollowUp(
    followUp,
    part === null
      ? { summary: null, pendingContext: state.ask?.context ?? null }
      : {
          summary: part.kind === 'catalog' && category && n ? resultSummary(category.label, n, decided ? chosen : null) : part.kind === 'web' ? `${part.label} : ${part.brand} ${part.reference} (source web, hors catalogue)` : null,
          pendingContext: state.ask?.context ?? (question && !decided && asking ? `question affichée : ${question.text}` : null),
        },
  );

  let placeholder: ReactNode = null;
  if (!part && turn.needsLogin !== null) {
    placeholder = (
      <div className="sheet-message">
        <p className="sheet-big">Connexion requise</p>
        <p>Connectez-vous au-dessus du champ. La recherche reprend au retour.</p>
      </div>
    );
  } else if (!part) {
    placeholder = busy ? (
      <div className="sheet-message">
        <p className="sheet-big">Recherche en cours</p>
        <p>{vehicle ? 'Recherche dans le catalogue du véhicule.' : 'Identification du véhicule, puis de la pièce.'}</p>
      </div>
    ) : (
      <div className="sheet-message">
        <p className="sheet-big">{state.ask ? 'Question en attente' : 'Aucune pièce'}</p>
        <p>{state.ask ? 'Répondez au-dessus du champ.' : 'Reformulez la demande.'}</p>
      </div>
    );
  }

  const block = !part ? null : part.kind === 'web' ? (
    <WebTitleBlock vehicle={vehicle} part={part} />
  ) : category && n && vehicle ? (
    <TitleBlock vehicle={vehicle} category={category} narrowing={n} selection={selection} onPick={(id) => openPart(part, pickChoice(selection, id))} rationale={part.selection ? undefined : part.rationale} />
  ) : null;

  return (
    <div className="work">
      <div ref={wrapRef} className={['sheet-wrap', state.parts.length > 1 && 'sheet-wrap--parts', !wide && 'sheet-wrap--stacked'].filter(Boolean).join(' ')}>
        {state.parts.length > 1 && part && <PartSwitcher parts={state.parts} selected={part.key} onSelect={(key) => { const p = state.parts.find((x) => x.key === key); if (p) openPart(p, agentChoice(p)); }} />}
        {!wide && block}
        <Sheet
          family={family}
          viewLabel={viewLabel(family, category)}
          dimensions={cluster?.dimensions ?? []}
          photo={family.kind === 'photo' ? sheetPhoto(n, cluster, vehicle?.carType ?? 'PC') : null}
          ghost={!cluster || state.stale}
          reducedMotion={reducedMotion}
          overlayWidth={overlay}
          placeholder={placeholder}
          status={null}
        >
          {wide && block}
        </Sheet>
      </div>
      {actionsSlot && part?.kind === 'catalog' && category && n && vehicle && createPortal(<ResultActions vehicle={vehicle} category={category} family={family} cluster={cluster} clusters={n.clusters} api={api} />, actionsSlot)}
      {slot &&
        createPortal(
          <>
            {state.ask && <AgentQuestion ask={state.ask} disabled={busy} onAnswer={(value) => onAsk(value, { pendingContext: state.ask?.context ?? null, continued: true })} onPlate={onPlate} onLabel={onLabel} />}
            {!state.ask && question && asking && part && <QuestionCard question={question} answered={selection.status === 'answer' ? (cluster?.id ?? null) : null} onAnswer={(id) => openPart(part, { id, by: 'answer' })} />}
          </>,
          slot,
        )}
    </div>
  );
}
