import { lazy, Suspense, useRef, type ReactNode } from 'react';
import { measure } from '../dims';
import { formatCount } from '../format';
import { HOME, type Starter } from '../home';
import type { MyVehicle } from '../my-vehicles';
import { routeText, type TextRoute } from '../route-input';
import { SHOWCASE } from '../showcase';
import { Intake } from './Intake';
import { MyVehicles } from './VehicleContext';

const Blueprint = lazy(() => import('../scene/Blueprint').then((m) => ({ default: m.Blueprint })));

interface Props {
  starters: readonly Starter[];
  mine: readonly MyVehicle[];
  // Logged in, saved cars still loading: their row's place is kept so the examples do not jump.
  mineLoading: boolean;
  busy: boolean;
  note: ReactNode;
  // Panels answering a home submit (VIN decode, carte grise, plate error), under the composer.
  panel: ReactNode;
  // What fills the page below: the recent searches when there are some, else the example.
  below: ReactNode;
  onPick: (v: MyVehicle) => void;
  onText: (route: TextRoute, typed: string) => void;
  onFiles: (files: File[]) => void;
}

// The home holds the composer itself, under the headline; it docks at the bottom once a search leaves the home.
export function Home({ starters, mine, mineLoading, busy, note, panel, below, onPick, onText, onFiles }: Props) {
  return (
    <div className="home">
      <section className="home-hero" aria-labelledby="entry-title">
        <h1 id="entry-title">{HOME.headline}</h1>
        <p className="entry-lede">{HOME.lede}</p>
        <Intake variant="home" categories={null} initial="" busy={busy} autoFocus hasVehicle={false} onText={onText} onFiles={onFiles} />
        {note}
        <div className="home-picks">
          {mineLoading ? <div className="home-pick home-pick--pending" aria-hidden="true" /> : <MyVehicles vehicles={mine} busy={busy} onPick={onPick} />}
          {starters.length > 0 && <Starters starters={starters} busy={busy} onText={onText} onFiles={onFiles} />}
        </div>
        {panel && <div className="entry-panel">{panel}</div>}
      </section>
      {below}
    </div>
  );
}

// Examples under the composer: one click runs the search, or opens the file picker for a photo.
function Starters({ starters, busy, onText, onFiles }: { starters: readonly Starter[]; busy: boolean; onText: Props['onText']; onFiles: Props['onFiles'] }) {
  const picker = useRef<HTMLInputElement>(null);
  return (
    <div className="home-pick" role="group" aria-labelledby="starters-label">
      <span id="starters-label" className="home-pick-label">
        Exemples
      </span>
      <div className="home-pick-row home-pick-row--wrap">
        {starters.map((s) => (
          <button
            key={s.label}
            type="button"
            className="btn btn--quiet"
            disabled={busy}
            onClick={() => (s.kind === 'photo' ? picker.current?.click() : onText(routeText(s.label), s.label))}
          >
            {s.label}
          </button>
        ))}
      </div>
      <input
        ref={picker}
        type="file"
        accept="image/*,application/pdf"
        className="sr-only"
        tabIndex={-1}
        aria-hidden="true"
        onChange={(e) => {
          const picked = e.target.files ? Array.from(e.target.files) : [];
          e.target.value = '';
          if (picked.length > 0) onFiles(picked);
        }}
      />
    </div>
  );
}

const extent = (): number => {
  const height = measure(SHOWCASE.dimensions, 'height')?.mid ?? 69;
  const diameter = measure(SHOWCASE.dimensions, 'outer')?.mid ?? 70;
  return Math.max(height + 56, diameter + 76);
};

// One real answer drawn the way a result is: the part turning on its sheet, with a reduced title block.
export function Showcase({ reducedMotion }: { reducedMotion: boolean }) {
  const s = SHOWCASE;
  return (
    <section className="show" aria-label="Exemple de résultat">
      <div className="show-sheet">
        <div className="sheet-label m">EXEMPLE · VUE 3D — {s.label.toUpperCase()}</div>
        <div className="show-canvas">
          {s.family.kind !== 'none' && (
            <Suspense fallback={null}>
              <Blueprint family={s.family} dimensions={s.dimensions} ghost={false} reducedMotion={reducedMotion} extent={extent()} interactive={false} />
            </Suspense>
          )}
        </div>
        <div className="tb tb--show" aria-label="Cartouche de l’exemple">
          <div className="c s3">
            <span className="l">Désignation</span>
            <b className="tb-title">{s.label}</b>
          </div>
          {s.oe && (
            <div className="c s3">
              <span className="l">Réf. OE · {s.oe.brand}</span>
              <div className="oe m">{s.oe.number}</div>
            </div>
          )}
          <div className="c s3">
            <span className="l">Équivalences</span>
            <div className="eq m">
              {s.equivalents.map((v) => (
                <span key={`${v.brand}-${v.number}`}>
                  <i>{v.brand}</i> {v.number}
                </span>
              ))}
              {s.otherBrands > 0 && <span className="eq-more">+ {formatCount(s.otherBrands)} autres marques</span>}
            </div>
          </div>
          <div className={s.cotes ? 'c s2' : 'c s2 nb'}>
            <span className="l">Véhicule</span>
            <span>{s.vehicle}</span>
          </div>
          <div className={s.cotes ? 'c r' : 'c r nb'}>
            <span className="l">Références lues</span>
            <span className="m">
              {formatCount(s.total)} → 1
            </span>
          </div>
          {s.cotes && (
            <div className="c s3 nb">
              <span className="l">Cotes</span>
              <span className="m">{s.cotes}</span>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
