import type { VinDecode } from '../types';

export function VinResult({ decode }: { decode: VinDecode }) {
  if (!decode.valid) {
    return (
      <p className="field-error" role="alert">
        VIN invalide{decode.error ? ` (${decode.error})` : ''}. Vérifiez la case E de la carte grise.
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
      <p className="entry-note">Le VIN ne suffit pas à trouver le kType. Saisissez la plaque ou envoyez la carte grise en photo.</p>
    </div>
  );
}
