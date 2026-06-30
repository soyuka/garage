import { type MutableRefObject, type RefObject } from 'react';
import { Html } from '@react-three/drei';
import type { BodyClass } from './CarProxy';
import type { ForecastDue, Signal } from '../types';
import { BODY_REGIONS, REGION_LABEL, regionAnchors, regionForType, type BodyRegion } from './anchors';
import { dueSignal, signalTag } from '../derive';
import './hotspots.css';

export function groupByRegion(due: ForecastDue[]): Array<{ region: BodyRegion; dues: ForecastDue[] }> {
  const byRegion = new Map<BodyRegion, ForecastDue[]>();
  for (const d of due) {
    const region = regionForType(d.type);
    const bucket = byRegion.get(region);
    if (bucket) bucket.push(d);
    else byRegion.set(region, [d]);
  }
  return BODY_REGIONS.filter((r) => byRegion.has(r)).map((region) => ({ region, dues: byRegion.get(region)! }));
}

const RANK: Record<Signal, number> = { green: 0, amber: 1, red: 2 };

export function worstSignal(dues: ForecastDue[]): Signal {
  return dues.reduce<Signal>((worst, d) => {
    const s = dueSignal(d);
    return RANK[s] > RANK[worst] ? s : worst;
  }, 'green');
}

export interface PinGroup {
  region: BodyRegion;
  dues: ForecastDue[];
  /** spotlit by the current focus (agent answer / pin click) — reads red regardless of due state. */
  forced: boolean;
}

/** The pins to show = occupied (due) regions ∪ spotlit regions, in head-to-tail order, one per region. */
export function pinGroups(due: ForecastDue[], highlight: BodyRegion[] = []): PinGroup[] {
  const dues = new Map(groupByRegion(due).map((g) => [g.region, g.dues] as const));
  const forced = new Set(highlight);
  const regions = new Set<BodyRegion>([...dues.keys(), ...forced]);
  return BODY_REGIONS.filter((r) => regions.has(r)).map((region) => ({
    region,
    dues: dues.get(region) ?? [],
    forced: forced.has(region),
  }));
}

/** A spotlit pin reads red (urgent attention); otherwise it wears its region's worst due signal. */
export function pinSignal(group: PinGroup): Signal {
  return group.forced ? 'red' : worstSignal(group.dues);
}

// shape + glyph differ by state, never colour alone (§8 / BUILD-NOTE 4): solid hex + ✓ (vert),
// hex + ! (ambre), doubled-outline hex + ‼ (rouge). Mirrored in the truth-panel's text tags.
const GLYPH: Record<Signal, string> = { green: '✓', amber: '!', red: '‼' };

// A pin is now a pure ANCHOR + trigger: clicking it focuses the slot on that region's tasks (the dense
// detail lives in the stable slot, never a floating-in-3D card that overflowed with many services).
function Pin({ group, reduced, onFocusRegion }: { group: PinGroup; reduced: boolean; onFocusRegion?: (r: BodyRegion) => void }) {
  const signal = pinSignal(group);
  const label = REGION_LABEL[group.region];
  return (
    <div className="at-hot" data-signal={signal}>
      <button
        type="button"
        className={`at-hot-pin${reduced ? ' at-hot-pin--still' : ''}${signal === 'red' ? ' at-hot-pin--double' : ''}`}
        aria-label={`${label} — ${signalTag(signal === 'red', signal).text}`}
        onClick={() => onFocusRegion?.(group.region)}
      >
        <span className="at-hot-glyph" aria-hidden="true">{GLYPH[signal]}</span>
      </button>
      <span className="at-hot-tab" aria-hidden="true">{label}</span>
    </div>
  );
}

export function Hotspots({
  bodyClass,
  due,
  reduced = false,
  portalTarget,
  highlight,
  onFocusRegion,
}: {
  bodyClass: BodyClass;
  due: ForecastDue[];
  reduced?: boolean;
  portalTarget?: RefObject<HTMLDivElement | null>;
  /** spotlit regions (focus) — force a lit pin even when the region is not due. */
  highlight?: BodyRegion[];
  /** click a pin → focus the slot on this region's tasks (replaces the old hover card). */
  onFocusRegion?: (r: BodyRegion) => void;
}) {
  const anchors = regionAnchors(bodyClass);
  const groups = pinGroups(due, highlight ?? []);
  return (
    <>
      {groups.map((group) => (
        <Html
          key={group.region}
          position={anchors[group.region]}
          center
          zIndexRange={[20, 0]}
          wrapperClass="at-hot-wrap"
          // drei types portal as a non-null MutableRefObject; our layer ref is nullable until mount
          portal={portalTarget as unknown as MutableRefObject<HTMLElement> | undefined}
        >
          <Pin group={group} reduced={reduced} onFocusRegion={onFocusRegion} />
        </Html>
      ))}
    </>
  );
}
