import { useEffect, useMemo, useRef, useState } from 'react';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { formatDate } from '../format';
import { serviceLabel } from '../labels';
import { reading, sentenceCase } from './describe';
import type { KmTrack as Track, Tick } from './kmTrack';
import { StateShape } from './StateMark';
import { spreadTicks } from './spreadTicks';
import { groupTicks, nextInGroup, type TickGroup } from './tickGroups';
import { placeLabels, type LabelBox, type Lane, type Reserved } from './trackLabels';

let measurer: CanvasRenderingContext2D | null = null;
// Labels mix the UI face and the mono figure; measuring both in the UI face and padding covers the mono.
function textWidth(text: string): number {
  measurer ??= document.createElement('canvas').getContext('2d');
  if (!measurer) return text.length * 6.5;
  measurer.font = "10.5px 'Zen Kaku Gothic New', sans-serif";
  return Math.ceil(measurer.measureText(text).width * 1.14) + 8;
}

const TICK_GAP = 16;

const STATE_PRIORITY: Record<Tick['state'], number> = { over: 3, due: 2, planned: 1, done: 0 };

export function trackTicks(track: Track): Tick[] {
  return [...track.done, ...track.planned].sort((a, b) => a.value - b.value);
}

function pct(value: number, track: Track): number {
  const span = track.max - track.min;
  return span <= 0 ? 50 : ((value - track.min) / span) * 100;
}

export function TrackStrip({ track, selectedId, onSelect }: { track: Track; selectedId: string | null; onSelect: (tick: Tick) => void }) {
  const t = useT();
  const [locale] = useLocale();
  const groups = useMemo(() => groupTicks(trackTicks(track)), [track]);
  const title = (tick: Tick) => sentenceCase(tick.label ?? (tick.types.length > 0 ? tick.types.map((ty) => serviceLabel(ty, locale)).join(' · ') : '—'));
  // A group names its selected job, else its first, and counts the rest.
  const shown = (g: TickGroup) => g.ticks.find((tk) => tk.id === selectedId) ?? g.ticks[0];
  const more = (g: TickGroup) => (g.ticks.length > 1 ? ` +${g.ticks.length - 1}` : '');

  const rail = useRef<HTMLDivElement>(null);
  const [railWidth, setRailWidth] = useState(0);
  useEffect(() => {
    const el = rail.current;
    if (!el) return;
    const ro = new ResizeObserver(([entry]) => setRailWidth(entry.contentRect.width));
    ro.observe(el);
    return () => ro.disconnect();
  }, [track.empty]);

  // Ticks closer than a tick's own width are drawn apart; the reading in each name stays exact.
  const drawn = useMemo(() => {
    const out = new Map<string, number>();
    if (railWidth <= 0) return out;
    const xs = spreadTicks(groups.map((g) => (pct(g.value, track) / 100) * railWidth), TICK_GAP, 0, railWidth);
    groups.forEach((g, i) => out.set(g.id, xs[i]));
    return out;
  }, [groups, track, railWidth]);

  const lanes = useMemo(() => {
    if (railWidth <= 0) return {} as Record<string, Lane | null>;
    const boxes: LabelBox[] = groups.map((g) => {
      const width = textWidth(`${reading(g.value, track.unit, locale)} ${title(shown(g))}${more(g)}`);
      // State labels outrank history; a label that cannot fit stays a tick, never an overprint.
      const priority = g.ticks.some((tk) => tk.id === selectedId) ? 4 : STATE_PRIORITY[g.state];
      const tx = drawn.get(g.id) ?? 0;
      return { id: g.id, x: tx, width, prefer: g.state === 'done' ? 'up' : 'down', right: tx + width > railWidth, priority };
    });
    const reserved: Reserved[] = [];
    if (track.today !== null) {
      const at = (pct(track.today, track) / 100) * railWidth;
      reserved.push({ lane: 'up', from: at - 2, to: at + 6 + textWidth(`${t.workshop.track.today} ${reading(track.today, track.unit, locale)}`) });
    }
    return placeLabels(boxes, reserved);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groups, track, railWidth, selectedId, locale, drawn]);

  if (track.empty) {
    return (
      <section className="w-track is-empty" aria-label={t.workshop.track.label}>
        <p className="w-muted">{t.workshop.track.empty}</p>
      </section>
    );
  }

  const unitLabel = track.unit === 'h' ? t.workshop.track.labelHours : t.workshop.track.label;
  return (
    <section className="w-track" aria-label={unitLabel}>
      <div className="w-track-rail" ref={rail}>
        <div className="w-track-axis" />
        {track.today !== null && <div className="w-track-done-run" style={{ width: `${pct(track.today, track)}%` }} />}
        <span className="w-data w-track-end w-track-end--min">{reading(track.min, track.unit, locale)}</span>
        <span className="w-data w-track-end w-track-end--max">{reading(track.max, track.unit, locale)}</span>
        {track.today !== null && (
          <div className="w-track-today" style={{ left: `${pct(track.today, track)}%` }}>
            <span className="w-track-today-label">
              {t.workshop.track.today} <span className="w-data">{reading(track.today, track.unit, locale)}</span>
            </span>
          </div>
        )}
        <div className="w-track-ticks" role="group" aria-label={`${unitLabel} · ${t.workshop.track.hint}`}>
          {groups.map((g, gi) => {
            const tick = shown(g);
            const selected = g.ticks.some((tk) => tk.id === selectedId);
            const lane = lanes[g.id] ?? null;
            const px = drawn.get(g.id);
            const at = px !== undefined && railWidth > 0 ? (px / railWidth) * 100 : pct(g.value, track);
            const name = title(tick);
            const names = g.ticks.map(title).join(', ');
            const value = reading(g.value, track.unit, locale);
            const stateWord = t.workshop.state[g.state];
            const count = more(g);
            return (
              <button
                key={g.id}
                type="button"
                className={`w-tick w-tick--${g.state}${lane === 'up' ? ' is-up' : ' is-down'}${selected ? ' is-selected' : ''}${tick.estimated ? ' is-estimated' : ''}${g.ticks.length > 1 ? ' is-stack' : ''}${railWidth > 0 && (at / 100) * railWidth + textWidth(`${value} ${name}${count}`) > railWidth ? ' is-right' : ''}`}
                style={{ left: `${at}%` }}
                tabIndex={selected || (selectedId === null && gi === 0) ? 0 : -1}
                aria-pressed={selected}
                aria-label={t.workshop.track.tick(names, value, tick.estimated ? `${stateWord}, ${t.workshop.track.estimated}` : stateWord)}
                title={[`${value} ${names}`, tick.date ? formatDate(tick.date, locale) : null].filter(Boolean).join(' · ')}
                onClick={() => onSelect(nextInGroup(g, selectedId))}
              >
                <StateShape state={g.state} size={g.state === 'over' ? 12 : 9} />
                {lane && (
                  <span className="w-tick-label" aria-hidden="true">
                    <span className="w-data">{value}</span> {name}
                    {count && <span className="w-data w-tick-more">{count}</span>}
                  </span>
                )}
              </button>
            );
          })}
        </div>
      </div>
      {track.unplaced.length > 0 && <span className="w-track-note w-muted">{t.workshop.track.unplaced(track.unplaced.length)}</span>}
      <kbd className="w-kbd w-data w-track-keys" aria-hidden="true">
        J K
      </kbd>
    </section>
  );
}
