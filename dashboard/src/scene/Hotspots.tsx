import { useState, type FocusEvent, type MutableRefObject, type RefObject } from 'react';
import { Html } from '@react-three/drei';
import type { BodyClass } from './CarProxy';
import type { ForecastDue, Signal } from '../types';
import { BODY_REGIONS, REGION_LABEL, regionAnchors, regionForType, type BodyRegion } from './anchors';
import { dueDescriptor, dueSignal, signalTag } from '../derive';
import { formatDate, formatHealthPct } from '../format';
import { serviceLabel } from '../labels';
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

// shape + glyph differ by state, never colour alone (§8 / BUILD-NOTE 4): solid hex + ✓ (vert),
// hex + ! (ambre), doubled-outline hex + ‼ (rouge). Mirrored in the truth-panel's text tags.
const GLYPH: Record<Signal, string> = { green: '✓', amber: '!', red: '‼' };

function Pin({ region, dues, reduced, onPlan }: { region: BodyRegion; dues: ForecastDue[]; reduced: boolean; onPlan?: (due: ForecastDue) => void }) {
  const [open, setOpen] = useState(false);
  const signal = worstSignal(dues);
  const label = REGION_LABEL[region];

  // Focus is tracked at the WRAPPER (events bubble) so Tab can move pin → in-card CTA without the card
  // unmounting; close only when focus leaves the whole hotspot. Fixes the keyboard-unreachable CTA.
  const onBlur = (e: FocusEvent<HTMLDivElement>) => {
    if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setOpen(false);
  };

  return (
    <div
      className="at-hot"
      data-signal={signal}
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
      onFocus={() => setOpen(true)}
      onBlur={onBlur}
    >
      <button
        type="button"
        className={`at-hot-pin${reduced ? ' at-hot-pin--still' : ''}${signal === 'red' ? ' at-hot-pin--double' : ''}`}
        aria-label={`${label} — ${signalTag(signal === 'red', signal).text}`}
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
      >
        <span className="at-hot-glyph" aria-hidden="true">{GLYPH[signal]}</span>
      </button>
      <span className="at-hot-tab" aria-hidden="true">{label}</span>

      {open && (
        <div className="at-hot-card glass" role="group" aria-label={label}>
          <span className="at-label at-hot-card-head">{label}</span>
          <ul className="at-hot-rows">
            {dues.map((d, i) => {
              const desc = dueDescriptor(d);
              const tag = signalTag(desc.overdue, desc.signal);
              return (
                <li className="at-hot-row" key={i} data-signal={desc.signal}>
                  <span className="at-dot" aria-hidden="true" />
                  <span className="at-hot-row-name">{serviceLabel(d.type)}</span>
                  <span className="at-hot-row-pct">{desc.healthPct != null ? formatHealthPct(desc.healthPct) : '—'}</span>
                  <span className={`at-tag at-tag--${tag.tone}`}>{tag.text}</span>
                  <span className="at-hot-row-when">
                    <span className={`at-hot-row-horizon${desc.overdue ? ' at-hot-row-horizon--late' : ''}`}>{desc.horizon}</span>
                    {desc.until && <span className="at-hot-row-until">{desc.until}</span>}
                  </span>
                  {d.lastPerformedAt && (
                    <span className="at-hot-row-last">
                      <span className="at-hot-row-last-label">dernière intervention</span>
                      <span className="at-hot-row-last-date">{formatDate(d.lastPerformedAt)}</span>
                    </span>
                  )}
                  {/* #10: plan a concrete task pegged to this predicted-due service (no-op if no handler). */}
                  {onPlan && (
                    <button type="button" className="at-hot-plan" onClick={() => onPlan(d)}>
                      Planifier {serviceLabel(d.type)} →
                    </button>
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      )}
    </div>
  );
}

export function Hotspots({
  bodyClass,
  due,
  reduced = false,
  portalTarget,
  onPlan,
}: {
  bodyClass: BodyClass;
  due: ForecastDue[];
  reduced?: boolean;
  portalTarget?: RefObject<HTMLDivElement | null>;
  onPlan?: (due: ForecastDue) => void;
}) {
  const anchors = regionAnchors(bodyClass);
  const groups = groupByRegion(due);
  return (
    <>
      {groups.map(({ region, dues }) => (
        <Html
          key={region}
          position={anchors[region]}
          center
          zIndexRange={[20, 0]}
          wrapperClass="at-hot-wrap"
          // drei types portal as a non-null MutableRefObject; our layer ref is nullable until mount
          portal={portalTarget as unknown as MutableRefObject<HTMLElement> | undefined}
        >
          <Pin region={region} dues={dues} reduced={reduced} onPlan={onPlan} />
        </Html>
      ))}
    </>
  );
}
