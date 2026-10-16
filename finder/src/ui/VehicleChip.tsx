import { buildCodeLabel } from '../buildcode';
import type { BuildCode, Vehicle } from '../types';

export function vehicleName(v: Vehicle): string {
  const make = v.make ? v.make.charAt(0) + v.make.slice(1).toLowerCase() : '';
  const model = v.modelEn ?? v.model ?? '';
  const name = [make, model.replace(new RegExp(`^${v.make ?? ''}\\s*`, 'i'), '')].filter(Boolean).join(' ').trim();
  return name || (v.carId !== null ? `Véhicule kType ${v.carId}` : 'Véhicule');
}

export const vehicleLabel = (v: Vehicle): string => v.plate ?? `kType ${v.carId ?? '?'}`;

export function vehicleEngine(v: Vehicle): string {
  return [v.version, v.engineCode].filter(Boolean).join(' · ');
}

export function VehicleChip({ vehicle, buildCode = null, onChange }: { vehicle: Vehicle; buildCode?: BuildCode | null; onChange: () => void }) {
  return (
    <button type="button" className="chip" onClick={onChange} aria-label={`Véhicule ${vehicle.plate ?? vehicleName(vehicle)}, changer de véhicule`}>
      {vehicle.plate && <span className="plate m">{vehicle.plate}</span>}
      <span className="chip-name">{vehicleName(vehicle)}</span>
      <span className="chip-engine">{vehicleEngine(vehicle)}</span>
      {vehicle.carId !== null && <span className="chip-ktype m">kType {vehicle.carId}</span>}
      {buildCode && (
        <span className="chip-opr m" title="Code de fabrication décodé">
          {buildCodeLabel(buildCode)}
        </span>
      )}
    </button>
  );
}
