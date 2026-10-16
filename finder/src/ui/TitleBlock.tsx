import { Fragment, useLayoutEffect, useRef, useState } from 'react';
import { isAuthenticated, login } from '../auth';
import type { FinderApi } from '../client';
import { formatCount } from '../format';
import { addLabel, atelierHref, planBody, PlanError, type GarageCandidate } from '../garage';
import { copyText, cotesLine, degradedMessage, foldEquivalents, pickOe, rankEquivalents } from '../result';
import type { Category, Family, Narrowing, Reference, Vehicle } from '../types';
import { makerOe, otherDimensions, variantLetter, type Selection } from '../variants';
import { BuyMenu } from './BuyMenu';
import { copy, CopyRef } from './CopyRef';
import { GaragePicker } from './Garage';
import { useToast } from './Toast';
import { VariantTable } from './VariantTable';
import { vehicleEngine, vehicleName } from './VehicleChip';

interface Props {
  vehicle: Vehicle;
  category: Category;
  narrowing: Narrowing;
  selection: Selection;
  onPick: (clusterId: string) => void;
  family: Family;
  api: FinderApi;
  rationale?: string;
}

const EQ_KEEP = 2;

export function TitleBlock({ vehicle, category, narrowing, selection, onPick, family, api, rationale }: Props) {
  const toast = useToast();
  const { cluster } = selection;
  const multi = narrowing.clusters.length > 1 && cluster !== null;
  const letter = multi ? variantLetter(selection.index) : null;
  const otherOe = multi ? makerOe(cluster, vehicle.make, narrowing.clusters) : [];
  const otherCotes = multi ? otherDimensions(cluster, narrowing.differsOn) : [];
  const oe = cluster ? pickOe(cluster, vehicle.make, narrowing.clusters) : null;
  const eq = cluster ? rankEquivalents(cluster) : null;
  const top = eq?.shown.find((v) => !oe || v.number !== oe.number) ?? null;
  const cotes = cluster ? cotesLine(cluster.dimensions) : null;
  const headline = oe ?? (cluster ? cluster.representative : null);
  const buyRef = oe ?? top ?? cluster?.representative ?? null;
  const fallback = !cluster ? narrowing.fallbackBrands.slice(0, 6) : [];
  const copyValue = copyText(oe, cluster?.representative ?? null);
  const [eqOpen, setEqOpen] = useState(false);
  const [whyOpen, setWhyOpen] = useState(false);
  const whyBody = useRef<HTMLDivElement>(null);
  const [whyLong, setWhyLong] = useState(false);
  const folded = eq && multi && !eqOpen ? foldEquivalents(eq, EQ_KEEP) : null;
  const eqShown = folded ? folded.visible : (eq?.shown ?? []);

  useLayoutEffect(() => {
    const el = whyBody.current;
    if (!multi || !el) return setWhyLong(false);
    const measure = () => setWhyLong(whyOpen || el.scrollHeight > el.clientHeight + 1);
    measure();
    window.addEventListener('resize', measure);
    return () => window.removeEventListener('resize', measure);
  });

  return (
    <div className={multi ? 'tb tb--multi' : 'tb'} aria-label="Cartouche du résultat">
      <div className="c s3">
        <span className="l">Désignation</span>
        <b className="tb-title">{category.label}</b>
      </div>

      {multi && <VariantTable narrowing={narrowing} make={vehicle.make} selection={selection} onPick={onPick} />}

      {headline ? (
        <div className="c s3">
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
        <div className="c s3">
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
      <div className="c r">
        <span className="l">Source</span>
        <span className="tag ok">TecDoc</span>
        {!narrowing.narrowed && <span className="tag warn">affinage partiel</span>}
      </div>

      <div className="c s3 why">
        <span className="l">Pourquoi cette pièce</span>
        <div className="why-body" ref={whyBody} data-clamped={multi && !whyOpen ? true : undefined}>
          {rationale && <span className="why-agent">{rationale}</span>}
          {whyLine(narrowing, selection, letter)}
          {narrowing.degradedReason && <span className="why-degraded">{degradedMessage(narrowing.degradedReason)}</span>}
          {narrowing.unclassifiedCount > 0 && cluster && <span className="why-quiet">{formatCount(narrowing.unclassifiedCount)} réf. non rattachées.</span>}
        </div>
        {multi && whyLong && (
          <button type="button" className="why-toggle" aria-expanded={whyOpen} onClick={() => setWhyOpen((o) => !o)}>
            {whyOpen ? 'Réduire' : 'Lire la suite'}
          </button>
        )}
      </div>

      <div className="act">
        {buyRef && <BuyMenu reference={buyRef} />}
        <AddToVehicle vehicle={vehicle} category={category} family={family} oe={oe} equivalent={top} api={api} />
        <button type="button" className="btn" onClick={async () => toast((await copy(window.location.href)) ? 'Lien copié' : 'Copie impossible.')}>
          Copier le lien
        </button>
      </div>
    </div>
  );
}

type AddState = { kind: 'idle' } | { kind: 'saving' } | { kind: 'login' } | { kind: 'pick'; candidates: GarageCandidate[] };

const PLAN_FAILURE: Record<string, string> = {
  ambiguous: 'Plusieurs véhicules correspondent dans le garage. Choisissez-le dans l’Atelier, puis réessayez.',
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

function whyLine(n: Narrowing, selection: Selection, letter: string | null) {
  const { cluster, status } = selection;
  if (!cluster) return <span>Références non regroupées en pièces distinctes.</span>;
  const brands = new Set(cluster.variants.map((v) => v.brand)).size;
  const same = `${formatCount(cluster.articleCount)} réf. de ${formatCount(brands)} marque${brands > 1 ? 's' : ''} pour cette même pièce.`;
  if (status === 'open') {
    return (
      <span>
        {formatCount(n.clusters.length)} pièces possibles, variante {letter} affichée. Choisissez la variante dans le tableau ou répondez à la question.
      </span>
    );
  }
  if (status === 'user') {
    return (
      <span>
        Variante {letter} choisie par vous : le catalogue ne départage pas ces pièces pour ce véhicule. {same}
      </span>
    );
  }
  return <span>{same}</span>;
}
