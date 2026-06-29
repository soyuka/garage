import './cardinals.css';
import { formatReading, formatHealthPct, formatDaysAgo } from '../format';

// §2 — four mono telemetry readouts at the cardinal points of the turntable disc. A flat DOM HUD over
// the stage (NOT 3D): mono telemetry stays axis-aligned and crisp while the car orbits behind it. Every
// value comes from props and is mirrored in the truth-panel (§8). The wrapper never blocks orbiting.

interface CardinalReadoutsProps {
  mileage: number | null;
  mileageUnit?: string | null;
  healthPct: number | null;
  daysSinceService: number | null;
  overdueCount: number;
  reduced?: boolean;
}

const DASH = '—';

function Cell({ pos, label, value, late }: { pos: string; label: string; value: string; late?: boolean }) {
  return (
    <div className={`at-card at-card--${pos}`}>
      <span className="at-label at-card-label">{label}</span>
      <span className="at-card-value" data-late={late ? 'true' : undefined}>
        {value}
      </span>
    </div>
  );
}

export function CardinalReadouts({
  mileage,
  mileageUnit,
  healthPct,
  daysSinceService,
  overdueCount,
  reduced,
}: CardinalReadoutsProps) {
  const overdue = overdueCount > 0;
  return (
    <div className="at-cardinals" data-reduced={reduced ? 'true' : undefined} aria-hidden="true">
      <Cell pos="top" label="KILOMÉTRAGE" value={mileage != null ? formatReading(mileage, mileageUnit) : DASH} />
      <Cell pos="right" label="SANTÉ" value={healthPct != null ? formatHealthPct(healthPct) : DASH} />
      <Cell
        pos="bottom"
        label="DEPUIS L'ENTRETIEN"
        value={daysSinceService != null ? formatDaysAgo(daysSinceService) : DASH}
      />
      <Cell pos="left" label="EN RETARD" value={String(overdueCount)} late={overdue} />
    </div>
  );
}
