import { formatCount } from '../format';
import { copyText, cotesLine, degradedMessage, pickOe, rankEquivalents } from '../result';
import type { Category, Cluster, Narrowing, Vehicle } from '../types';
import { BuyMenu } from './BuyMenu';
import { useToast } from './Toast';
import { vehicleEngine, vehicleName } from './VehicleChip';

interface Props {
  vehicle: Vehicle;
  category: Category;
  narrowing: Narrowing;
  cluster: Cluster | null;
  confirmed: boolean;
}

async function copy(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
}

export function TitleBlock({ vehicle, category, narrowing, cluster, confirmed }: Props) {
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
              <span key={v.artId}>
                <i>{v.brand}</i> {v.number}
              </span>
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
        <span className="m tb-plate">{vehicle.plate}</span> · {vehicleName(vehicle)} {vehicleEngine(vehicle) && <span className="tb-muted">· {vehicleEngine(vehicle)}</span>}
      </div>
      <div className="c r">
        <span className="l">kType</span>
        <span className="m tb-ktype">{vehicle.carId}</span>
      </div>

      <div className="c s2">
        <span className="l">Cotes</span>
        <span className="m tb-cotes">{cotes ?? '—'}</span>
      </div>
      <div className="c r">
        <span className="l">Source</span>
        <span className="tag ok">TecDoc</span>
        {!narrowing.narrowed && <span className="tag warn">affinage partiel</span>}
      </div>

      <div className="c s3 why">
        <span className="l">Pourquoi cette pièce</span>
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
        <button type="button" className="btn" onClick={() => toast('Bientôt : ajout de la pièce au véhicule dans l’Atelier.')}>
          Ajouter au véhicule
        </button>
        <button type="button" className="btn" onClick={async () => toast((await copy(window.location.href)) ? 'Lien copié' : 'Copie impossible.')}>
          Partager
        </button>
      </div>
    </div>
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
