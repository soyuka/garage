import { useEffect, useRef, useState, type FormEvent } from 'react';
import { ApiError } from '../api';
import { isAuthenticated, login } from '../auth';
import type { FinderApi } from '../client';
import { formatPlateInput, isPlate, isVinShape, normaliseVin } from '../format';
import type { Registration, VinDecode } from '../types';
import { Segmented, type EntryMode } from './Segmented';

interface Props {
  api: FinderApi;
  mode: EntryMode;
  onMode: (m: EntryMode) => void;
  onPlate: (plate: string) => void;
  error: string | null;
  demo: boolean;
}

export function Entry({ api, mode, onMode, onPlate, error, demo }: Props) {
  return (
    <section className="entry" aria-labelledby="entry-title">
      <h1 id="entry-title">Quel véhicule ?</h1>
      <p className="entry-lede">La plaque donne le type exact du véhicule, et donc les bonnes pièces.</p>
      <Segmented value={mode} onChange={onMode} label="Mode de saisie" />
      <div className="entry-panel">
        {mode === 'plate' && <PlateForm onPlate={onPlate} error={error} />}
        {mode === 'vin' && <VinForm api={api} onMode={onMode} />}
        {mode === 'card' && <CardForm api={api} onPlate={onPlate} demo={demo} />}
      </div>
    </section>
  );
}

function PlateForm({ onPlate, error }: { onPlate: (p: string) => void; error: string | null }) {
  const [plate, setPlate] = useState('');
  const [touched, setTouched] = useState(false);
  const ref = useRef<HTMLInputElement>(null);
  useEffect(() => ref.current?.focus(), []);
  const valid = isPlate(plate);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    setTouched(true);
    if (valid) onPlate(plate);
  };
  return (
    <form className="plate-form" onSubmit={submit} noValidate>
      <label htmlFor="plate" className="field-label">
        Plaque d’immatriculation
      </label>
      <div className="plate-row">
        <input
          ref={ref}
          id="plate"
          className="plate-input m"
          inputMode="text"
          autoComplete="off"
          autoCapitalize="characters"
          spellCheck={false}
          placeholder="AA-123-AA"
          value={plate}
          aria-invalid={touched && !valid}
          aria-describedby="plate-help"
          onChange={(e) => setPlate(formatPlateInput(e.target.value))}
        />
        <button type="submit" className="btn pri" disabled={plate === ''}>
          Identifier
        </button>
      </div>
      <p id="plate-help" className={touched && !valid ? 'field-error' : 'field-help'} role={error ? 'alert' : undefined}>
        {error ?? (touched && !valid ? 'Format attendu : deux lettres, trois chiffres, deux lettres (AA-123-AA).' : 'Format SIV, depuis 2009.')}
      </p>
    </form>
  );
}

function VinForm({ api, onMode }: { api: FinderApi; onMode: (m: EntryMode) => void }) {
  const [vin, setVin] = useState('');
  const [state, setState] = useState<{ status: 'idle' | 'loading' } | { status: 'ok'; data: VinDecode } | { status: 'error'; message: string }>({ status: 'idle' });
  const shaped = isVinShape(vin);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!shaped) return;
    setState({ status: 'loading' });
    try {
      setState({ status: 'ok', data: await api.vin(vin) });
    } catch (err) {
      setState({ status: 'error', message: err instanceof ApiError ? err.message : 'Décodage impossible.' });
    }
  };
  return (
    <form className="vin-form" onSubmit={submit} noValidate>
      <label htmlFor="vin" className="field-label">
        Numéro VIN (case E de la carte grise)
      </label>
      <div className="plate-row">
        <input
          id="vin"
          className="vin-input m"
          autoComplete="off"
          autoCapitalize="characters"
          spellCheck={false}
          maxLength={20}
          placeholder="17 caractères"
          value={vin}
          aria-describedby="vin-help"
          onChange={(e) => setVin(normaliseVin(e.target.value).slice(0, 17))}
        />
        <button type="submit" className="btn pri" disabled={!shaped || state.status === 'loading'}>
          {state.status === 'loading' ? 'Décodage…' : 'Décoder'}
        </button>
      </div>
      <p id="vin-help" className="field-help">
        <span className="m">{vin.length}</span>/17 · les lettres I, O et Q n’existent pas dans un VIN.
      </p>
      {state.status === 'error' && (
        <p className="field-error" role="alert">
          {state.message}
        </p>
      )}
      {state.status === 'ok' && <VinResult decode={state.data} onMode={onMode} />}
    </form>
  );
}

function VinResult({ decode, onMode }: { decode: VinDecode; onMode: (m: EntryMode) => void }) {
  if (!decode.valid) {
    return (
      <p className="field-error" role="alert">
        Ce VIN n’est pas valide{decode.error ? ` (${decode.error})` : ''}. Vérifiez la case E de la carte grise.
      </p>
    );
  }
  const year = decode.modelYear ?? (decode.modelYearCandidates.length === 1 ? decode.modelYearCandidates[0] : null);
  return (
    <div className="vin-result">
      <dl className="facts">
        <div>
          <dt>Constructeur</dt>
          <dd>{decode.manufacturer ?? 'Inconnu'}</dd>
        </div>
        <div>
          <dt>Pays</dt>
          <dd>{decode.country ?? '—'}</dd>
        </div>
        <div>
          <dt>Année modèle</dt>
          <dd className="m">{year ?? (decode.modelYearCandidates.length > 1 ? decode.modelYearCandidates.join(' ou ') : '—')}</dd>
        </div>
        <div>
          <dt>WMI</dt>
          <dd className="m">{decode.wmi ?? '—'}</dd>
        </div>
      </dl>
      <div className="ask">
        <p>Le VIN identifie le constructeur, pas le type exact du véhicule. Pour trouver les bonnes pièces, il faut la plaque ou la carte grise.</p>
        <div>
          <button type="button" className="btn" onClick={() => onMode('plate')}>
            Saisir la plaque
          </button>
          <button type="button" className="btn" onClick={() => onMode('card')}>
            Lire la carte grise
          </button>
        </div>
      </div>
    </div>
  );
}

const ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';

function CardForm({ api, onPlate, demo }: { api: FinderApi; onPlate: (p: string) => void; demo: boolean }) {
  const [over, setOver] = useState(false);
  const [state, setState] = useState<{ status: 'idle' } | { status: 'loading'; name: string } | { status: 'ok'; data: Registration } | { status: 'error'; message: string }>({ status: 'idle' });
  const authed = demo || isAuthenticated();

  const read = async (file: File | undefined) => {
    if (!file) return;
    if (!ACCEPT.split(',').includes(file.type)) {
      setState({ status: 'error', message: 'Format non pris en charge : photo (JPEG, PNG, WebP) ou PDF.' });
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      setState({ status: 'error', message: 'Fichier trop lourd : 10 Mo au maximum.' });
      return;
    }
    setState({ status: 'loading', name: file.name });
    try {
      setState({ status: 'ok', data: await api.extractRegistration(file) });
    } catch (err) {
      const kind = err instanceof ApiError ? err.kind : 'network';
      setState({
        status: 'error',
        message: kind === 'auth' ? 'Session expirée : reconnectez-vous.' : kind === 'upstream' ? 'La carte grise n’a pas pu être lue. Essayez une photo plus nette, à plat et bien éclairée.' : err instanceof Error ? err.message : 'Lecture impossible.',
      });
    }
  };

  if (!authed) {
    return (
      <div className="card-login">
        <p>La lecture d’une carte grise demande une connexion. Le document est lu puis oublié : il n’est jamais stocké.</p>
        <button type="button" className="btn pri" onClick={() => void login()}>
          Se connecter
        </button>
      </div>
    );
  }

  if (state.status === 'ok') return <CardResult data={state.data} onPlate={onPlate} onReset={() => setState({ status: 'idle' })} />;

  return (
    <div>
      <label
        className="drop"
        data-over={over}
        data-busy={state.status === 'loading'}
        onDragOver={(e) => {
          e.preventDefault();
          setOver(true);
        }}
        onDragLeave={() => setOver(false)}
        onDrop={(e) => {
          e.preventDefault();
          setOver(false);
          void read(e.dataTransfer.files[0]);
        }}
      >
        <input type="file" accept={ACCEPT} className="sr-only" disabled={state.status === 'loading'} onChange={(e) => void read(e.target.files?.[0])} />
        <svg viewBox="0 0 48 32" width="48" height="32" aria-hidden="true" className="drop-glyph">
          <rect x="1" y="1" width="46" height="30" rx="2" fill="none" stroke="currentColor" strokeWidth="1.5" />
          <path d="M7 9h14M7 14h20M7 19h10M31 9h10v14H31z" fill="none" stroke="currentColor" strokeWidth="1.5" />
        </svg>
        {state.status === 'loading' ? (
          <span className="drop-title" role="status">
            Lecture de la carte grise…
          </span>
        ) : (
          <>
            <span className="drop-title">Déposez une photo ou un PDF de la carte grise</span>
            <span className="drop-sub">ou cliquez pour choisir un fichier · 10 Mo max · jamais stocké</span>
          </>
        )}
      </label>
      {state.status === 'error' && (
        <p className="field-error" role="alert">
          {state.message}
        </p>
      )}
    </div>
  );
}

function CardResult({ data, onPlate, onReset }: { data: Registration; onPlate: (p: string) => void; onReset: () => void }) {
  return (
    <div className="card-result">
      {!data.legible && (
        <p className="field-error" role="alert">
          Document peu lisible : vérifiez chaque champ avant de continuer.
        </p>
      )}
      <table className="reg">
        <caption className="sr-only">Champs lus sur la carte grise</caption>
        <tbody>
          {data.fields.map((f) => (
            <tr key={f.code}>
              <th scope="row" className="m">
                {f.code}
              </th>
              <td>{f.label}</td>
              <td className={f.code === 'A' || f.code === 'E' ? 'm' : undefined}>{f.value}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {data.warnings.length > 0 && (
        <ul className="warnings">
          {data.warnings.map((w) => (
            <li key={w}>{w}</li>
          ))}
        </ul>
      )}
      <div className="card-actions">
        {data.plate ? (
          <button type="button" className="btn pri" onClick={() => onPlate(data.plate!)}>
            Rechercher <span className="m">{data.plate}</span>
          </button>
        ) : (
          <p className="field-error">Plaque (case A) illisible : saisissez-la à la main.</p>
        )}
        <button type="button" className="btn" onClick={onReset}>
          Autre document
        </button>
      </div>
    </div>
  );
}
