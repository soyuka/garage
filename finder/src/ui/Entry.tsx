import type { ReactNode } from 'react';
import { routeText, type TextRoute } from '../route-input';
import type { Registration, VinDecode } from '../types';
import { Intake } from './Intake';

interface Props {
  context: string | null;
  busy: boolean;
  onText: (route: TextRoute, typed: string) => void;
  onFiles: (files: File[]) => void;
  examples: string[];
  children?: ReactNode;
}

export function Entry({ context, busy, onText, onFiles, examples, children }: Props) {
  return (
    <section className="entry" aria-labelledby="entry-title">
      <h1 id="entry-title">Quelle pièce, pour quel véhicule ?</h1>
      <p className="entry-lede">Un seul champ : la plaque, le VIN, une référence, le nom d’une pièce ou un symptôme. Ou une photo de la pièce, de la carte grise, une courte vidéo.</p>
      <Intake variant="hero" categories={null} initial="" context={context} busy={busy} autoFocus onText={onText} onFiles={onFiles} />
      {examples.length > 0 && (
        <p className="entry-examples">
          <span>Exemples</span>
          {examples.map((x) => (
            <button key={x} type="button" className="link-btn" onClick={() => onText(routeText(x, null), x)}>
              {x}
            </button>
          ))}
        </p>
      )}
      {children && <div className="entry-panel">{children}</div>}
    </section>
  );
}

export function VinResult({ decode }: { decode: VinDecode }) {
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
      <p className="entry-note">Le VIN donne le constructeur, pas le type exact du véhicule. Ajoutez la plaque, ou une photo de la carte grise, dans le champ.</p>
    </div>
  );
}

export function CardResult({ data, onPlate, onReset }: { data: Registration; onPlate: (p: string) => void; onReset: () => void }) {
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
          Annuler
        </button>
      </div>
    </div>
  );
}
