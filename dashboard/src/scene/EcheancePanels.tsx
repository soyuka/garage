import './echeancepanels.css';
import type { Echeance } from './echeances';
import { layoutSlots, ARC_END_DEG, type EcheanceSlot } from './ringEcheances';
import type { ForecastDue } from '../types';

// Échéances live OFF the right dock and ON the ring (§ choreography 6): fixed glass panels floating
// around the disc's left flank while the car spins behind them — never rotating with it. The DUE vs
// UPCOMING split + the toggle now live in SceneView (a bottom chip); this leaf just lays out whatever
// active set it is handed. Panels are informational — only "Planifier" acts (no focus-dim on the body).

const CAP = 8; // worst 8 shown (4 per flank) + a "+N" pill — a dense forecast must not carpet the ring.
const STAGGER_MS = 70;
const RADIUS_PCT = 35; // hug the car (pulled further inward so panels read as embedded in the scene).

/** Panel center placed on a circle around the stage's own center (in %, so it scales with viewport). */
function slotStyle(angleDeg: number, radiusPct = RADIUS_PCT): React.CSSProperties {
  const rad = (angleDeg * Math.PI) / 180;
  const x = 50 + radiusPct * Math.sin(rad);
  const y = 50 - radiusPct * Math.cos(rad);
  return { left: `${x}%`, top: `${y}%` };
}

function PanelCard({ slot, index, reduced, onPlan }: {
  slot: EcheanceSlot;
  index: number;
  reduced: boolean;
  onPlan?: (due: ForecastDue) => void;
}) {
  const e = slot.echeance;
  const canPlan = e.kind === 'due' && !!e.due && !!onPlan;
  return (
    <div
      className="at-echp"
      data-signal={e.signal}
      style={{ ...slotStyle(slot.angleDeg), animationDelay: reduced ? undefined : `${Math.min(index, CAP - 1) * STAGGER_MS}ms` }}
    >
      <div className="at-echp-body">
        <span className="at-echp-dot" aria-hidden="true" />
        <span className="at-echp-text">
          <span className="at-echp-svc">{e.label}</span>
          {e.horizon && <span className="at-echp-horizon">{e.horizon}</span>}
        </span>
      </div>
      {canPlan && (
        <button type="button" className="at-echp-plan" onClick={() => onPlan?.(e.due!)}>
          Planifier
        </button>
      )}
    </div>
  );
}

export function EcheancePanels({ items, onPlan, reduced = false }: {
  items: Echeance[];
  onPlan?: (due: ForecastDue) => void;
  reduced?: boolean;
}) {
  if (items.length === 0) return null;
  const shown = items.slice(0, CAP);
  const overflow = items.length - shown.length;
  const slots = layoutSlots(shown);

  return (
    <div className="at-echp-layer" data-reduced={reduced ? 'true' : undefined}>
      {slots.map((slot, i) => (
        <PanelCard key={slot.echeance.id} slot={slot} index={i} reduced={reduced} onPlan={onPlan} />
      ))}
      {overflow > 0 && (
        <div className="at-echp-overflow" style={slotStyle(ARC_END_DEG, RADIUS_PCT + 8)}>
          +{overflow}
        </div>
      )}
    </div>
  );
}
