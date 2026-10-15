import { useState } from 'react';
import { isAuthenticated, login } from '../auth';
import type { FinderApi } from '../client';
import { atelierHref, PlanError, saveBody, type GarageCandidate } from '../garage';
import type { Vehicle } from '../types';
import { useToast } from './Toast';

// A 409 from the garage: the stored vehicles that may be this one, told apart by plate.
export function GaragePicker({ candidates, busy, onPick, onNew, onCancel }: { candidates: GarageCandidate[]; busy: boolean; onPick: (iri: string) => void; onNew: () => void; onCancel: () => void }) {
  return (
    <div className="pick" role="group" aria-labelledby="pick-title">
      <p id="pick-title">Plusieurs véhicules du garage peuvent correspondre : lequel ?</p>
      <ul>
        {candidates.map((c) => (
          <li key={c.iri}>
            <button type="button" className="btn" disabled={busy} onClick={() => onPick(c.iri)}>
              {c.plate ? <span className="m">{c.plate}</span> : <span className="tb-muted">sans plaque</span>}
              {c.name && <span>· {c.name}</span>}
            </button>
          </li>
        ))}
      </ul>
      <div className="pick-tail">
        <button type="button" className="btn btn--quiet" disabled={busy} onClick={onNew}>
          Un autre véhicule
        </button>
        <button type="button" className="btn btn--quiet" disabled={busy} onClick={onCancel}>
          Annuler
        </button>
      </div>
    </div>
  );
}

type SaveState = { kind: 'idle' } | { kind: 'saving' } | { kind: 'saved' } | { kind: 'login' } | { kind: 'pick'; candidates: GarageCandidate[] };

const SAVE_FAILURE: Record<string, string> = {
  invalid: 'Le garage a refusé ce véhicule : marque ou modèle manquant.',
  network: 'Garage injoignable : vérifiez la connexion et réessayez.',
  failed: 'L’enregistrement a échoué. Réessayez.',
  ambiguous: 'Plusieurs véhicules du garage correspondent.',
};

export function SaveVehicle({ vehicle, api }: { vehicle: Vehicle; api: FinderApi }) {
  const toast = useToast();
  const [state, setState] = useState<SaveState>({ kind: 'idle' });

  const save = async (extra: { vehicle?: string; confirmNew?: boolean } = {}) => {
    if (!isAuthenticated()) return setState({ kind: 'login' });
    setState({ kind: 'saving' });
    try {
      const saved = await api.saveVehicle({ ...saveBody(vehicle), ...extra });
      toast({ text: saved.created ? 'Véhicule enregistré dans le garage.' : 'Ce véhicule est déjà dans le garage.', link: { href: atelierHref(saved.vehicle, window.location.origin), label: 'Voir dans l’Atelier' } });
      setState({ kind: 'saved' });
    } catch (e) {
      if (e instanceof PlanError && e.kind === 'auth') return setState({ kind: 'login' });
      if (e instanceof PlanError && e.kind === 'ambiguous' && e.candidates.length > 0) return setState({ kind: 'pick', candidates: e.candidates });
      toast(SAVE_FAILURE[e instanceof PlanError ? e.kind : 'failed']!);
      setState({ kind: 'idle' });
    }
  };

  return (
    <div className="save">
      <button type="button" className="btn btn--quiet" aria-busy={state.kind === 'saving'} disabled={state.kind === 'saving' || state.kind === 'saved'} onClick={() => void save()}>
        {state.kind === 'saved' ? 'Enregistré' : state.kind === 'saving' ? 'Enregistrement…' : <>Enregistrer<span className="save-long">{'\u00a0le véhicule'}</span></>}
      </button>
      {state.kind === 'login' && (
        <div className="save-pop" role="group" aria-label="Connexion requise">
          <p>Le garage est lié à votre compte : connectez-vous, puis enregistrez le véhicule.</p>
          <div className="pick-tail">
            <button type="button" className="btn pri" onClick={() => void login()}>
              Se connecter
            </button>
            <button type="button" className="btn btn--quiet" onClick={() => setState({ kind: 'idle' })}>
              Annuler
            </button>
          </div>
        </div>
      )}
      {state.kind === 'pick' && (
        <div className="save-pop">
          <GaragePicker candidates={state.candidates} busy={false} onPick={(iri) => void save({ vehicle: iri })} onNew={() => void save({ confirmNew: true })} onCancel={() => setState({ kind: 'idle' })} />
        </div>
      )}
    </div>
  );
}
