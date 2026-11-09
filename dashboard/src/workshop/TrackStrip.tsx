import { useEffect, useMemo, useRef, useState } from 'react';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { formatDate } from '../format';
import { serviceLabel } from '../labels';
import { reading, sentenceCase } from './describe';
import type { KmTrack as Track, Tick } from './kmTrack';
import { StateShape } from './StateMark';
import { spreadTicks } from './spreadTicks';
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
  const ticks = useMemo(() => trackTicks(track), [track]);
  const title = (tick: Tick) => sentenceCase(tick.label ?? (tick.types.length > 0 ? tick.types.map((ty) => serviceLabel(ty, locale)).join(' · ') : '—'));

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
    const xs = spreadTicks(ticks.map((tk) => (pct(tk.value, track) / 100) * railWidth), TICK_GAP, 0, railWidth);
    ticks.forEach((tk, i) => out.set(tk.id, xs[i]));
    return out;
  }, [ticks, track, railWidth]);

  const lanes = useMemo(() => {
    if (railWidth <= 0) return {} as Record<string, Lane | null>;
    const boxes: LabelBox[] = ticks.map((tick) => {
      const width = textWidth(`${reading(tick.value, track.unit, locale)} ${title(tick)}`);
      // State labels outrank history, and an overdue label is never dropped.
      const priority = tick.id === selectedId ? 4 : STATE_PRIORITY[tick.state];
      const tx = drawn.get(tick.id) ?? 0;
      return { id: tick.id, x: tx, width, prefer: tick.state === 'done' ? 'up' : 'down', right: tx + width > railWidth, priority, keep: tick.state === 'over' };
    });
    const ordered = boxes;
    const reserved: Reserved[] = [];
    if (track.today !== null) {
      const at = (pct(track.today, track) / 100) * railWidth;
      reserved.push({ lane: 'up', from: at - 2, to: at + 6 + textWidth(`${t.workshop.track.today} ${reading(track.today, track.unit, locale)}`) });
    }
    return placeLabels(ordered, reserved);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ticks, track, railWidth, selectedId, locale, drawn]);

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
          {ticks.map((tick) => {
            const selected = tick.id === selectedId;
            const lane = lanes[tick.id] ?? null;
            const px = drawn.get(tick.id);
            const at = px !== undefined && railWidth > 0 ? (px / railWidth) * 100 : pct(tick.value, track);
            const name = title(tick);
            const value = reading(tick.value, track.unit, locale);
            const stateWord = t.workshop.state[tick.state];
            return (
              <button
                key={tick.id}
                type="button"
                className={`w-tick w-tick--${tick.state}${lane === 'up' ? ' is-up' : ' is-down'}${selected ? ' is-selected' : ''}${tick.estimated ? ' is-estimated' : ''}${railWidth > 0 && (at / 100) * railWidth + textWidth(`${value} ${name}`) > railWidth ? ' is-right' : ''}`}
                style={{ left: `${at}%` }}
                tabIndex={selected || (selectedId === null && tick === ticks[0]) ? 0 : -1}
                aria-pressed={selected}
                aria-label={t.workshop.track.tick(name, value, tick.estimated ? `${stateWord}, ${t.workshop.track.estimated}` : stateWord)}
                title={[`${value} ${name}`, tick.date ? formatDate(tick.date, locale) : null].filter(Boolean).join(' · ')}
                onClick={() => onSelect(tick)}
              >
                <StateShape state={tick.state} size={tick.state === 'over' ? 12 : 9} />
                {lane && (
                  <span className="w-tick-label" aria-hidden="true">
                    <span className="w-data">{value}</span> {name}
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
