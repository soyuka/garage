import { Suspense, useEffect, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { isArchived } from './archive';
import { createPortal } from 'react-dom';
import { Canvas } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import { useLocale, useT } from '../i18n/LocaleProvider';
import type { Locale } from '../i18n/locale';
import { formatAmount, formatDate, formatEuros, NBSP } from '../format';
import { visitTitle } from '../records';
import type { BodyRegion } from '../scene/anchors';
import { MeshErrorBoundary } from '../scene/VehicleBody';
import type { MaintenanceRecord, PlannedPart, VehicleBundle } from '../types';
import { CarStage } from './CarStage';
import { barCents, costByYear, costSeries, costSummary, labelledBars, visitSlots, type CostBar } from './costs';
import { jobTitle, reading, sentenceCase } from './describe';
import { EdgeModel, useTokenColor, type EdgeFit } from './EdgeModel';
import { regionOnBody, topicForService, topicForText } from './focusTopic';
import { normalizeUnit } from './kmTrack';
import { piecesLists, type JobItem, type Modules } from './modules';
import { meshForPart, partUrl, type PartFile } from './partMeshes';
import { PartThumb } from './PartView';
import { defaultPart, findPart, movePart, partDisplay, partForProjection, partShelves, performer, visitShelfId, type Shelf, type ShelfItem, type ShelfMove } from './shelf';
import { PHONE, useMedia } from './hooks';
import { AddBar, EditVisitButton, JobEditActions } from './Editors';
import { Close } from './Icons';
import { StateMark } from './StateMark';
import type { ViewState } from './view';

const PART_FIT: EdgeFit = { mode: 'centre', size: 1 };
const KEY_MOVES: Record<string, ShelfMove> = {
  ArrowLeft: 'left',
  ArrowRight: 'right',
  ArrowUp: 'up',
  ArrowDown: 'down',
};

function regionOf(part: PlannedPart, shelf: Shelf, vehicleType: string): BodyRegion | null {
  const fallback = shelf.kind === 'toOrder' ? shelf.job.type : (shelf.record.items[0]?.type ?? null);
  const found = topicForText([part.label, part.brand].filter(Boolean).join(' ')) ?? (fallback ? topicForService(fallback) : null);
  return found ? regionOnBody(found.region, found.topic, vehicleType) : null;
}

function wholeEuros(cents: number, locale: Locale): string {
  const amount = formatAmount(Math.round(cents / 100), locale);
  return locale === 'fr' ? `${amount}${NBSP}€` : `€${amount}`;
}

interface PiecesProps {
  slot: ReactNode;
  bundle: VehicleBundle;
  modules: Modules;
  view: ViewState;
  active: boolean;
  reduced: boolean;
  actionsDisabled: boolean;
  onPlan?: (job: JobItem) => void;
  /** An open hand editor takes the inspector's place. */
  editor: ReactNode;
}

export function PiecesPlane({ slot, bundle, modules, view, active, reduced, actionsDisabled, onPlan, editor }: PiecesProps) {
  const t = useT();
  const { toOrder, fitted } = piecesLists(bundle, modules);
  const shelves = useMemo(() => partShelves(toOrder, fitted), [bundle, modules]);
  const [selected, setSelected] = useState<string | null>(() => defaultPart(shelves));
  const [focusKey, setFocusKey] = useState<string | null>(null);
  const tiles = useRef(new Map<string, HTMLButtonElement>());
  const framed = partForProjection(shelves, view.projection);
  const phone = useMedia(PHONE);
  const [sheet, setSheet] = useState(false);

  useEffect(() => {
    if (!findPart(shelves, selected)) setSelected(defaultPart(shelves));
  }, [shelves, selected]);
  useEffect(() => {
    if (!framed) return;
    setSelected(framed);
    setSheet(true);
  }, [view.projectionKey]);

  const current = findPart(shelves, selected);
  const roving = focusKey ?? selected;

  const onShelfKey = (e: KeyboardEvent<HTMLDivElement>) => {
    const move = KEY_MOVES[e.key];
    if (!move || !roving) return;
    e.preventDefault();
    const next = movePart(shelves, roving, move);
    setFocusKey(next);
    tiles.current.get(next)?.focus();
  };
  const backToShelf = () => {
    setSheet(false);
    if (selected) tiles.current.get(selected)?.focus();
  };
  const [hoverVisit, setHoverVisit] = useState<string | null>(null);
  const openVisit = (recordId: string) => {
    const shelf = shelves.find((sh) => sh.id === visitShelfId(recordId));
    const first = shelf?.items[0];
    if (!first) return;
    setSelected(first.key);
    setFocusKey(first.key);
    const tile = tiles.current.get(first.key);
    tile?.scrollIntoView({
      block: 'center',
      behavior: reduced ? 'auto' : 'smooth',
    });
    tile?.focus({ preventScroll: true });
  };
  const shelved = new Set(shelves.map((sh) => sh.id));
  const showInspector = !editor && current && (!phone || sheet);

  return (
    <div className="w-pieces">
      <CostView bundle={bundle} compact={phone} onVisit={openVisit} onHoverVisit={setHoverVisit} hasShelf={(id) => shelved.has(visitShelfId(id))} />
      {view.projection && !framed && <div className="w-pieces-slot">{slot}</div>}
      {editor && <div className="w-pieces-editor">{editor}</div>}
      <section className="w-shelves" aria-label={t.workshop.pieces.shelves} onKeyDown={onShelfKey}>
        <AddBar items={['visit']} className="w-pieces-add" />
        {shelves.length === 0 && <p className="w-muted w-shelves-empty">{t.workshop.pieces.empty}</p>}
        {shelves.map((shelf) => (
          <ShelfRow key={shelf.id} shelf={shelf} bundle={bundle} lit={hoverVisit !== null && shelf.id === visitShelfId(hoverVisit)}>
            {shelf.items.map((item) => (
              <ShelfTile
                key={item.key}
                item={item}
                tone={shelf.kind === 'toOrder' && shelf.job.state === 'over' && !isArchived(bundle.vehicle) ? 'over' : undefined}
                selected={item.key === selected}
                tabbable={item.key === roving}
                register={(el) => (el ? tiles.current.set(item.key, el) : tiles.current.delete(item.key))}
                onSelect={() => {
                  setSelected(item.key);
                  setFocusKey(item.key);
                  setSheet(true);
                }}
              />
            ))}
          </ShelfRow>
        ))}
      </section>
      {showInspector &&
        (() => {
          const inspector = (
            <PartInspector
              sheet={phone}
              shelf={current.shelf}
              item={current.item}
              bundle={bundle}
              active={active}
              reduced={reduced}
              actionsDisabled={actionsDisabled}
              onPlan={onPlan}
              onEscape={backToShelf}
            />
          );
          if (!phone) return inspector;
          // The section planes sit in a perspective, which would make a fixed sheet scroll with them.
          const host = document.querySelector('.w-root') ?? document.body;
          return createPortal(
            <>
              <button type="button" className="w-sheet-scrim" aria-hidden="true" tabIndex={-1} onClick={backToShelf} />
              {inspector}
            </>,
            host,
          );
        })()}
    </div>
  );
}

function ShelfRow({ shelf, bundle, lit, children }: { shelf: Shelf; bundle: VehicleBundle; lit: boolean; children: ReactNode }) {
  const t = useT();
  const [locale] = useLocale();
  let title: ReactNode;
  if (shelf.kind === 'toOrder') {
    title = (
      <>
        <StateMark state={shelf.job.state} immediate={shelf.job.immediate} />
        <span>{t.workshop.pieces.shelfToOrder(sentenceCase(jobTitle(shelf.job, locale)))}</span>
      </>
    );
  } else {
    const r = shelf.record;
    const by = performer(r.performedBy);
    title = (
      <>
        <span>{t.workshop.pieces.shelfFitted(formatDate(r.performedAt, locale))}</span>
        {by && <span className="w-row-dim">· {by.self ? t.workshop.pieces.diy : by.name}</span>}
        {r.usageValue != null && <span className="w-row-dim w-data">· {reading(r.usageValue, normalizeUnit(r.usageUnit), locale)}</span>}
        <span className="w-row-dim w-shelf-visit" title={visitTitle(r, locale)}>
          · {visitTitle(r, locale)}
        </span>
      </>
    );
  }
  return (
    <div className="w-shelf" data-shelf={shelf.id}>
      <div className="w-shelf-head">
        <h2 className={`w-shelf-title${lit ? ' is-lit' : ''}`}>{title}</h2>
        {shelf.kind === 'fitted' ? <EditVisitButton record={shelf.record} /> : shelf.job.taskId && <JobEditActions job={shelf.job} bundle={bundle} />}
      </div>
      <div className="w-shelf-row" role="group">
        {children}
      </div>
    </div>
  );
}

function ShelfTile({
  item,
  tone,
  selected,
  tabbable,
  register,
  onSelect,
}: {
  item: ShelfItem;
  tone?: 'over';
  selected: boolean;
  tabbable: boolean;
  register: (el: HTMLButtonElement | null) => void;
  onSelect: () => void;
}) {
  const [locale] = useLocale();
  const p = item.part;
  const { name } = partDisplay(p, item.type, locale);
  return (
    <button ref={register} type="button" className={`w-tile${selected ? ' is-selected' : ''}`} aria-pressed={selected} tabIndex={tabbable ? 0 : -1} onClick={onSelect}>
      <span className="w-tile-name" title={name}>
        {name}
      </span>
      <span className="w-tile-meta">
        <span className="w-data w-tile-ref">{p.ref}</span>
        {p.priceCents != null && <span className="w-data">{formatEuros(p.priceCents * (p.qty ?? 1), locale)}</span>}
      </span>
      <PartThumb file={meshForPart(p, item.type ?? undefined)} label={[p.brand, p.label, p.ref].filter(Boolean).join(' ')} size="lg" tone={selected ? 'focus' : tone} />
    </button>
  );
}

function PartInspector({
  sheet,
  shelf,
  item,
  bundle,
  active,
  reduced,
  actionsDisabled,
  onPlan,
  onEscape,
}: {
  sheet: boolean;
  shelf: Shelf;
  item: ShelfItem;
  bundle: VehicleBundle;
  active: boolean;
  reduced: boolean;
  actionsDisabled: boolean;
  onPlan?: (job: JobItem) => void;
  onEscape: () => void;
}) {
  const t = useT();
  const [locale] = useLocale();
  const p = item.part;
  const { name, note } = partDisplay(p, item.type, locale);
  const file = meshForPart(p, item.type ?? undefined);
  const region = regionOf(p, shelf, bundle.vehicle.vehicleType ?? '');
  const closeRef = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    if (sheet) closeRef.current?.focus({ preventScroll: true });
  }, [sheet, item.key]);
  const specs: Array<[string, string]> = [
    [t.workshop.pieces.ref, p.ref],
    ...(p.brand ? [[t.workshop.pieces.brand, p.brand] as [string, string]] : []),
    ...(p.qty != null && p.qty !== 1 ? [[t.workshop.pieces.qty, `×${p.qty}`] as [string, string]] : []),
    ...(p.priceCents != null ? [[t.workshop.pieces.price, formatEuros(p.priceCents * (p.qty ?? 1), locale)] as [string, string]] : []),
    ...(p.supplier ? [[t.workshop.pieces.supplier, p.supplier] as [string, string]] : []),
  ];
  return (
    <aside
      className={`w-inspector${sheet ? ' is-sheet' : ''}`}
      aria-label={t.workshop.pieces.inspector}
      role={sheet ? 'dialog' : undefined}
      aria-modal={sheet || undefined}
      onKeyDown={(e) => e.key === 'Escape' && onEscape()}
    >
      {sheet && (
        <button ref={closeRef} type="button" className="w-icon-button w-inspector-close" onClick={onEscape} aria-label={t.workshop.pieces.closeSheet} title={t.workshop.pieces.closeSheet}>
          <Close />
        </button>
      )}
      <div className="w-inspector-render" title={t.workshop.pieces.rotate}>
        {active && file ? <PartStage key={file} file={file} reduced={reduced} /> : <PartThumb file={file} label={name} size="lg" />}
      </div>
      <div className="w-inspector-body">
        <h2 className="w-inspector-name">{name}</h2>
        {note && <p className="w-row-dim">{note}</p>}
        <div className="w-sheet-tags">
          {specs.map(([label, value]) => (
            <span key={label} className="w-tag w-tag--2">
              <span className="w-tag-label">{label}</span>
              <span className="w-data w-tag-value">{value}</span>
            </span>
          ))}
        </div>
        <p className="w-inspector-state">
          {shelf.kind === 'toOrder' ? (
            <>
              <StateMark state={shelf.job.state} immediate={shelf.job.immediate} />
              {shelf.job.taskId && <StateMark state="planned" text={t.workshop.urgent.planned} />}
              <span>{t.workshop.pieces.forJob(sentenceCase(jobTitle(shelf.job, locale)))}</span>
            </>
          ) : (
            <>
              <span>{t.workshop.pieces.fittedOn(formatDate(shelf.record.performedAt, locale))}</span>
              {shelf.record.usageValue != null && <span className="w-data w-row-dim">{reading(shelf.record.usageValue, normalizeUnit(shelf.record.usageUnit), locale)}</span>}
            </>
          )}
        </p>
        <div className="w-actions">
          {shelf.kind === 'toOrder' && onPlan && !shelf.job.taskId && (
            <button type="button" className="w-button is-primary" onClick={() => onPlan(shelf.job)} disabled={actionsDisabled}>
              {t.workshop.urgent.plan}
            </button>
          )}
        </div>
      </div>
      <figure className="w-locator">
        <figcaption className="w-mod-title">{t.workshop.pieces.locator}</figcaption>
        <div className="w-locator-car">{active && <CarStage vehicle={bundle.vehicle} focusRegion={region} ringLabel={null} reduced={reduced} />}</div>
      </figure>
    </aside>
  );
}

function PartStage({ file, reduced }: { file: PartFile; reduced: boolean }) {
  const ink = useTokenColor('--w-blue');
  return (
    <Canvas className="w-inspector-canvas" dpr={[1, 1.75]} camera={{ position: [1.25, 0.95, 1.7], fov: 30, near: 0.05, far: 20 }} gl={{ antialias: true, alpha: true }} resize={{ offsetSize: true }}>
      <MeshErrorBoundary fallback={null}>
        <Suspense fallback={null}>
          <EdgeModel url={partUrl(file)} angle={18} fit={PART_FIT} ink={ink} reduced={reduced} />
        </Suspense>
      </MeshErrorBoundary>
      <OrbitControls makeDefault enablePan={false} enableZoom={false} enableDamping={!reduced} target={[0, 0, 0]} />
    </Canvas>
  );
}

// ── Cost view ───────────────────────────────────────────────────────────────────

const CHART_H = 148;
const COMPACT_H = 36;
const PAD = { top: 18, right: 8, bottom: 20, left: 52 };
const COMPACT_PAD = { top: 2, right: 2, bottom: 2, left: 2 };
const BAR_MAX = 16;
const BAR_MIN = 4;
const GAP = 2;
const UNPRICED_SIZE = 5;
const LABEL_GAP = 64;

function niceCeil(value: number): number {
  if (value <= 0) return 1;
  const pow = 10 ** Math.floor(Math.log10(value));
  const step = [1, 2, 2.5, 5, 10].find((s) => s * pow >= value) ?? 10;
  return step * pow;
}

function CostView({
  bundle,
  compact,
  onVisit,
  onHoverVisit,
  hasShelf,
}: {
  bundle: VehicleBundle;
  compact: boolean;
  onVisit: (recordId: string) => void;
  onHoverVisit: (recordId: string | null) => void;
  hasShelf: (recordId: string) => boolean;
}) {
  const t = useT();
  const [locale] = useLocale();
  const bars = useMemo(() => costSeries(bundle.records), [bundle.records]);
  const slots = useMemo(() => visitSlots(bundle.records), [bundle.records]);
  const years = useMemo(() => costByYear(bundle.records), [bundle.records]);
  const summary = costSummary(bars);
  const host = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(0);
  const [hover, setHoverBar] = useState<string | null>(null);
  const setHover = (id: string | null) => {
    setHoverBar(id);
    onHoverVisit(id);
  };
  useLayoutEffect(() => {
    const el = host.current;
    if (!el) return;
    const measure = () => setWidth(el.clientWidth);
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [bars.length, compact]);

  if (bars.length === 0) {
    return (
      <section className="w-costs is-empty" aria-labelledby="w-costs-title">
        <h2 id="w-costs-title" className="w-mod-title">
          {t.workshop.costs.title}
        </h2>
        <p className="w-muted">{t.workshop.costs.none}</p>
      </section>
    );
  }

  const share =
    summary.partsShare === null
      ? null
      : new Intl.NumberFormat(locale, {
          style: 'percent',
          maximumFractionDigits: 0,
        }).format(summary.partsShare);
  const year = (iso: string | null) => (iso ? iso.slice(0, 4) : '');
  const hasUnsplit = bars.some((b) => b.unsplitCents > 0);
  const hasRest = bars.some((b) => b.restCents > 0);
  const hasPartial = bars.some((b) => b.partial);
  const hoveredIndex = slots.findIndex((s) => s.id === hover);
  const hoveredSlot = hoveredIndex >= 0 ? slots[hoveredIndex] : null;
  const hovered = hoveredSlot?.bar ?? null;

  const pad = compact ? COMPACT_PAD : PAD;
  const chartH = compact ? COMPACT_H : CHART_H;
  const plotW = Math.max(0, width - pad.left - pad.right);
  const plotH = chartH - pad.top - pad.bottom;
  const slotW = slots.length > 0 ? plotW / slots.length : 0;
  const barMax = compact ? 6 : BAR_MAX;
  const barW = Math.max(compact ? BAR_MIN / 3 : BAR_MIN, Math.min(barMax, slotW * 0.6));
  const cx = (i: number) => pad.left + slotW * (i + 0.5);
  const yMax = niceCeil(Math.max(...bars.map(barCents)));
  const y = (cents: number) => pad.top + plotH - (cents / yMax) * plotH;
  const ticks = [0, yMax / 2, yMax];
  const pricedAt = slots.flatMap((s, i) => (s.bar ? [i] : []));
  const pricedLabelled = labelledBars(pricedAt.map(cx), LABEL_GAP);
  const labelled = new Set(pricedAt.filter((_, k) => pricedLabelled[k]));
  const groups = slots.reduce<Array<{ year: string; start: number; end: number }>>((acc, s, i) => {
    const y4 = s.date.slice(0, 4);
    const last = acc[acc.length - 1];
    if (last && last.year === y4) last.end = i;
    else acc.push({ year: y4, start: i, end: i });
    return acc;
  }, []);
  const minYearLabelW = 30;
  const spentByYear = new Map(years.filter((y) => y.pricedVisits > 0).map((y) => [y.year, y.spentCents]));
  const summaryText = t.workshop.costs.summary(summary.visits, wholeEuros(summary.spentCents, locale), formatDate(summary.from, locale), formatDate(summary.to, locale));

  const svg =
    width > 0 ? (
      <svg width={width} height={chartH} role="img" aria-label={summaryText}>
        <defs>
          <pattern id="w-hatch" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
            <rect width="5" height="5" className="w-hatch-ground" />
            <line x1="0" y1="0" x2="0" y2="5" className="w-hatch-line" />
          </pattern>
        </defs>
        {!compact &&
          ticks.map((v) => (
            <g key={v} className="w-axis">
              <line x1={pad.left} x2={width - pad.right} y1={y(v)} y2={y(v)} />
              <text x={pad.left - 6} y={y(v)} dy="0.32em" textAnchor="end">
                {wholeEuros(v, locale)}
              </text>
            </g>
          ))}
        {compact && <line className="w-axis-base" x1={pad.left} x2={width - pad.right} y1={y(0)} y2={y(0)} />}
        {groups.slice(1).map((g) => (
          <line key={g.year} className="w-year-rule" x1={pad.left + slotW * g.start} x2={pad.left + slotW * g.start} y1={pad.top} y2={chartH - (compact ? pad.bottom : 14)} />
        ))}
        {!compact &&
          groups.map(
            (g) =>
              slotW * (g.end - g.start + 1) >= minYearLabelW && (
                <text key={g.year} className="w-axis-x" x={pad.left + (slotW * (g.start + g.end + 1)) / 2} y={chartH - 5} textAnchor="middle">
                  {g.year}
                  {spentByYear.get(g.year) ? <tspan className="w-axis-x-sum">{` ${wholeEuros(spentByYear.get(g.year) as number, locale)}`}</tspan> : null}
                </text>
              ),
          )}
        {slots.map((slot, i) => {
          const clickable = hasShelf(slot.record.id);
          const onOpen = clickable ? () => onVisit(slot.record.id) : undefined;
          const b = slot.bar;
          if (!b) {
            return (
              <UnpricedMark
                key={slot.id}
                id={slot.id}
                x={cx(i)}
                base={y(0)}
                top={pad.top}
                slotW={slotW}
                active={hover === slot.id}
                onHover={setHover}
                label={unpricedLabel(slot.date, slot.record, t, locale)}
                onOpen={onOpen}
              />
            );
          }
          return (
            <g key={slot.id}>
              <BarMarks bar={b} x={cx(i) - barW / 2} width={barW} y={y} top={pad.top} active={hover === b.id} onHover={setHover} label={barLabel(b, t, locale)} onOpen={onOpen} />
              {!compact && labelled.has(i) && (
                <text className="w-bar-total" x={cx(i)} y={y(barCents(b)) - (b.partial ? 9 : 5)} textAnchor="middle">
                  {wholeEuros(barCents(b), locale)}
                </text>
              )}
            </g>
          );
        })}
      </svg>
    ) : null;

  const tipX = hoveredSlot ? cx(hoveredIndex) : 0;
  const tipLeft = tipX + barW / 2 + 236 > width ? tipX - barW / 2 - 228 : tipX + barW / 2 + 8;
  const tooltip =
    hoveredSlot && width > 0 && !compact ? (
      <div className="w-tooltip" style={{ left: tipLeft, top: 18 }} role="presentation">
        <p className="w-tooltip-head">
          <span className="w-data">{formatDate(hoveredSlot.date, locale)}</span> {visitTitle(hoveredSlot.record, locale)}
        </p>
        {!hovered && <p className="w-tooltip-note">{t.workshop.costs.unpricedShort}</p>}
        {hovered && (
          <>
            <p className="w-tooltip-row">
              <span className="w-swatch w-swatch--parts" aria-hidden="true" />
              {t.workshop.costs.parts}
              <span className="w-data">{formatEuros(hovered.partsCents, locale)}</span>
            </p>
            {hovered.restCents > 0 && (
              <p className="w-tooltip-row">
                <span className="w-swatch w-swatch--rest" aria-hidden="true" />
                {t.workshop.costs.rest}
                <span className="w-data">{formatEuros(hovered.restCents, locale)}</span>
              </p>
            )}
            {hovered.unsplitCents > 0 && (
              <p className="w-tooltip-row">
                <span className="w-swatch w-swatch--unsplit" aria-hidden="true" />
                {t.workshop.costs.unsplit}
                <span className="w-data">{formatEuros(hovered.unsplitCents, locale)}</span>
              </p>
            )}
            {hovered.totalCents !== null && (
              <p className="w-tooltip-row w-tooltip-total">
                {t.workshop.costs.total}
                <span className="w-data">{formatEuros(hovered.totalCents, locale)}</span>
              </p>
            )}
            {hovered.partial && <p className="w-tooltip-note">{t.workshop.costs.partial}</p>}
          </>
        )}
      </div>
    ) : null;

  const table = (
    <div className="w-visually-hidden">
      <table>
        <caption>{t.workshop.costs.title}</caption>
        <thead>
          <tr>
            <th scope="col">{t.workshop.costs.date}</th>
            <th scope="col">{t.workshop.costs.visit}</th>
            <th scope="col">{t.workshop.costs.total}</th>
            <th scope="col">{t.workshop.costs.parts}</th>
            <th scope="col">{t.workshop.costs.rest}</th>
          </tr>
        </thead>
        <tbody>
          {slots.map((slot) => {
            const b = slot.bar;
            return (
              <tr key={slot.id}>
                <td>{formatDate(slot.date, locale)}</td>
                <td>{visitTitle(slot.record, locale)}</td>
                <td>{b && b.totalCents !== null ? formatEuros(b.totalCents, locale) : '—'}</td>
                <td>{b ? formatEuros(b.partsCents, locale) : '—'}</td>
                <td>{!b ? t.workshop.costs.unpricedShort : b.partial ? t.workshop.costs.partial : formatEuros(b.restCents, locale)}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );

  if (compact) {
    return (
      <section className="w-costs is-compact" aria-labelledby="w-costs-title">
        <h2 id="w-costs-title" className="w-tag-label w-costs-compact-label">
          {t.workshop.costs.spent} · {t.workshop.costs.period(year(summary.from), year(summary.to))}
        </h2>
        <span className="w-data w-costs-figure">{wholeEuros(summary.spentCents, locale)}</span>
        <div className="w-costs-chart" ref={host}>
          {svg}
          {table}
        </div>
      </section>
    );
  }

  return (
    <section className="w-costs" aria-labelledby="w-costs-title">
      <div className="w-costs-figures">
        <h2 id="w-costs-title" className="w-mod-title">
          {t.workshop.costs.title}
        </h2>
        <span className="w-tag-label">
          {t.workshop.costs.spent} · {t.workshop.costs.period(year(summary.from), year(summary.to))}
        </span>
        <span className="w-data w-costs-figure">{wholeEuros(summary.spentCents, locale)}</span>
        <p className="w-costs-minor">
          <span className="w-row-dim">{t.workshop.costs.pricedOf(summary.visits, slots.length)}</span>
          {share !== null && (
            <span>
              <span className="w-data">{share}</span> <span className="w-row-dim">{t.workshop.costs.partsShareShort}</span>
            </span>
          )}
          {summary.perThousandCents !== null && (
            <span>
              <span className="w-data">{wholeEuros(summary.perThousandCents, locale)}</span> <span className="w-row-dim">{t.workshop.costs.perThousandShort}</span>
            </span>
          )}
        </p>
      </div>
      <div className="w-costs-chart" ref={host}>
        <ul className="w-costs-legend">
          <li>
            <span className="w-swatch w-swatch--parts" aria-hidden="true" />
            {t.workshop.costs.parts}
          </li>
          {hasRest && (
            <li>
              <span className="w-swatch w-swatch--rest" aria-hidden="true" />
              {t.workshop.costs.rest}
            </li>
          )}
          {hasUnsplit && (
            <li>
              <span className="w-swatch w-swatch--unsplit" aria-hidden="true" />
              {t.workshop.costs.unsplit}
            </li>
          )}
          {hasPartial && (
            <li>
              <span className="w-swatch w-swatch--partial" aria-hidden="true" />
              {t.workshop.costs.partialKey}
            </li>
          )}
        </ul>
        {svg}
        {tooltip}
        {table}
      </div>
    </section>
  );
}

function unpricedLabel(date: string, record: MaintenanceRecord, t: ReturnType<typeof useT>, locale: Locale): string {
  return `${formatDate(date, locale)} · ${visitTitle(record, locale)} · ${t.workshop.costs.unpriced}`;
}

function UnpricedMark({
  id,
  x,
  base,
  top,
  slotW,
  active,
  onHover,
  label,
  onOpen,
}: {
  id: string;
  x: number;
  base: number;
  top: number;
  slotW: number;
  active: boolean;
  onHover: (id: string | null) => void;
  label: string;
  onOpen?: () => void;
}) {
  const hitW = Math.max(UNPRICED_SIZE + 4, slotW);
  return (
    <g
      className={`w-bar${active ? ' is-active' : ''}${onOpen ? ' is-link' : ''}`}
      tabIndex={0}
      role={onOpen ? 'button' : 'img'}
      aria-label={label}
      onPointerEnter={() => onHover(id)}
      onPointerLeave={() => onHover(null)}
      onFocus={() => onHover(id)}
      onBlur={() => onHover(null)}
      onClick={onOpen}
      onKeyDown={(e) => {
        if (onOpen && (e.key === 'Enter' || e.key === ' ')) {
          e.preventDefault();
          onOpen();
        }
      }}
    >
      <rect className="w-bar-hit" x={x - hitW / 2} y={top} width={hitW} height={base - top} />
      <rect className="w-unpriced" x={x - UNPRICED_SIZE / 2} y={base - UNPRICED_SIZE - 1} width={UNPRICED_SIZE} height={UNPRICED_SIZE} />
    </g>
  );
}

function barLabel(b: CostBar, t: ReturnType<typeof useT>, locale: Locale): string {
  return [
    formatDate(b.date, locale),
    `${t.workshop.costs.parts} ${formatEuros(b.partsCents, locale)}`,
    b.restCents > 0 ? `${t.workshop.costs.rest} ${formatEuros(b.restCents, locale)}` : null,
    b.unsplitCents > 0 ? `${t.workshop.costs.unsplit} ${formatEuros(b.unsplitCents, locale)}` : null,
    b.partial ? t.workshop.costs.partial : null,
  ]
    .filter(Boolean)
    .join(', ');
}

function BarMarks({
  bar,
  x,
  width,
  y,
  top,
  active,
  onHover,
  label,
  onOpen,
}: {
  bar: CostBar;
  x: number;
  width: number;
  y: (c: number) => number;
  top: number;
  active: boolean;
  onHover: (id: string | null) => void;
  label: string;
  onOpen?: () => void;
}) {
  const base = y(0);
  const segments: Array<{ cents: number; className: string }> = [
    { cents: bar.partsCents, className: 'w-bar-parts' },
    { cents: bar.restCents, className: 'w-bar-rest' },
    { cents: bar.unsplitCents, className: 'w-bar-unsplit' },
  ];
  let at = base;
  const rects: ReactNode[] = [];
  segments.forEach((s, i) => {
    if (s.cents <= 0) return;
    const h = base - y(s.cents);
    const start = at - (rects.length > 0 ? GAP : 0);
    const height = Math.max(1, h - (rects.length > 0 ? GAP : 0));
    rects.push(<rect key={i} className={s.className} x={x} y={start - height} width={width} height={height} />);
    at = start - height;
  });
  const total = y(barCents(bar));
  return (
    <g
      className={`w-bar${active ? ' is-active' : ''}${onOpen ? ' is-link' : ''}`}
      tabIndex={0}
      role={onOpen ? 'button' : 'img'}
      aria-label={label}
      onPointerEnter={() => onHover(bar.id)}
      onPointerLeave={() => onHover(null)}
      onFocus={() => onHover(bar.id)}
      onBlur={() => onHover(null)}
      onClick={onOpen}
      onKeyDown={(e) => {
        if (onOpen && (e.key === 'Enter' || e.key === ' ')) {
          e.preventDefault();
          onOpen();
        }
      }}
    >
      <rect className="w-bar-hit" x={x - 6} y={top} width={width + 12} height={base - top} />
      {rects}
      {bar.partial && <line className="w-bar-partial" x1={x - 2} x2={x + width + 2} y1={total - 4} y2={total - 4} />}
    </g>
  );
}
