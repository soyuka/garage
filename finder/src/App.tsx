import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { isAuthenticated, login } from './auth';
import { ApiError } from './api';
import { isDemo, loadApi, type FinderApi, type Trace } from './client';
import { partFamily } from './family';
import { MediaError, pickSharpest, prepareImage, sampleVideo, type Frame } from './media';
import { buildQuestion } from './question';
import { recentFor, remember, type RecentSearch } from './recent';
import { parseRef, type Route } from './route';
import { routeFile, type TextRoute } from './route-input';
import type { AgentPart, Category, Family, Narrowing, Registration, Vehicle, VinDecode } from './types';
import { isBusy } from './agent';
import { carVehicle, rememberCar } from './vehicles';
import { Aside } from './ui/Aside';
import { AgentQuestion, AgentStatus, PartSwitcher, WebTitleBlock } from './ui/Agent';
import { BlankSheet, CardResult, Entry, VinResult } from './ui/Entry';
import { useAsync, useMedia, useReducedMotion, useRoute, useWideEnough, type Async } from './ui/hooks';
import { Intake } from './ui/Intake';
import { Journal } from './ui/Journal';
import { useAgentTurn, type AgentTurn } from './ui/useAgentTurn';
import { Sheet } from './ui/Sheet';
import { TitleBlock } from './ui/TitleBlock';
import { VehicleChip, vehicleLabel } from './ui/VehicleChip';

const FAMILY_VIEW: Record<string, string> = {
  filter: 'VUE ISO — FILTRE',
  front: 'VUE ISO — FREIN AVANT',
  rear: 'VUE ISO — FREIN ARRIÈRE',
};

const viewLabel = (family: Family, category: Category | null): string => {
  if (family.kind === 'filter') return `VUE ISO — ${(category?.label ?? 'filtre').toUpperCase()}`;
  if (family.kind === 'brake') return FAMILY_VIEW[family.axle] ?? 'VUE ISO';
  return category ? category.label.toUpperCase() : 'PLANCHE';
};

const MAX_FRAMES = 3;
const PHOTO_INSTRUCTION = 'Identifie la pièce sur la photo.';

export function App() {
  const [api, setApi] = useState<FinderApi | null>(null);
  useEffect(() => {
    void loadApi().then(setApi);
  }, []);
  if (!api) return null;
  return <Finder api={api} />;
}

// What the entry panel shows under the single input, besides an agent turn.
type Panel =
  | { kind: 'none' }
  | { kind: 'vin'; decode: VinDecode }
  | { kind: 'card'; registration: Registration }
  | { kind: 'reading'; what: string }
  | { kind: 'login'; why: string }
  | { kind: 'error'; message: string };

const routeRef = (route: Route): string => (route.view === 'entry' ? '' : route.ref);

function Finder({ api }: { api: FinderApi }) {
  const [route, navigate] = useRoute();
  const demo = isDemo();
  const [panel, setPanel] = useState<Panel>({ kind: 'none' });
  const [journalOpen, setJournalOpen] = useState(false);
  const docked = useMedia('(min-width: 1280px)');
  const journalButton = useRef<HTMLButtonElement>(null);
  const trace = useRef<Trace | null>(null);
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
  const categoryId = route.view === 'result' ? route.categoryId : null;
  const narrowing = useAsync<Narrowing>(v?.carId && categoryId ? `${v.carId}-${categoryId}` : null, () => {
    const t = trace.current ?? { rule: 'url', input: String(categoryId) };
    trace.current = null;
    return api.narrowed(v!.carId!, v!.carType, categoryId!, v!.make, t);
  });

  const turn = useAgentTurn(v, ref, !demo);
  const turnVehicle = turn.state.vehicle;

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

  const askAgent = (text: string, options: Parameters<AgentTurn['ask']>[1] = {}) => {
    if (demo) {
      setPanel({ kind: 'error', message: 'Mode démo : l’agent n’est pas appelé. Essayez la plaque XB-301-ZB, puis « filtre à huile ».' });
      return;
    }
    setPanel({ kind: 'none' });
    if (route.view === 'result') navigate({ view: 'vehicle', ref: route.ref });
    turn.ask(text, options);
  };

  const onText = (r: TextRoute, typed: string) => {
    switch (r.kind) {
      case 'empty':
        return;
      case 'plate':
        return goVehicle(r.plate, r.format === 'siv' ? 'plate' : 'plate-fni', typed);
      case 'vin':
        setPanel({ kind: 'reading', what: 'Décodage du VIN…' });
        if (route.view !== 'entry') navigate({ view: 'entry' });
        api
          .vin(r.vin, { rule: 'vin', input: typed })
          .then((decode) => setPanel({ kind: 'vin', decode }))
          .catch((e: unknown) => setPanel({ kind: 'error', message: e instanceof ApiError ? e.message : 'Décodage impossible.' }));
        return;
      case 'category':
        if (!v) return askAgent(typed);
        turn.reset();
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
    if (!demo && !isAuthenticated()) {
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
      if (intake.kind === 'part') {
        askAgent(PHOTO_INSTRUCTION, { intent: 'photo', images: frames.map((f) => f.dataUrl), photoHints: intake.part });
        return;
      }
      setPanel({ kind: 'error', message: 'Ni une pièce ni une carte grise sur cette photo. Cadrez la pièce, ou sa référence, de plus près.' });
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
  const agentActive = turn.state.phase !== 'idle' || turn.needsLogin !== null;

  const header = (
    <header className="top">
      <a
        className="logo"
        href={demo ? '?demo#/' : '#/'}
        onClick={() => {
          turn.reset();
          setPanel({ kind: 'none' });
        }}
      >
        finder
      </a>
      {v && (
        <VehicleChip
          vehicle={v}
          onChange={() => {
            turn.reset();
            setPanel({ kind: 'none' });
            navigate({ view: 'entry' });
          }}
        />
      )}
      {(route.view !== 'entry' || agentActive) && (
        <div className="q">
          <Intake
            variant="bar"
            categories={categories.status === 'ok' ? categories.data : null}
            loading={categories.status === 'loading'}
            initial={
              route.view === 'result' && categories.status === 'ok'
                ? (categories.data.find((c) => c.id === route.categoryId)?.label ?? '')
                : (turn.needsLogin ?? turn.restored ?? (turn.state.phase !== 'idle' ? turn.state.instruction : ''))
            }
            busy={busyReading}
            autoFocus={route.view === 'vehicle' && turn.state.phase === 'idle'}
            onText={onText}
            onFiles={(f) => void onFiles(f)}
          />
        </div>
      )}
      <button ref={journalButton} type="button" className="journal-btn" aria-expanded={journalOpen} onClick={() => setJournalOpen((o) => !o)}>
        Journal
      </button>
    </header>
  );

  const footer = (
    <footer className="foot">
      <span>Catalogue TecDoc · usage privé</span>
      {demo && <span className="tag">mode démo : réponses enregistrées, aucun appel catalogue</span>}
    </footer>
  );

  const journal = <Journal api={api} open={journalOpen} authed={demo || isAuthenticated()} docked={docked} onClose={closeJournal} />;

  if ((route.view === 'entry' && !agentActive) || vehicle.status === 'error') {
    const error =
      vehicle.status === 'error'
        ? vehicle.error.kind === 'notFound'
          ? `La plaque ${ref} est introuvable. Vérifiez-la, ou envoyez une photo de la carte grise.`
          : vehicle.error.message
        : null;
    return (
      <div className={journalOpen && docked ? 'app app--entry app--journal' : 'app app--entry'}>
        {header}
        <main className="entry-main">
          <Entry
            context={panel.kind === 'vin' && panel.decode.valid ? 'Ajoutez la plaque, ou une photo de la carte grise : le VIN seul ne donne pas le type exact.' : null}
            busy={busyReading}
            onText={onText}
            onFiles={(f) => void onFiles(f)}
            examples={demo ? ['XB-301-ZB', 'W 712/73', 'filtre à huile 307 1.4 hdi'] : []}
          >
            <EntryPanel panel={error ? { kind: 'error', message: error } : panel} onPlate={(p) => goVehicle(p, 'carte-grise', p)} onReset={() => setPanel({ kind: 'none' })} />
          </Entry>
          <BlankSheet />
        </main>
        {footer}
        {journal}
      </div>
    );
  }

  return (
    <div className={journalOpen && docked ? 'app app--work app--journal' : 'app app--work'}>
      {header}
      {route.view === 'entry' ? (
        <AgentWorkspace api={api} vehicle={null} categories={[]} turn={turn} recent={[]} onRecent={() => undefined} />
      ) : (
        <Workspace api={api} route={route} vehicle={vehicle} categories={categories} narrowing={narrowing} navigate={navigate} turn={turn} trace={trace} panel={panel} />
      )}
      {footer}
      {journal}
    </div>
  );
}

function EntryPanel({ panel, onPlate, onReset }: { panel: Panel; onPlate: (plate: string) => void; onReset: () => void }) {
  switch (panel.kind) {
    case 'none':
      return null;
    case 'vin':
      return <VinResult decode={panel.decode} />;
    case 'card':
      return <CardResult data={panel.registration} onPlate={onPlate} onReset={onReset} />;
    case 'reading':
      return (
        <p className="entry-status" role="status">
          <span className="agent-dot" aria-hidden="true" />
          {panel.what}
        </p>
      );
    case 'login':
      return (
        <div className="card-login">
          <p>{panel.why}</p>
          <button type="button" className="btn pri" onClick={() => void login()}>
            Se connecter
          </button>
        </div>
      );
    case 'error':
      return (
        <p className="field-error" role="alert">
          {panel.message}
        </p>
      );
  }
}

interface WorkspaceProps {
  api: FinderApi;
  route: Exclude<Route, { view: 'entry' }>;
  vehicle: Async<Vehicle> & { retry: () => void };
  categories: Async<Category[]> & { retry: () => void };
  narrowing: Async<Narrowing> & { retry: () => void };
  navigate: (r: Route) => void;
  turn: AgentTurn;
  trace: { current: Trace | null };
  panel: Panel;
}

const SUGGESTIONS = [100259, 100260, 100261];
const SHEET_OVERLAY_MIN = 812;

function Workspace({ api, route, vehicle, categories, narrowing, navigate, turn, trace, panel }: WorkspaceProps) {
  const reducedMotion = useReducedMotion();
  const [wrapRef, wide] = useWideEnough(SHEET_OVERLAY_MIN);
  const v = vehicle.status === 'ok' ? vehicle.data : null;
  const [recent, setRecent] = useState<RecentSearch[]>(() => recentFor(route.ref));
  const open = (categoryId: number, rule: string, input: string) => {
    trace.current = { rule, input };
    navigate({ view: 'result', ref: route.ref, categoryId, clusterId: null });
  };

  const category = route.view === 'result' && categories.status === 'ok' ? (categories.data.find((c) => c.id === route.categoryId) ?? null) : null;
  const n = narrowing.status === 'ok' ? narrowing.data : null;
  const clusters = n?.clusters ?? [];
  const cluster = clusters.find((c) => c.id === (route.view === 'result' ? route.clusterId : null)) ?? clusters[0] ?? null;
  const confirmed = clusters.length === 1 || (route.view === 'result' && route.clusterId !== null && cluster?.id === route.clusterId);
  const question = useMemo(() => (n && category ? buildQuestion(n, category.label) : null), [n, category]);
  const family = category ? partFamily(category, cluster?.dimensions ?? []) : ({ kind: 'none' } as const);

  useEffect(() => setRecent(recentFor(route.ref)), [route.ref]);
  useEffect(() => {
    if (category) setRecent(remember(route.ref, { categoryId: category.id, label: category.label }));
  }, [category, route.ref]);

  if (!v) {
    return (
      <main className="work work--loading" aria-busy="true">
        <aside className="aside" />
        <div className="sheet-wrap">
          <div className="sheet">
            <div className="sheet-status" role="status">
              Identification de <span className="m">{route.ref}</span>…
            </div>
          </div>
        </div>
      </main>
    );
  }

  if (route.view === 'vehicle' && (turn.state.phase !== 'idle' || turn.needsLogin !== null)) {
    return (
      <AgentWorkspace
        api={api}
        vehicle={v}
        categories={categories.status === 'ok' ? categories.data : []}
        turn={turn}
        recent={recent}
        onRecent={(r) => {
          turn.reset();
          open(r.categoryId, 'recent', r.label);
        }}
      />
    );
  }

  const overlay = wide ? 520 : null;
  const loading = narrowing.status === 'loading' || (route.view === 'result' && categories.status === 'loading');
  const suggestions = categories.status === 'ok' ? SUGGESTIONS.flatMap((id) => categories.data.find((c) => c.id === id) ?? []) : [];

  let status: ReactNode = null;
  let placeholder: ReactNode = null;
  if (v.carId === null) {
    placeholder = <p className="sheet-message">Ce véhicule n’a pas de type catalogue (kType) : impossible d’en lister les pièces.</p>;
  } else if (route.view === 'vehicle') {
    placeholder = (
      <div className="sheet-message">
        <p className="sheet-big">Quelle pièce ?</p>
        <p>Tapez-la dans le champ en haut, « filtre à huile », « disque de frein », une référence, ou envoyez une photo de la pièce.</p>
        {panel.kind === 'error' && (
          <p className="field-error" role="alert">
            {panel.message}
          </p>
        )}
        {panel.kind === 'reading' && (
          <p className="entry-status" role="status">
            <span className="agent-dot" aria-hidden="true" />
            {panel.what}
          </p>
        )}
        {suggestions.length > 0 && (
          <div className="sugg">
            {suggestions.map((c) => (
              <button key={c.id} type="button" className="btn" onClick={() => open(c.id, 'suggestion', c.label)}>
                {c.label}
              </button>
            ))}
          </div>
        )}
      </div>
    );
  } else if (categories.status === 'error') {
    status = <ErrorNote message={categories.error.message} onRetry={categories.retry} />;
  } else if (route.view === 'result' && categories.status === 'ok' && !category) {
    placeholder = <p className="sheet-message">Cette catégorie n’existe pas pour ce véhicule. Cherchez la pièce dans le champ en haut.</p>;
  } else if (narrowing.status === 'error') {
    status = <ErrorNote message={narrowing.error.message} onRetry={narrowing.retry} />;
    placeholder = <p className="sheet-big">{category?.label}</p>;
  } else if (n && n.totalArticles === 0) {
    status = <p className="sheet-message">Aucune pièce de cette catégorie n’est référencée pour ce véhicule.</p>;
    placeholder = null;
  } else if (family.kind === 'none' && category) {
    placeholder = (
      <div className="sheet-message">
        <p className="sheet-big">{category.label}</p>
        <p>Pas encore de vue technique pour cette famille de pièces.</p>
      </div>
    );
  }
  if (loading && family.kind !== 'none') status = <p className="sheet-loading" role="status">Affinage des références…</p>;

  const showResult = route.view === 'result' && category && n && n.totalArticles > 0;

  return (
    <main className="work">
      <Aside
        narrowing={route.view === 'result' ? narrowing : null}
        remaining={confirmed ? 1 : null}
        cluster={cluster}
        question={question}
        selected={cluster?.id ?? null}
        confirmed={confirmed}
        onAnswer={(id) => route.view === 'result' && navigate({ ...route, clusterId: id })}
        recent={recent}
        vehicleLabel={vehicleLabel(v)}
        currentCategory={category?.id ?? null}
        onRecent={(r) => open(r.categoryId, 'recent', r.label)}
      />
      <div ref={wrapRef} className={wide ? 'sheet-wrap' : 'sheet-wrap sheet-wrap--stacked'}>
        <Sheet
          family={family}
          viewLabel={viewLabel(family, category)}
          dimensions={cluster?.dimensions ?? []}
          ghost={loading || !cluster}
          reducedMotion={reducedMotion}
          overlayWidth={showResult || loading ? overlay : null}
          placeholder={placeholder}
          status={status}
        >
          {wide && showResult && <TitleBlock vehicle={v} category={category} narrowing={n} cluster={cluster} confirmed={confirmed} family={family} api={api} demo={isDemo()} />}
          {wide && loading && route.view === 'result' && <TitleSkeleton />}
        </Sheet>
        {!wide && showResult && <TitleBlock vehicle={v} category={category} narrowing={n} cluster={cluster} confirmed={confirmed} family={family} api={api} demo={isDemo()} />}
        {!wide && loading && route.view === 'result' && <TitleSkeleton />}
      </div>
    </main>
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
  vehicle: Vehicle | null;
  categories: Category[];
  turn: AgentTurn;
  recent: RecentSearch[];
  onRecent: (r: RecentSearch) => void;
}

function partCategory(part: AgentPart, categories: Category[]): Category {
  const known = part.kind === 'catalog' ? categories.find((c) => c.id === part.categoryId) : undefined;
  return known
    ? { ...known, label: part.label }
    : { id: part.kind === 'catalog' ? part.categoryId : 0, name: part.label, path: part.label, segments: [part.label], label: part.label, leaf: true };
}

function AgentWorkspace({ api, vehicle, categories, turn, recent, onRecent }: AgentWorkspaceProps) {
  const reducedMotion = useReducedMotion();
  const [wrapRef, wide] = useWideEnough(SHEET_OVERLAY_MIN);
  const { state } = turn;
  const [selectedKey, setSelectedKey] = useState<string | null>(null);
  const [clusterFor, setClusterFor] = useState<Record<string, string>>({});
  const busy = isBusy(state);

  const part = state.parts.find((p) => p.key === selectedKey) ?? state.parts[0] ?? null;
  const category = part ? partCategory(part, categories) : null;
  const n = part?.kind === 'catalog' ? part.narrowing : null;
  const clusters = n?.clusters ?? [];
  const chosen = part ? clusterFor[part.key] : undefined;
  const cluster = clusters.find((c) => c.id === chosen) ?? clusters[0] ?? null;
  const confirmed = clusters.length === 1 || (chosen !== undefined && cluster?.id === chosen);
  const question = useMemo(() => (n && category ? buildQuestion(n, category.label) : null), [n, category]);
  const family: Family = category ? partFamily(category, cluster?.dimensions ?? []) : { kind: 'none' };
  const overlay = wide && part ? 520 : null;

  useEffect(() => {
    if (state.phase === 'sending') {
      setSelectedKey(null);
      setClusterFor({});
    }
  }, [state.phase]);

  const lead = (
    <>
      <AgentStatus
        state={state}
        needsLogin={turn.needsLogin}
        onCancel={turn.cancel}
        onRetry={() => turn.ask(state.instruction)}
        onSignIn={turn.signIn}
      />
      {state.ask && <AgentQuestion ask={state.ask} disabled={busy} onAnswer={(value) => turn.ask(value, { pendingContext: state.ask?.context ?? null })} />}
    </>
  );

  let placeholder: ReactNode = null;
  if (!part && turn.needsLogin !== null) {
    placeholder = (
      <div className="sheet-message">
        <p className="sheet-big">Connexion requise</p>
        <p>L’agent cherche la pièce pour un compte connecté. Connectez-vous dans la colonne de gauche : la demande repart au retour.</p>
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
        <p>{state.ask ? 'Répondez à la question à gauche pour que l’agent continue.' : 'Reformulez la demande dans le champ en haut, ou choisissez une catégorie dans la liste.'}</p>
      </div>
    );
  } else if (family.kind === 'none') {
    placeholder = (
      <div className="sheet-message">
        <p className="sheet-big">{part.label}</p>
        <p>{part.kind === 'web' ? 'Référence trouvée sur le web : pas de vue technique pour cette pièce.' : 'Pas encore de vue technique pour cette famille de pièces.'}</p>
      </div>
    );
  }

  const block = !part ? null : part.kind === 'web' ? (
    <WebTitleBlock vehicle={vehicle} part={part} />
  ) : category && n && vehicle ? (
    <TitleBlock vehicle={vehicle} category={category} narrowing={n} cluster={cluster} confirmed={confirmed} family={family} api={api} demo={isDemo()} rationale={part.rationale} />
  ) : null;

  return (
    <main className="work">
      <Aside
        narrowing={n ? { status: 'ok', data: n } : null}
        remaining={confirmed ? 1 : null}
        cluster={cluster}
        question={question}
        selected={cluster?.id ?? null}
        confirmed={confirmed}
        onAnswer={(id) => part && setClusterFor((m) => ({ ...m, [part.key]: id }))}
        recent={recent}
        vehicleLabel={vehicle ? vehicleLabel(vehicle) : ''}
        currentCategory={null}
        onRecent={onRecent}
        lead={lead}
      />
      <div ref={wrapRef} className={['sheet-wrap', state.parts.length > 1 && 'sheet-wrap--parts', !wide && 'sheet-wrap--stacked'].filter(Boolean).join(' ')}>
        {state.parts.length > 1 && part && <PartSwitcher parts={state.parts} selected={part.key} onSelect={setSelectedKey} />}
        <Sheet
          family={family}
          viewLabel={viewLabel(family, category)}
          dimensions={cluster?.dimensions ?? []}
          ghost={!cluster}
          reducedMotion={reducedMotion}
          overlayWidth={overlay}
          placeholder={placeholder}
          status={null}
        >
          {wide && block}
        </Sheet>
        {!wide && block}
      </div>
    </main>
  );
}
