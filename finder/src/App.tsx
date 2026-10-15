import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { isDemo, loadApi, type FinderApi } from './client';
import { partFamily } from './family';
import { buildQuestion } from './question';
import { recentFor, remember, type RecentSearch } from './recent';
import type { Route } from './route';
import type { Category, Family, Narrowing, Vehicle } from './types';
import { Aside } from './ui/Aside';
import { Entry } from './ui/Entry';
import { useAsync, useMedia, useReducedMotion, useRoute, type Async } from './ui/hooks';
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
            initial={route.view === 'result' && categories.status === 'ok' ? (categories.data.find((c) => c.id === route.categoryId)?.label ?? '') : ''}
            autoFocus={route.view === 'vehicle'}
            onPick={(c) => navigate({ view: 'result', plate: v.plate, categoryId: c.id, clusterId: null })}
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
      <Workspace route={route} vehicle={vehicle} categories={categories} narrowing={narrowing} navigate={navigate} />
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
}

const SUGGESTIONS = [100259, 100260, 100261];

function Workspace({ route, vehicle, categories, narrowing, navigate }: WorkspaceProps) {
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
