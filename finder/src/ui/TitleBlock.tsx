import { Fragment, useState } from 'react';
import { isAuthenticated, login } from '../auth';
import type { FinderApi } from '../client';
import { formatCount } from '../format';
import { addLabel, atelierHref, planBody, PlanError, type GarageCandidate } from '../garage';
import { copyText, cotesLine, degradedMessage, pickOe, rankEquivalents, sourceLabel } from '../result';
import type { Category, Cluster, Family, Narrowing, Reference, Vehicle } from '../types';
import { BuyMenu } from './BuyMenu';
import { GaragePicker } from './Garage';
import { useToast } from './Toast';
import { vehicleEngine, vehicleName } from './VehicleChip';

interface Props {
  vehicle: Vehicle;
  category: Category;
  narrowing: Narrowing;
  cluster: Cluster | null;
  confirmed: boolean;
  family: Family;
  api: FinderApi;
  demo: boolean;
  synthetic?: boolean;
  rationale?: string;
}

async function copy(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
}

export function TitleBlock({ vehicle, category, narrowing, cluster, confirmed, family, api, demo, synthetic = false, rationale }: Props) {
  const toast = useToast();
  const oe = cluster ? pickOe(cluster, vehicle.make) : null;
  const eq = cluster ? rankEquivalents(cluster) : null;
  const top = eq?.shown.find((v) => !oe || v.number !== oe.number) ?? null;
  const cotes = cluster ? cotesLine(cluster.dimensions) : null;
  const headline = oe ?? (cluster ? cluster.representative : null);
  const buyRef = oe ?? top ?? cluster?.representative ?? null;
  const fallback = !cluster ? narrowing.fallbackBrands.slice(0, 6) : [];
  const copyValue = oe ? copyText(oe, top) : cluster ? copyText(null, { ...cluster.representative, tier: 1 }) : '';

  return (
    <div className="tb" aria-label="Cartouche du résultat">
      <div className="c s3">
        <span className="l">Désignation</span>
        <b className="tb-title">{category.label}</b>
      </div>

      {headline ? (
        <div className="c s3">
          <span className="l">{oe ? `Réf. OE · ${oe.brand}` : `Réf. · ${headline.brand}`}</span>
          <div className="oe m">{headline.number}</div>
        </div>
      ) : (
        <div className="c s3">
          <span className="l">Référence</span>
          <div className="tb-muted">Pas de pièce unique identifiée</div>
        </div>
      )}

      {eq && eq.shown.length > 0 && (
        <div className="c s3">
          <span className="l">Équivalences · même pièce, autres marques</span>
          <div className="eq m">
            {eq.shown.map((v) => (
              <button
                key={v.artId}
                type="button"
                className="eq-ref"
                title={`Copier ${v.brand} ${v.number}`}
                onClick={async () => toast((await copy(`${v.brand} ${v.number}`)) ? `Copié : ${v.brand} ${v.number}` : 'Copie impossible.')}
              >
                <i>{v.brand}</i> {v.number}
              </button>
            ))}
            {eq.otherBrands > 0 && <span className="eq-more">+ {formatCount(eq.otherBrands)} autres marques</span>}
          </div>
        </div>
      )}

      {fallback.length > 0 && (
        <div className="c s3">
          <span className="l">Marques classées · sans affinage</span>
          <div className="eq m">
            {fallback.map((b) => (
              <span key={b.brand}>
                <i>{b.brand}</i> {b.articles[0]?.number}
              </span>
            ))}
          </div>
        </div>
      )}

      <div className="c s2">
        <span className="l">Véhicule</span>
        {vehicle.plate && <><span className="m tb-plate">{vehicle.plate}</span> · </>}{vehicleName(vehicle)} {vehicleEngine(vehicle) && <span className="tb-muted">· {vehicleEngine(vehicle)}</span>}
      </div>
      <div className="c r">
        <span className="l">kType</span>
        <span className="m tb-ktype">{vehicle.carId}</span>
      </div>

      <div className="c s2">
        <span className="l">Cotes</span>
        <span className="m tb-cotes">
          {cotes
            ? cotes.split(' · ').map((part, i) => (
                <Fragment key={i}>
                  {i > 0 && ' · '}
                  <span>{part}</span>
                </Fragment>
              ))
            : <span className="tb-muted">non renseignées par le catalogue</span>}
        </span>
      </div>
      <div className="c r">
        <span className="l">Source</span>
        <span className={synthetic && demo ? 'tag' : 'tag ok'}>{sourceLabel({ demo, synthetic })}</span>
        {!narrowing.narrowed && <span className="tag warn">affinage partiel</span>}
      </div>

      <div className="c s3 why">
        <span className="l">Pourquoi cette pièce</span>
        {rationale && <span className="why-agent">{rationale}</span>}
        {whyLine(narrowing, cluster, confirmed)}
        {narrowing.degradedReason && <span className="why-degraded">{degradedMessage(narrowing.degradedReason)}</span>}
        {narrowing.unclassifiedCount > 0 && cluster && (
          <span className="why-quiet">{formatCount(narrowing.unclassifiedCount)} références non rattachées à une pièce.</span>
        )}
      </div>

      <div className="act">
        <button
          type="button"
          className="btn pri"
          disabled={!headline}
          onClick={async () => toast((await copy(copyValue)) ? `Copié : ${copyValue}` : 'Copie impossible : sélectionnez la référence à la main.')}
        >
          Copier la référence
        </button>
        {buyRef && <BuyMenu reference={buyRef} />}
        <AddToVehicle vehicle={vehicle} category={category} family={family} oe={oe} equivalent={top} api={api} demo={demo} />
        <button type="button" className="btn" onClick={async () => toast((await copy(window.location.href)) ? 'Lien copié' : 'Copie impossible.')}>
          Partager
        </button>
      </div>
    </div>
  );
}

type AddState = { kind: 'idle' } | { kind: 'saving' } | { kind: 'login' } | { kind: 'pick'; candidates: GarageCandidate[] };

const PLAN_FAILURE: Record<string, string> = {
  ambiguous: 'Plusieurs véhicules du garage correspondent : ouvrez l’Atelier pour choisir le bon, puis réessayez.',
  invalid: 'Le garage a refusé ce véhicule : marque ou modèle manquant.',
  network: 'Garage injoignable : vérifiez la connexion et réessayez.',
  failed: 'L’ajout au garage a échoué. Réessayez.',
};

function AddToVehicle({ vehicle, category, family, oe, equivalent, api, demo }: { vehicle: Vehicle; category: Category; family: Family; oe: Reference | null; equivalent: Reference | null; api: FinderApi; demo: boolean }) {
  const toast = useToast();
  const [state, setState] = useState<AddState>({ kind: 'idle' });
  const label = addLabel(vehicle);

  const add = async (extra: { vehicle?: string; confirmNew?: boolean } = {}) => {
    if (!demo && !isAuthenticated()) return setState({ kind: 'login' });
    setState({ kind: 'saving' });
    try {
      const { vehicle: iri } = await api.planPart({ ...planBody(vehicle, category, family, oe, equivalent), ...extra });
      toast({ text: `${category.label} ajouté au plan d’entretien.`, link: { href: atelierHref(iri, window.location.origin), label: 'Voir dans l’Atelier' } });
      setState({ kind: 'idle' });
    } catch (e) {
      if (e instanceof PlanError && e.kind === 'auth') return setState({ kind: 'login' });
      if (e instanceof PlanError && e.kind === 'ambiguous' && e.candidates.length > 0) return setState({ kind: 'pick', candidates: e.candidates });
      toast(PLAN_FAILURE[e instanceof PlanError ? e.kind : 'failed']!);
      setState({ kind: 'idle' });
    }
  };

  if (state.kind === 'login') {
    return (
      <div className="add-login" role="group" aria-label="Connexion requise">
        <p>Le garage est lié à votre compte : connectez-vous, puis ajoutez la pièce.</p>
        <button type="button" className="btn pri" onClick={() => void login()}>
          Se connecter
        </button>
        <button type="button" className="btn btn--quiet" onClick={() => setState({ kind: 'idle' })}>
          Annuler
        </button>
      </div>
    );
  }
  if (state.kind === 'pick') {
    return <GaragePicker candidates={state.candidates} busy={false} onPick={(iri) => void add({ vehicle: iri })} onNew={() => void add({ confirmNew: true })} onCancel={() => setState({ kind: 'idle' })} />;
  }
  return (
    <button type="button" className="btn" disabled={!oe && !equivalent} aria-busy={state.kind === 'saving'} onClick={() => void add()}>
      {state.kind === 'saving' ? 'Ajout…' : label}
    </button>
  );
}

function whyLine(n: Narrowing, cluster: Cluster | null, confirmed: boolean) {
  if (!cluster) return <span>Le catalogue n’a pas pu regrouper les références en pièces distinctes.</span>;
  const brands = new Set(cluster.variants.map((v) => v.brand)).size;
  const same = `${formatCount(cluster.articleCount)} références de ${formatCount(brands)} marque${brands > 1 ? 's' : ''} désignent cette même pièce.`;
  if (n.clusters.length > 1 && !confirmed) {
    return (
      <span>
        {n.clusters.length} pièces possibles pour ce véhicule : répondez à la question pour confirmer. <span className="tag">à confirmer</span>
      </span>
    );
  }
  return (
    <span>
      {same} {n.clusters.length > 1 && <span className="tag ok">confirmé par la cote</span>}
    </span>
  );
}
