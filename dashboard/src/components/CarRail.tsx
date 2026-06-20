import type { Vehicle, VehicleId, VehicleStatus } from '../types';

const STATUS_COLOR: Record<VehicleStatus, string> = {
  ok: 'var(--mpa-ok)',
  soon: 'var(--mpa-warn)',
  overdue: 'var(--mpa-alarm)',
};

interface Props {
  vehicles: Vehicle[];
  selectedId: VehicleId;
  onSelect: (id: VehicleId) => void;
}

/** The parc as a horizontal strip of floating instrument-cards — one glass block per vehicle,
 * the selected one lit. Selection-by-gallery rather than a nav list. */
export function CarRail({ vehicles, selectedId, onSelect }: Props) {
  return (
    <div className="car-rail">
      <div className="car-rail-track">
        {vehicles.map((v) => (
          <button
            key={v.id}
            type="button"
            className={`car-card hud-panel${v.id === selectedId ? ' active' : ''}`}
            onClick={() => onSelect(v.id)}
            aria-pressed={v.id === selectedId}
          >
            <span className="car-card-ini">{v.initials}</span>
            <span className="car-card-body">
              <span className="car-card-name">{v.name}</span>
              <span className="car-card-sub">{v.usage || v.subtitle}</span>
            </span>
            <span className="car-card-status" style={{ background: STATUS_COLOR[v.status] }} />
          </button>
        ))}
        <button type="button" className="car-card car-card-add hud-panel" title="Ajouter un véhicule">
          <span className="car-card-add-glyph">+</span>
          <span className="car-card-add-label">Ajouter</span>
        </button>
      </div>
    </div>
  );
}
