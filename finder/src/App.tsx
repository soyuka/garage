import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { isDemo, loadApi, type FinderApi } from './client';
import { partFamily } from './family';
import { buildQuestion } from './question';
import { recentFor, remember, type RecentSearch } from './recent';
import type { Route } from './route';
import type { AgentPart, Category, Family, Narrowing, Vehicle } from './types';
import { isBusy } from './agent';
import { Aside } from './ui/Aside';
import { AgentQuestion, AgentStatus, PartSwitcher, WebTitleBlock } from './ui/Agent';
import { Entry } from './ui/Entry';
import { useAsync, useMedia, useReducedMotion, useRoute, type Async } from './ui/hooks';
import { useAgentTurn, type AgentTurn } from './ui/useAgentTurn';
import { QueryBox } from './ui/QueryBox';
import { Segmented, type EntryMode } from './ui/Segmented';
import { Sheet } from './ui/Sheet';
import { TitleBlock } from './ui/TitleBlock';
import { VehicleChip } from './ui/VehicleChip';

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

export function App() {
  const [api, setApi] = useState<FinderApi | null>(null);
  useEffect(() => {
    void loadApi().then(setApi);
  }, []);
  if (!api) return null;
  return <Finder api={api} />;
}

function Finder({ api }: { api: FinderApi }) {
  const [route, navigate] = useRoute();
  const [entryMode, setEntryMode] = useState<EntryMode>('plate');
  const demo = isDemo();
  const plate = route.view === 'entry' ? null : route.plate;

  const vehicle = useAsync<Vehicle>(plate, () => api.vehicle(plate!));
  const v = vehicle.status === 'ok' ? vehicle.data : null;
  const categories = useAsync<Category[]>(v?.carId ? `${v.carId}-${v.carType}` : null, () => api.categories(v!.carId!, v!.carType));
  const categoryId = route.view === 'result' ? route.categoryId : null;
  const narrowing = useAsync<Narrowing>(
    v?.carId && categoryId ? `${v.carId}-${categoryId}` : null,
    () => api.narrowed(v!.carId!, v!.carType, categoryId!, v!.make),
  );

  const turn = useAgentTurn(v, !demo);
  const askAgent = (text: string) => {
    if (!v) return;
    if (route.view !== 'vehicle') navigate({ view: 'vehicle', plate: v.plate });
    turn.ask(text);
  };

  const changeVehicle = (mode: EntryMode) => {
    setEntryMode(mode);
    navigate({ view: 'entry' });
  };

  const header = (
    <header className="top">
      <a className="logo" href="#/" onClick={() => setEntryMode('plate')}>
        finder
      </a>
      {v && <VehicleChip vehicle={v} onChange={() => changeVehicle('plate')} />}
      {v && route.view !== 'entry' && (
        <div className="q">
          <div className="top-seg">
            <Segmented value={null} onChange={changeVehicle} label="Changer de véhicule" />
          </div>
          <QueryBox
            categories={categories.status === 'ok' ? categories.data : null}
            loading={categories.status === 'loading'}
            initial={
              route.view === 'result' && categories.status === 'ok'
                ? (categories.data.find((c) => c.id === route.categoryId)?.label ?? '')
                : (turn.needsLogin ?? turn.restored ?? (turn.state.phase !== 'idle' ? turn.state.instruction : ''))
            }
            autoFocus={route.view === 'vehicle' && turn.state.phase === 'idle'}
            onPick={(c) => {
              turn.reset();
              navigate({ view: 'result', plate: v.plate, categoryId: c.id, clusterId: null });
            }}
            onAsk={demo || v.carId === null ? null : askAgent}
          />
        </div>
      )}
    </header>
  );

  const footer = (
    <footer className="foot">
      <span>Catalogue TecDoc · usage privé</span>
      {demo && <span className="tag">mode démo : réponses enregistrées, aucun appel catalogue</span>}
    </footer>
  );

  if (route.view === 'entry' || vehicle.status === 'error') {
    const error =
      vehicle.status === 'error'
        ? vehicle.error.kind === 'notFound'
          ? `La plaque ${plate} est introuvable. Vérifiez-la, ou passez par la carte grise.`
          : vehicle.error.message
        : null;
    return (
      <div className="app app--entry">
        {header}
        <main className="entry-main">
          <Entry api={api} mode={entryMode} onMode={setEntryMode} onPlate={(p) => navigate({ view: 'vehicle', plate: p })} error={error} demo={demo} />
        </main>
        {footer}
      </div>
    );
  }

  return (
    <div className="app app--work">
      {header}
      <Workspace route={route} vehicle={vehicle} categories={categories} narrowing={narrowing} navigate={navigate} turn={turn} />
      {footer}
    </div>
  );
}

interface WorkspaceProps {
  route: Exclude<Route, { view: 'entry' }>;
  vehicle: Async<Vehicle> & { retry: () => void };
  categories: Async<Category[]> & { retry: () => void };
  narrowing: Async<Narrowing> & { retry: () => void };
  navigate: (r: Route) => void;
  turn: AgentTurn;
}

const SUGGESTIONS = [100259, 100260, 100261];

function Workspace({ route, vehicle, categories, narrowing, navigate, turn }: WorkspaceProps) {
  const reducedMotion = useReducedMotion();
  const wide = useMedia('(min-width: 1100px)');
  const v = vehicle.status === 'ok' ? vehicle.data : null;
  const [recent, setRecent] = useState<RecentSearch[]>(() => recentFor(route.plate));

  const category = route.view === 'result' && categories.status === 'ok' ? (categories.data.find((c) => c.id === route.categoryId) ?? null) : null;
  const n = narrowing.status === 'ok' ? narrowing.data : null;
  const clusters = n?.clusters ?? [];
  const cluster = clusters.find((c) => c.id === (route.view === 'result' ? route.clusterId : null)) ?? clusters[0] ?? null;
  const confirmed = clusters.length === 1 || (route.view === 'result' && route.clusterId !== null && cluster?.id === route.clusterId);
  const question = useMemo(() => (n && category ? buildQuestion(n, category.label) : null), [n, category]);
  const family = category ? partFamily(category, cluster?.dimensions ?? []) : ({ kind: 'none' } as const);

  useEffect(() => setRecent(recentFor(route.plate)), [route.plate]);
  useEffect(() => {
    if (category) setRecent(remember(route.plate, { categoryId: category.id, label: category.label }));
  }, [category, route.plate]);

  if (!v) {
    return (
      <main className="work work--loading" aria-busy="true">
        <aside className="aside" />
        <div className="sheet-wrap">
          <div className="sheet">
            <div className="sheet-status" role="status">
              Identification de <span className="m">{route.plate}</span>…
            </div>
          </div>
        </div>
      </main>
    );
  }

  if (route.view === 'vehicle' && (turn.state.phase !== 'idle' || turn.needsLogin !== null)) {
    return (
      <AgentWorkspace
        vehicle={v}
        categories={categories.status === 'ok' ? categories.data : []}
        turn={turn}
        wide={wide}
        reducedMotion={reducedMotion}
        recent={recent}
        onRecent={(r) => {
          turn.reset();
          navigate({ view: 'result', plate: v.plate, categoryId: r.categoryId, clusterId: null });
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
        <p>Tapez-la dans le champ en haut : « filtre à huile », « disque de frein »…</p>
        {suggestions.length > 0 && (
          <div className="sugg">
            {suggestions.map((c) => (
              <button key={c.id} type="button" className="btn" onClick={() => navigate({ view: 'result', plate: v.plate, categoryId: c.id, clusterId: null })}>
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
        question={question}
        selected={cluster?.id ?? null}
        confirmed={confirmed}
        onAnswer={(id) => route.view === 'result' && navigate({ ...route, clusterId: id })}
        recent={recent}
        plate={v.plate}
        currentCategory={category?.id ?? null}
        onRecent={(r) => navigate({ view: 'result', plate: v.plate, categoryId: r.categoryId, clusterId: null })}
      />
      <div className="sheet-wrap">
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
          {wide && showResult && <TitleBlock vehicle={v} category={category} narrowing={n} cluster={cluster} confirmed={confirmed} />}
          {wide && loading && route.view === 'result' && <TitleSkeleton />}
        </Sheet>
        {!wide && showResult && <TitleBlock vehicle={v} category={category} narrowing={n} cluster={cluster} confirmed={confirmed} />}
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
  vehicle: Vehicle;
  categories: Category[];
  turn: AgentTurn;
  wide: boolean;
  reducedMotion: boolean;
  recent: RecentSearch[];
  onRecent: (r: RecentSearch) => void;
}

function partCategory(part: AgentPart, categories: Category[]): Category {
  const known = part.kind === 'catalog' ? categories.find((c) => c.id === part.categoryId) : undefined;
  return known
    ? { ...known, label: part.label }
    : { id: part.kind === 'catalog' ? part.categoryId : 0, name: part.label, path: part.label, segments: [part.label], label: part.label, leaf: true };
}

function AgentWorkspace({ vehicle, categories, turn, wide, reducedMotion, recent, onRecent }: AgentWorkspaceProps) {
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
      {state.ask && <AgentQuestion ask={state.ask} disabled={busy} onAnswer={(value) => turn.ask(value, state.ask?.context ?? null)} />}
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
        <p>L’agent identifie la ou les pièces de votre demande dans le catalogue de ce véhicule.</p>
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
  ) : category && n ? (
    <TitleBlock vehicle={vehicle} category={category} narrowing={n} cluster={cluster} confirmed={confirmed} rationale={part.rationale} />
  ) : null;

  return (
    <main className="work">
      <Aside
        narrowing={n ? { status: 'ok', data: n } : null}
        remaining={confirmed ? 1 : null}
        question={question}
        selected={cluster?.id ?? null}
        confirmed={confirmed}
        onAnswer={(id) => part && setClusterFor((m) => ({ ...m, [part.key]: id }))}
        recent={recent}
        plate={vehicle.plate}
        currentCategory={null}
        onRecent={onRecent}
        lead={lead}
      />
      <div className={state.parts.length > 1 ? 'sheet-wrap sheet-wrap--parts' : 'sheet-wrap'}>
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
