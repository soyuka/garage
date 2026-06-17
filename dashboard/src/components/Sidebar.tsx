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

export function Sidebar({ vehicles, selectedId, onSelect }: Props) {
  return (
    <aside className="sidebar">
      <div className="head">
        <span>Parc · {vehicles.length} véhicules</span>
        <button className="add" title="Ajouter un véhicule">+</button>
      </div>
      {vehicles.map((v) => (
        <div
          key={v.id}
          className={`veh-row${v.id === selectedId ? ' active' : ''}`}
          onClick={() => onSelect(v.id)}
        >
          <div className="ini">
            {v.initials}
            <span className="dot" style={{ background: STATUS_COLOR[v.status] }} />
          </div>
          <div className="info">
            <div className="nm">{v.name}</div>
            <div className="sub">{v.subtitle}</div>
          </div>
          <div className="meta">
            <div className="use">{v.usage}</div>
            <div className="ago">{v.ago}</div>
          </div>
        </div>
      ))}
    </aside>
  );
}
