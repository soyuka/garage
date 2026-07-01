import './cardinals.css';
import { formatHealthPct } from '../format';

// §2 — SANTÉ is now the SOLE cardinal readout on the disc (KILOMÉTRAGE → car-info row; EN RETARD →
// échéance panels; DEPUIS L'ENTRETIEN dropped as noise). A flat DOM HUD over the stage (NOT 3D): the
// health % stays axis-aligned and crisp — big, VR-scale — while the car orbits behind it.

interface CardinalReadoutsProps {
  healthPct: number | null;
  reduced?: boolean;
}

const DASH = '—';

export function CardinalReadouts({ healthPct }: CardinalReadoutsProps) {
  return (
    <div className="at-cardinals" aria-hidden="true">
      <div className="at-card at-card--right">
        <span className="at-label at-card-label">SANTÉ</span>
        <span className="at-card-value">{healthPct != null ? formatHealthPct(healthPct) : DASH}</span>
      </div>
    </div>
  );
}
