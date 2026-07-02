import './echeancepins.css';
import { layoutSlots } from './ringEcheances';
import type { RailItem } from './rail';

// The ring pins — the SPATIAL index (E7 → B1): each échéance shrinks to a minimal marker on the turning
// disc — index NN (mono, tier-2) + a signal dot + a short hair leader pointing at the rail. NO prose (the
// readable text lives in the left rail). A pin shares its index with its rail row; hovering/focusing either
// half brightens the pair (data-idx + data-active, driven by SceneView). Pins are decorative-but-focusable:
// each carries an aria-label naming its rail row. Gaps have no pin (index null → filtered before layout).

const RADIUS_PCT = 35; // hug the car — the pins read as embedded on the disc, not floating chrome.

function slotStyle(angleDeg: number): React.CSSProperties {
  const rad = (angleDeg * Math.PI) / 180;
  return { left: `${50 + RADIUS_PCT * Math.sin(rad)}%`, top: `${50 - RADIUS_PCT * Math.cos(rad)}%` };
}

function pad2(n: number): string {
  return String(n).padStart(2, '0');
}

export function EcheancePins({ items, hoveredIdx, onHoverIdx, reduced = false }: {
  items: RailItem[];
  hoveredIdx: number | null;
  onHoverIdx: (idx: number | null) => void;
  reduced?: boolean;
}) {
  const pins = items.filter((i): i is RailItem & { index: number } => i.index != null);
  if (pins.length === 0) return null;
  const slots = layoutSlots(pins);

  return (
    <div className="at-echpin-layer" data-reduced={reduced ? 'true' : undefined}>
      {slots.map((slot) => {
        const e = slot.echeance;
        const label = e.horizon ? `${e.label} — ${e.horizon}` : e.label;
        return (
          <div
            key={e.id}
            className="at-echpin"
            data-signal={e.signal}
            data-idx={e.index}
            data-active={hoveredIdx === e.index ? 'true' : undefined}
            data-done={e.done ? 'true' : undefined}
            style={slotStyle(slot.angleDeg)}
            tabIndex={0}
            aria-label={`Échéance ${pad2(e.index)} : ${label}`}
            onMouseEnter={() => onHoverIdx(e.index)}
            onMouseLeave={() => onHoverIdx(null)}
            onFocus={() => onHoverIdx(e.index)}
            onBlur={() => onHoverIdx(null)}
          >
            <span className="at-echpin-leader" aria-hidden="true" />
            <span className="at-echpin-dot" aria-hidden="true" />
            <span className="at-echpin-idx" aria-hidden="true">{pad2(e.index)}</span>
          </div>
        );
      })}
    </div>
  );
}
