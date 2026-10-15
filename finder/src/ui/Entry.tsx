import type { ReactNode } from 'react';
import type { Registration, VinDecode } from '../types';

export function Entry({ children }: { children?: ReactNode }) {
  return (
    <section className="entry" aria-labelledby="entry-title">
      <h1 id="entry-title">Quelle pièce, pour quel véhicule ?</h1>
      <p className="entry-lede">Saisissez la plaque ou le VIN, puis la pièce.</p>
      {children && <div className="entry-panel">{children}</div>}
    </section>
  );
}

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

export function CardResult({ data, onPlate, onReset }: { data: Registration; onPlate: (p: string) => void; onReset: () => void }) {
  return (
    <div className="card-result">
      {!data.legible && (
        <p className="field-error" role="alert">
          Document peu lisible. Vérifiez chaque champ.
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
          <p className="field-error">Plaque (case A) illisible. Saisissez-la.</p>
        )}
        <button type="button" className="btn" onClick={onReset}>
          Annuler
        </button>
      </div>
    </div>
  );
}
