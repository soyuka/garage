import '../tokens/atelier.css';
import '../floor/floor.css';
import './parcpage.css';
import { PARC } from '../fixtures/vehicles';
import { ParcLot } from './ParcLot';

// Stage B.2 — Le Parc playground. The fleet grid over the fixture vehicles. Selecting a car drives
// into the hero (#/scene); Stage C swaps the fixtures for the live vehicles collection + real routing.
export function ParcPlayground() {
  return (
    <div className="at-parc-page">
      <div className="at-parc-page-top">
        <a href="#/dev" className="at-parc-back">← dev index</a>
        <a href="#/scene" className="at-parc-back">l'atelier →</a>
      </div>
      <h1 className="at-parc-page-title">Le Parc</h1>
      <ParcLot
        vehicles={PARC}
        onSelect={() => {
          window.location.hash = '#/scene';
        }}
      />
    </div>
  );
}
