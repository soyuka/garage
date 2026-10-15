import { vehicleShortName } from '../garage';
import type { MyVehicle } from '../my-vehicles';
import type { Vehicle } from '../types';

export function contextLabel(v: Vehicle): { text: string; mono: boolean } {
  if (v.plate) return { text: v.plate, mono: true };
  const name = vehicleShortName(v);
  return name ? { text: name, mono: false } : { text: `kType ${v.carId ?? '?'}`, mono: true };
}

function CrossIcon() {
  return (
    <svg viewBox="0 0 12 12" width="10" height="10" aria-hidden="true">
      <path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" strokeWidth="1.5" fill="none" strokeLinecap="round" />
    </svg>
  );
}

// The car the composer searches on, pinned at the start of the field. Removing it leaves the car.
export function ContextToken({ vehicle, onRemove }: { vehicle: Vehicle; onRemove: () => void }) {
  const label = contextLabel(vehicle);
  return (
    <span className="ctx">
      <span className={label.mono ? 'ctx-name m' : 'ctx-name'}>{label.text}</span>
      <button type="button" className="ctx-remove" aria-label={`Retirer le véhicule ${label.text}`} title="Retirer le véhicule" onClick={onRemove}>
        <CrossIcon />
      </button>
    </span>
  );
}

// Logged-in home, under the composer: one click opens a saved car with the composer scoped to it.
export function MyVehicles({ vehicles, busy, onPick }: { vehicles: readonly MyVehicle[]; busy: boolean; onPick: (v: MyVehicle) => void }) {
  if (vehicles.length === 0) return null;
  return (
    <div className="home-pick" role="group" aria-labelledby="mine-label">
      <span id="mine-label" className="home-pick-label">
        Mes véhicules
      </span>
      <div className="home-pick-row">
        {vehicles.map((v) => (
          <button key={v.iri} type="button" className="btn btn--quiet" disabled={busy} onClick={() => onPick(v)}>
            {v.plate && <span className="plate m">{v.plate}</span>}
            {v.name && <span>{v.name}</span>}
          </button>
        ))}
      </div>
    </div>
  );
}
