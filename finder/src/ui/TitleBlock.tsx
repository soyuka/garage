import { Fragment, useState } from 'react';
import { formatCount } from '../format';
import { copyText, cotesLine, degradedMessage, foldEquivalents, pickOe, rankEquivalents } from '../result';
import type { Category, Narrowing, Vehicle } from '../types';
import { makerOe, otherDimensions, variantLetter, type Selection } from '../variants';
import { CopyRef } from './CopyRef';
import { VariantTable } from './VariantTable';

interface Props {
  vehicle: Vehicle;
  category: Category;
  narrowing: Narrowing;
  selection: Selection;
  onPick: (clusterId: string) => void;
  rationale?: string;
}

const EQ_KEEP = 2;

export function TitleBlock({ vehicle, category, narrowing, selection, onPick, rationale }: Props) {
  const { cluster } = selection;
  const multi = narrowing.clusters.length > 1 && cluster !== null;
  const letter = multi ? variantLetter(selection.index) : null;
  const otherOe = multi ? makerOe(cluster, vehicle.make, narrowing.clusters) : [];
  const otherCotes = multi ? otherDimensions(cluster, narrowing.differsOn) : [];
  const oe = cluster ? pickOe(cluster, vehicle.make, narrowing.clusters) : null;
  const eq = cluster ? rankEquivalents(cluster) : null;
  const cotes = cluster ? cotesLine(cluster.dimensions) : null;
  const headline = oe ?? (cluster ? cluster.representative : null);
  const fallback = !cluster ? narrowing.fallbackBrands.slice(0, 6) : [];
  const copyValue = copyText(oe, cluster?.representative ?? null);
  const [eqOpen, setEqOpen] = useState(false);
  const folded = eq && multi && !eqOpen ? foldEquivalents(eq, EQ_KEEP) : null;
  const eqShown = folded ? folded.visible : (eq?.shown ?? []);

  return (
    <div className={multi ? 'tb tb--multi' : 'tb'} aria-label="Cartouche du résultat">
      <div className="c s3 tb-head">
        <span className="l">Désignation</span>
        <span className="tb-prov" aria-label="Source">
          <span className="tag ok">TecDoc</span>
          {!narrowing.narrowed && <span className="tag warn">affinage partiel</span>}
        </span>
        <b className="tb-title">{category.label}</b>
        {narrowing.degradedReason && <p className="tb-degraded">{degradedMessage(narrowing.degradedReason)}</p>}
      </div>

      {multi && <VariantTable narrowing={narrowing} make={vehicle.make} selection={selection} onPick={onPick} />}

      {headline ? (
        <div key={`oe-${cluster?.id ?? 'none'}`} className="c s3 tb-swap">
          <span className="l">
            {oe ? `Réf. OE · ${oe.brand}` : `Réf. · ${headline.brand}`}
            {letter && ` · variante ${letter}`}
          </span>
          <div className="oe-line">
            <div className="oe m">{headline.number}</div>
            <CopyRef text={copyValue} />
          </div>
          {otherOe.length > 0 && (
            <div className="tb-oe-more">
              <span className="tb-muted">Aussi :</span> <span className="m">{otherOe.map((r) => r.number).join(' · ')}</span>
            </div>
          )}
        </div>
      ) : (
        <div className="c s3">
          <span className="l">Référence</span>
          <div className="tb-muted">Aucune référence unique</div>
        </div>
      )}

      {eq && eq.shown.length > 0 && (
        <div key={`eq-${cluster?.id ?? 'none'}`} className="c s3 tb-swap">
          <span className="l">Équivalences</span>
          <div className="eq m">
            {eqShown.map((v) => (
              <span key={v.artId} className="eq-ref">
                <i>{v.brand}</i> {v.number}
                <CopyRef text={v.number} label={`${v.number} (${v.brand})`} />
              </span>
            ))}
            {folded ? (
              folded.hidden > 0 && (
                <button type="button" className="eq-more eq-toggle" aria-expanded={false} onClick={() => setEqOpen(true)}>
                  + {formatCount(folded.hidden)} autres marques
                </button>
              )
            ) : (
              <>
                {eq.otherBrands > 0 && <span className="eq-more">+ {formatCount(eq.otherBrands)} autres marques</span>}
                {multi && (
                  <button type="button" className="eq-more eq-toggle" aria-expanded={true} onClick={() => setEqOpen(false)}>
                    Réduire
                  </button>
                )}
              </>
            )}
          </div>
        </div>
      )}

      {fallback.length > 0 && (
        <div className="c s3">
          <span className="l">Marques (non affiné)</span>
          <div className="eq m">
            {fallback.map((b) => (
              <span key={b.brand}>
                <i>{b.brand}</i> {b.articles[0]?.number}
              </span>
            ))}
          </div>
        </div>
      )}

      <div key={`cotes-${cluster?.id ?? 'none'}`} className="c s3 tb-swap">
        <span className="l">Cotes</span>
        <span className="m tb-cotes">
          {cotes
            ? cotes.split(' · ').map((part, i) => (
                <Fragment key={i}>
                  {i > 0 && ' · '}
                  <span>{part}</span>
                </Fragment>
              ))
            : otherCotes.length === 0 && <span className="tb-muted">non renseignées par le catalogue</span>}
        </span>
        {otherCotes.length > 0 && (
          <ul className="tb-cotes-more">
            {otherCotes.map((d) => (
              <li key={d.key} data-differs={d.differs || undefined}>
                {d.label} <span className="m">{d.text}</span>
              </li>
            ))}
          </ul>
        )}
      </div>

      {!multi && rationale && (
        <div className="c s3 tb-foot">
          <p className="tb-note">{rationale}</p>
        </div>
      )}
    </div>
  );
}
