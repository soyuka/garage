import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ApiError } from '../api';
import { isAuthenticated, login } from '../auth';
import { formatDate, formatMeasure, formatNumber } from '../format';
import {
  deleteFact,
  deleteGarageVehicle,
  getGarageVehicle,
  listGarageVehicles,
  listPlannedParts,
  myVehicleOf,
  type GarageRow,
  type GarageSheet,
  type PlannedItem,
} from '../garage-vehicles';
import type { MyVehicle } from '../my-vehicles';
import { routeToHash, type Route } from '../route';
import { useAsync, type Navigate } from './hooks';
import { useToast } from './Toast';

type GarageRoute = Extract<Route, { view: 'garage' | 'garageVehicle' }>;

const KIND: Record<string, string> = { car: 'Voiture', motorcycle: 'Moto', cv: 'Utilitaire', groundscare: 'Motoculture' };

const engineOf = (v: GarageRow): string | null => [v.engineVariant, v.engineCode].filter(Boolean).join(' · ') || null;
const usageOf = (v: GarageRow): string | null => (v.usage ? formatMeasure(String(v.usage.value), v.usage.unit) : null);

function Plate({ plate }: { plate: string | null }) {
  return plate ? <span className="m plate">{plate}</span> : <span className="tb-muted">sans plaque</span>;
}

function KType({ v }: { v: GarageRow }) {
  return v.carId !== null ? (
    <span className="m">
      {v.carId}
      <span className="tb-muted"> · {v.carType}</span>
    </span>
  ) : (
    <span className="tb-muted">hors catalogue</span>
  );
}

function Cell({ label, className, children }: { label: string; className?: string; children: ReactNode }) {
  return (
    <div className={['c', className].filter(Boolean).join(' ')}>
      <span className="l">{label}</span>
      <div className="gc-v">{children}</div>
    </div>
  );
}

const missing = <span className="tb-muted">non renseigné</span>;

export function GarageList({ rows }: { rows: readonly GarageRow[] }) {
  if (rows.length === 0) {
    return (
      <div className="garage-empty">
        <p>Aucun véhicule dans le garage.</p>
        <p className="tb-muted">Sur un résultat, « Enregistrer le véhicule » l’ajoute ici.</p>
      </div>
    );
  }
  return (
    <ol className="garage-list">
      {rows.map((v) => (
        <li key={v.id}>
          <a className="gtb gtb--card" href={routeToHash({ view: 'garageVehicle', id: v.id })}>
            <Cell label="Immatriculation" className="gc-plate">
              <Plate plate={v.plate} />
            </Cell>
            <Cell label="Véhicule" className="gc-name">
              <span className="gtb-title">{v.name ?? 'Véhicule sans nom'}</span>
            </Cell>
            <Cell label="Année" className="gc-year r">
              {v.year !== null ? <span className="m">{v.year}</span> : missing}
            </Cell>
            <Cell label="Motorisation" className="gc-engine nb">
              {engineOf(v) ?? missing}
            </Cell>
            <Cell label="kType" className="gc-ktype r nb">
              <KType v={v} />
            </Cell>
          </a>
        </li>
      ))}
    </ol>
  );
}

function ListSkeleton() {
  return (
    <ol className="garage-list" aria-hidden="true">
      {[0, 1, 2].map((i) => (
        <li key={i}>
          <div className="gtb gtb--card tb--skeleton">
            <div className="c gc-plate"><span className="l">Immatriculation</span><span className="sk" style={{ width: '70%' }} /></div>
            <div className="c gc-name"><span className="l">Véhicule</span><span className="sk" style={{ width: '50%' }} /></div>
            <div className="c gc-year r"><span className="l">Année</span><span className="sk" style={{ width: '60%' }} /></div>
            <div className="c gc-engine nb"><span className="l">Motorisation</span><span className="sk" style={{ width: '40%' }} /></div>
            <div className="c gc-ktype r nb"><span className="l">kType</span><span className="sk" style={{ width: '60%' }} /></div>
          </div>
        </li>
      ))}
    </ol>
  );
}

// Two steps in place: the button becomes the question with its confirm and cancel. No browser dialog.
export function ConfirmAction({ label, question, confirm, busy, onConfirm, quiet = false, icon = null }: { label: string; question: string; confirm: string; busy: boolean; onConfirm: () => void; quiet?: boolean; icon?: ReactNode }) {
  const [asking, setAsking] = useState(false);
  const opener = useRef<HTMLButtonElement>(null);
  if (!asking && !busy) {
    return (
      <button ref={opener} type="button" className={icon ? 'garage-x' : quiet ? 'btn btn--quiet' : 'btn'} aria-label={icon ? label : undefined} onClick={() => setAsking(true)}>
        {icon ?? label}
      </button>
    );
  }
  const size = icon || quiet ? 'btn btn--quiet' : 'btn';
  return (
    <span className="confirm" role="group" aria-label={question}>
      <span className="confirm-q">{question}</span>
      <button type="button" className={`${size} btn--danger`} disabled={busy} aria-busy={busy} autoFocus onClick={onConfirm}>
        {busy ? 'Suppression…' : confirm}
      </button>
      <button type="button" className={size} disabled={busy} onClick={() => setAsking(false)}>
        Annuler
      </button>
    </span>
  );
}

const CROSS = (
  <svg viewBox="0 0 12 12" width="12" height="12" aria-hidden="true">
    <path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" strokeWidth="1.5" fill="none" strokeLinecap="round" />
  </svg>
);

interface SheetViewProps {
  sheet: GarageSheet;
  planned: readonly PlannedItem[];
  plannedLoading: boolean;
  onSearch: (v: MyVehicle) => void;
  onDeleteVehicle: () => void;
  onDeleteFact: (key: string) => void;
  deletingFact: string | null;
  deletingVehicle: boolean;
  error: string | null;
}

export function GarageSheetView({ sheet, planned, plannedLoading, onSearch, onDeleteVehicle, onDeleteFact, deletingFact, deletingVehicle, error }: SheetViewProps) {
  const v = sheet.vehicle;
  const target = myVehicleOf(v);
  return (
    <>
      <div className="garage-head">
        <h1>{v.name ?? 'Véhicule sans nom'}</h1>
        <div className="garage-acts">
          {target && (
            <button type="button" className="btn pri" onClick={() => onSearch(target)}>
              Chercher une pièce
            </button>
          )}
          <ConfirmAction label="Supprimer le véhicule" question={`Retirer ${v.plate ?? 'ce véhicule'} du garage ?`} confirm="Supprimer" busy={deletingVehicle} onConfirm={onDeleteVehicle} />
        </div>
      </div>
      {error && (
        <p className="field-error" role="alert">
          {error}
        </p>
      )}

      <div className="gtb gtb--sheet">
        <Cell label="Immatriculation" className="gs-plate">
          <Plate plate={v.plate} />
        </Cell>
        <Cell label="VIN" className="gs-vin">
          {v.vin ? <span className="m">{v.vin}</span> : missing}
        </Cell>
        <Cell label="Année" className="gs-year r">
          {v.year !== null ? <span className="m">{v.year}</span> : missing}
        </Cell>
        <Cell label="Marque et modèle" className="gs-name">
          {[v.make, v.model].filter(Boolean).join(' ') || missing}
        </Cell>
        <Cell label="Motorisation" className="gs-engine r">
          {v.engineVariant ?? missing}
        </Cell>
        <Cell label="Code moteur" className="gs-code nb">
          {v.engineCode ? <span className="m">{v.engineCode}</span> : missing}
        </Cell>
        <Cell label="kType" className="gs-ktype nb">
          <KType v={v} />
          {v.vehicleType && KIND[v.vehicleType] && <span className="gs-kind">{KIND[v.vehicleType]}</span>}
        </Cell>
        <Cell label="Utilisation" className="gs-usage nb">
          {usageOf(v) ? <span className="m">{usageOf(v)}</span> : missing}
        </Cell>
        <Cell label="Ajouté le" className="gs-added r nb">
          {v.addedAt ? formatDate(v.addedAt) : missing}
        </Cell>
      </div>

      <section className="garage-sec" aria-labelledby="facts-title">
        <div className="garage-sec-head">
          <h2 id="facts-title">Caractéristiques</h2>
          {sheet.facts.length > 0 && <span className="m tb-muted">{formatNumber(sheet.facts.length)}</span>}
        </div>
        {sheet.facts.length === 0 ? (
          <p className="garage-none">Aucune caractéristique enregistrée.</p>
        ) : (
          <ul className="gfacts">
            <li className="gf-row gf-row--head" aria-hidden="true">
              <span>Caractéristique</span>
              <span>Valeur</span>
              <span>Source</span>
              <span />
            </li>
            {sheet.facts.map((f) => (
              <li key={f.key} className="gf-row" data-busy={deletingFact === f.key || undefined}>
                <span className="gf-label">{f.label}</span>
                <span className="gf-value m">{f.value}</span>
                <span className="gf-source">
                  {f.source && f.url ? (
                    <a href={f.url} target="_blank" rel="noopener noreferrer">
                      {f.source}
                    </a>
                  ) : (
                    f.source
                  )}
                </span>
                <span className="gf-del">
                  <ConfirmAction label={`Supprimer ${f.label}`} question="Supprimer ?" confirm="Supprimer" busy={deletingFact === f.key} onConfirm={() => onDeleteFact(f.key)} icon={CROSS} />
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="garage-sec" aria-labelledby="planned-title">
        <div className="garage-sec-head">
          <h2 id="planned-title">Pièces prévues</h2>
        </div>
        {plannedLoading ? (
          <p className="garage-none">Chargement…</p>
        ) : planned.length === 0 ? (
          <p className="garage-none">Aucune pièce prévue. Une pièce ajoutée à ce véhicule depuis un résultat apparaît ici.</p>
        ) : (
          <ul className="gplanned">
            {planned.map((t) => (
              <li key={t.id}>
                <span className="gp-task">{t.label}</span>
                {t.parts.length === 0 ? (
                  <span className="tb-muted">Aucune référence</span>
                ) : (
                  <ul className="gp-parts">
                    {t.parts.map((p, i) => (
                      <li key={`${p.ref}-${i}`}>
                        {p.brand && <i>{p.brand}</i>}
                        <span className="m">{p.ref}</span>
                        {p.label && <span className="gp-label">{p.label}</span>}
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>
    </>
  );
}

function SheetSkeleton() {
  return (
    <div aria-hidden="true">
      <div className="garage-head">
        <span className="sk sk--big" style={{ width: '40%' }} />
      </div>
      <div className="gtb gtb--sheet tb--skeleton">
        {['Immatriculation', 'VIN', 'Année', 'Marque et modèle', 'Motorisation', 'Code moteur', 'kType', 'Utilisation', 'Ajouté le'].map((l, i) => (
          <div key={l} className={`c ${['gs-plate', 'gs-vin', 'gs-year r', 'gs-name', 'gs-engine r', 'gs-code nb', 'gs-ktype nb', 'gs-usage nb', 'gs-added r nb'][i]}`}>
            <span className="l">{l}</span>
            <span className="sk" style={{ width: '60%' }} />
          </div>
        ))}
      </div>
    </div>
  );
}

export function GarageLogin({ expired }: { expired: boolean }) {
  return (
    <div className="garage-login" role="group" aria-labelledby="garage-login-title">
      <p id="garage-login-title">{expired ? 'Session expirée : reconnectez-vous pour voir le garage.' : 'Connectez-vous pour voir les véhicules de votre garage.'}</p>
      <button type="button" className="btn pri" onClick={() => void login()}>
        Se connecter
      </button>
    </div>
  );
}

const isAuth = (e: unknown): boolean => e instanceof ApiError && e.kind === 'auth';

function ListPage() {
  const rows = useAsync('garage', listGarageVehicles);
  return (
    <>
      <div className="garage-head">
        <h1>Garage</h1>
        {rows.status === 'ok' && rows.data.length > 0 && (
          <span className="garage-count">
            <span className="m">{formatNumber(rows.data.length)}</span> véhicule{rows.data.length > 1 ? 's' : ''}
          </span>
        )}
      </div>
      {rows.status === 'error' ? (
        rows.error.kind === 'auth' ? (
          <GarageLogin expired />
        ) : (
          <Failure message="Garage injoignable. Vérifiez la connexion et réessayez." onRetry={rows.retry} />
        )
      ) : rows.status === 'ok' ? (
        <GarageList rows={rows.data} />
      ) : (
        <ListSkeleton />
      )}
    </>
  );
}

function Failure({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="garage-login" role="alert">
      <p>{message}</p>
      <button type="button" className="btn" onClick={onRetry}>
        Réessayer
      </button>
    </div>
  );
}

function VehiclePage({ id, navigate, onSearch }: { id: string; navigate: Navigate; onSearch: (v: MyVehicle) => void }) {
  const toast = useToast();
  const sheet = useAsync(id, () => getGarageVehicle(id));
  const planned = useAsync(`${id}-planned`, () => listPlannedParts(id));
  // A refresh after a fact delete keeps the sheet on screen instead of flashing the skeleton.
  const last = useRef<GarageSheet | null>(null);
  if (sheet.status === 'ok') last.current = sheet.data;
  const loaded = sheet.status === 'ok' ? sheet.data : sheet.status === 'loading' && last.current?.vehicle.id === id ? last.current : null;
  // A deleted fact leaves at once; the refreshed sheet then no longer carries it.
  const [gone, setGone] = useState<string[]>([]);
  useEffect(() => setGone([]), [sheet.status === 'ok' ? sheet.data : null]);
  const shown = loaded && gone.length > 0 ? { ...loaded, facts: loaded.facts.filter((f) => !gone.includes(f.key)) } : loaded;
  const [deletingFact, setDeletingFact] = useState<string | null>(null);
  const [deletingVehicle, setDeletingVehicle] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [expired, setExpired] = useState(false);

  const removeFact = async (key: string) => {
    setError(null);
    setDeletingFact(key);
    try {
      await deleteFact(id, key);
      setGone((g) => [...g, key]);
      sheet.retry();
    } catch (e) {
      if (isAuth(e)) setExpired(true);
      else if (e instanceof ApiError && e.kind === 'notFound') sheet.retry();
      else setError('Suppression impossible. Réessayez.');
    } finally {
      setDeletingFact(null);
    }
  };

  const removeVehicle = async () => {
    setError(null);
    setDeletingVehicle(true);
    try {
      await deleteGarageVehicle(id);
      toast('Véhicule retiré du garage.');
      navigate({ view: 'garage' });
    } catch (e) {
      setDeletingVehicle(false);
      if (isAuth(e)) setExpired(true);
      else if (e instanceof ApiError && e.kind === 'notFound') navigate({ view: 'garage' });
      else setError('Suppression impossible. Réessayez.');
    }
  };

  const crumb = (
    <nav className="garage-crumb" aria-label="Fil d’Ariane">
      <a href={routeToHash({ view: 'garage' })}>Garage</a>
      {shown?.vehicle.plate && (
        <>
          <span aria-hidden="true"> / </span>
          <span className="m">{shown.vehicle.plate}</span>
        </>
      )}
    </nav>
  );

  if (expired || (sheet.status === 'error' && sheet.error.kind === 'auth')) return <>{crumb}<GarageLogin expired /></>;
  if (sheet.status === 'error' && sheet.error.kind === 'notFound') {
    return (
      <>
        {crumb}
        <div className="garage-login">
          <p>Ce véhicule n’est pas, ou plus, dans le garage.</p>
          <a className="btn" href={routeToHash({ view: 'garage' })}>
            Voir le garage
          </a>
        </div>
      </>
    );
  }
  if (sheet.status === 'error') return <>{crumb}<Failure message="Garage injoignable. Vérifiez la connexion et réessayez." onRetry={sheet.retry} /></>;
  if (!shown) return <>{crumb}<SheetSkeleton /></>;
  return (
    <>
      {crumb}
      <GarageSheetView
        sheet={shown}
        planned={planned.status === 'ok' ? planned.data : []}
        plannedLoading={planned.status === 'loading' || planned.status === 'idle'}
        onSearch={onSearch}
        onDeleteVehicle={() => void removeVehicle()}
        onDeleteFact={(key) => void removeFact(key)}
        deletingFact={deletingFact}
        deletingVehicle={deletingVehicle}
        error={error}
      />
    </>
  );
}

export function GaragePage({ route, navigate, onSearch }: { route: GarageRoute; navigate: Navigate; onSearch: (v: MyVehicle) => void }) {
  return (
    <div className="garage">
      {!isAuthenticated() ? (
        <>
          <div className="garage-head">
            <h1>Garage</h1>
          </div>
          <GarageLogin expired={false} />
        </>
      ) : route.view === 'garage' ? (
        <ListPage />
      ) : (
        <VehiclePage key={route.id} id={route.id} navigate={navigate} onSearch={onSearch} />
      )}
    </div>
  );
}
