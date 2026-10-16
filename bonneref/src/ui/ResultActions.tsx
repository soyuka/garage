import { useState } from 'react';
import { isAuthenticated, login } from '../auth';
import type { FinderApi } from '../client';
import { addLabel, garageHref, planBody, PlanError, type GarageCandidate } from '../garage';
import { actionRefs } from '../result';
import type { Category, Cluster, Family, Reference, Vehicle, WebPart } from '../types';
import { BuyMenu } from './BuyMenu';
import { copy } from './CopyRef';
import { GaragePicker } from './Garage';
import { useToast } from './Toast';

interface Props {
  vehicle: Vehicle;
  category: Category;
  family: Family;
  cluster: Cluster | null;
  clusters: readonly Cluster[];
  api: FinderApi;
}

// Under the composer on a result: what to do with the variant shown.
export function ResultActions({ vehicle, category, family, cluster, clusters, api }: Props) {
  const toast = useToast();
  const refs = cluster ? actionRefs(cluster, vehicle.make, clusters) : { oe: null, equivalent: null, buy: null };
  return (
    <div className="act" role="group" aria-label="Actions sur la pièce">
      {refs.buy && <BuyMenu reference={refs.buy} />}
      <AddToVehicle vehicle={vehicle} category={category} family={family} oe={refs.oe} equivalent={refs.equivalent} api={api} />
      <button type="button" className="btn" onClick={async () => toast((await copy(window.location.href)) ? 'Lien copié' : 'Copie impossible.')}>
        Copier le lien
      </button>
    </div>
  );
}

// A web find: no catalogue variant and no shareable route, so buying is its only dock action.
export function WebActions({ part }: { part: WebPart }) {
  return (
    <div className="act" role="group" aria-label="Actions sur la pièce">
      <BuyMenu reference={{ brand: part.brand || null, number: part.reference }} />
    </div>
  );
}

type AddState = { kind: 'idle' } | { kind: 'saving' } | { kind: 'login' } | { kind: 'pick'; candidates: GarageCandidate[] };

const PLAN_FAILURE: Record<string, string> = {
  ambiguous: 'Plusieurs véhicules correspondent dans le garage. Réessayez.',
  invalid: 'Refusé par le garage : marque ou modèle manquant.',
  network: 'Garage injoignable. Vérifiez la connexion et réessayez.',
  failed: 'Ajout au garage impossible. Réessayez.',
};

function AddToVehicle({ vehicle, category, family, oe, equivalent, api }: { vehicle: Vehicle; category: Category; family: Family; oe: Reference | null; equivalent: Reference | null; api: FinderApi }) {
  const toast = useToast();
  const [state, setState] = useState<AddState>({ kind: 'idle' });
  const label = addLabel(vehicle);

  const add = async (extra: { vehicle?: string; confirmNew?: boolean } = {}) => {
    if (!isAuthenticated()) return setState({ kind: 'login' });
    setState({ kind: 'saving' });
    try {
      const { vehicle: iri } = await api.planPart({ ...planBody(vehicle, category, family, oe, equivalent), ...extra });
      toast({ text: `${category.label} ajouté au plan d’entretien.`, link: { href: garageHref(iri), label: 'Voir le véhicule' } });
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
        <p>Connexion requise pour ajouter la pièce.</p>
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
