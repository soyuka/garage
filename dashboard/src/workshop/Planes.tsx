import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { formatDate, formatEuros } from '../format';
import { factLabel, fluidLabel, serviceLabel } from '../labels';
import { visitTitle } from '../records';
import type { BodyRegion } from '../scene/anchors';
import type { VehicleBundle, VehicleFact } from '../types';
import { CarStage } from './CarStage';
import { useFlag } from './hooks';
import { Pause, Play } from './Icons';
import { TURNTABLE_KEY } from './prefs';
import { AddBar, EditVisitButton, JobEditActions, VehicleEditor } from './Editors';
import { useEditHost } from './edits';
import { isArchived } from './archive';
import { jobTitle, jobWhen, reading, sentenceCase } from './describe';
import { normalizeUnit } from './kmTrack';
import { factGroups, isIdentifier, packColumns, splitVarying } from './datasheet';
import { factValues } from './factValue';
import { Module, NodeRows } from './Module';
import { entretienLists, type DocRow, type JobItem, type JobList, type Modules } from './modules';
import { StateMark } from './StateMark';
import { vehicleName } from './TopBar';
import type { ViewAction, ViewState } from './view';

interface PlaneProps {
  /** The projection slot; only the planes that carry one render it. */
  slot: ReactNode;
  bundle: VehicleBundle;
  modules: Modules;
  view: ViewState;
  onOpenRow: (row: DocRow) => void;
  onView: (action: ViewAction) => void;
}

const NEXT_LIMIT = 3;
const TIERS = [1, 2, 3] as const;
// Past this a value reads as a sentence: set in the text face, not as a mono figure.
const PROSE = 48;

function CarPlate({
  bundle,
  view,
  active,
  reduced,
  hoverRegion,
  onPoint,
}: {
  bundle: VehicleBundle;
  view: ViewState;
  active: boolean;
  reduced: boolean;
  hoverRegion?: BodyRegion | null;
  onPoint?: () => void;
}) {
  const t = useT();
  const [locale] = useLocale();
  const [turntable, setTurntable] = useFlag(TURNTABLE_KEY, false);
  const region = hoverRegion ?? view.focusRegion;
  const ringLabel = !hoverRegion && view.focusRegion && view.focusTopic ? `${serviceLabel(view.focusTopic, locale)} · ${t.region[view.focusRegion]}` : null;
  return (
    <section className="w-plate" aria-label={t.workshop.mod.car}>
      {active && (
        <CarStage vehicle={bundle.vehicle} focusRegion={region} ringLabel={ringLabel} reduced={reduced} pointSeq={hoverRegion ? undefined : view.focusSeq} onPoint={onPoint} turntable={turntable} />
      )}
      <p className="w-plate-caption">
        <span>{vehicleName(bundle.vehicle)}</span>
        {isArchived(bundle.vehicle) && <span className="w-archived-mark">{t.workshop.home.archivedMark}</span>}
        {bundle.vehicle.engineVariant && <span className="w-data">{bundle.vehicle.engineVariant}</span>}
        {bundle.vehicle.usageValue != null && <span className="w-data w-plate-reading">{reading(bundle.vehicle.usageValue, normalizeUnit(bundle.vehicle.usageUnit), locale)}</span>}
        <button
          type="button"
          className="w-icon-button w-turntable"
          aria-pressed={turntable && !reduced}
          aria-label={t.workshop.car.turntable}
          title={reduced ? t.workshop.car.turntableReduced : t.workshop.car.turntable}
          disabled={reduced}
          onClick={() => setTurntable(!turntable)}
        >
          {turntable && !reduced ? <Pause /> : <Play />}
        </button>
      </p>
    </section>
  );
}

function JobListModule({ title, list, bundle, onView, labelledBy }: { title: string; list: JobList; bundle: VehicleBundle; onView: (a: ViewAction) => void; labelledBy: string }) {
  const t = useT();
  const [locale] = useLocale();
  const unit = normalizeUnit(bundle.vehicle.usageUnit);
  const detail = (j: JobItem) =>
    [j.dueReading !== null ? t.workshop.atReading(reading(j.dueReading, j.remaining?.unit ?? unit, locale)) : null, j.dueDate ? formatDate(j.dueDate, locale) : null].filter(Boolean).join(' · ');
  return (
    <Module title={title} labelledBy={labelledBy}>
      <ul className="w-rows">
        {list.jobs.map((j) => (
          <li key={j.id} className="w-row">
            <StateMark state={j.state} immediate={j.immediate} />
            <span className="w-row-main w-job-title" title={jobTitle(j, locale)}>
              {sentenceCase(jobTitle(j, locale))}
            </span>
            {!j.immediate && <span className="w-data w-row-end">{jobWhen(j, locale)}</span>}
            <JobEditActions job={j} bundle={bundle} />
            {detail(j) && <span className="w-data w-row-dim w-row-sub">{detail(j)}</span>}
          </li>
        ))}
      </ul>
      {list.more > 0 && (
        <button type="button" className="w-more" onClick={() => onView({ type: 'focusView', panel: 'tasks' })}>
          {t.workshop.more.jobs(list.more)}
        </button>
      )}
    </Module>
  );
}

function EntretienLists({ bundle, modules, onView, urgentShown }: { bundle: VehicleBundle; modules: Modules; onView: (a: ViewAction) => void; urgentShown: boolean }) {
  const t = useT();
  const { aFaire, bientot } = entretienLists(modules, NEXT_LIMIT, urgentShown);
  return (
    <>
      {aFaire.jobs.length > 0 && <JobListModule title={t.workshop.mod.aFaire} list={aFaire} bundle={bundle} onView={onView} labelledBy="w-mod-todo" />}
      {bientot.jobs.length > 0 ? (
        <JobListModule title={t.workshop.mod.bientot} list={bientot} bundle={bundle} onView={onView} labelledBy="w-mod-soon" />
      ) : (
        <Module title={t.workshop.mod.bientot} labelledBy="w-mod-soon">
          <p className="w-muted">{t.workshop.empty.bientot}</p>
        </Module>
      )}
    </>
  );
}

function LastVisitLine({ bundle, modules }: { bundle: VehicleBundle; modules: Modules }) {
  const t = useT();
  const [locale] = useLocale();
  const v = modules.derniereVisite;
  const cost = v ? (v.effectiveCostCents ?? v.costCents ?? null) : null;
  return (
    <p className="w-mod w-last-visit">
      <span className="w-mod-title">{t.workshop.mod.derniereVisite}</span>
      {v ? (
        <>
          <span className="w-data">{formatDate(v.performedAt, locale)}</span>
          <span className="w-row-main" title={visitTitle(v, locale)}>
            {visitTitle(v, locale)}
          </span>
          {v.usageValue != null && <span className="w-data w-row-dim">{reading(v.usageValue, normalizeUnit(v.usageUnit ?? bundle.vehicle.usageUnit), locale)}</span>}
          {cost != null && <span className="w-data">{formatEuros(cost, locale)}</span>}
          <EditVisitButton record={v} />
        </>
      ) : (
        <span className="w-muted">{t.workshop.empty.visit}</span>
      )}
    </p>
  );
}

export function EntretienPlane({ slot, bundle, modules, view, active, reduced, onView, editor, onPoint }: PlaneProps & { active: boolean; reduced: boolean; editor: ReactNode; onPoint?: () => void }) {
  return (
    <div className="w-split">
      <CarPlate bundle={bundle} view={view} active={active} reduced={reduced} onPoint={onPoint} />
      <div className="w-side">
        {editor ?? slot}
        <EntretienLists bundle={bundle} modules={modules} onView={onView} urgentShown={!view.projection && !editor} />
        <LastVisitLine bundle={bundle} modules={modules} />
        <AddBar items={['visit', 'task', 'rule']} className="w-mod" />
      </div>
    </div>
  );
}

export function CaracteristiquesPlane({ bundle, view }: PlaneProps) {
  const t = useT();
  const [locale] = useLocale();
  const v = bundle.vehicle;
  const host = useEditHost();
  const [editing, setEditing] = useState(false);
  const editButton = useRef<HTMLButtonElement>(null);
  useEffect(() => setEditing(false), [v['@id']]);
  const stopEditing = () => {
    setEditing(false);
    window.requestAnimationFrame(() => editButton.current?.focus());
  };
  const facts = v.facts ?? [];
  const p = view.projection;
  const lit = new Set(p?.kind === 'facts' || (p?.kind === 'panel' && p.panel === 'specs') ? p.keys : []);
  const nodes = p?.kind === 'facts' ? (p.nodes ?? []) : [];
  const known = new Set(facts.map((f) => f.key));
  const identity: Array<[string, string | null | undefined]> = [
    ['plate', v.plate],
    ['make', v.make],
    ['model', v.model],
    ['year', v.year ? String(v.year) : null],
    ['engine_variant', v.engineVariant],
    ['engine_code', known.has('engine_code') ? null : v.engineCode],
    ['usage', v.usageValue != null ? reading(v.usageValue, normalizeUnit(v.usageUnit), locale) : null],
  ];
  const synthetic: VehicleFact[] = identity.filter((e): e is [string, string] => !!e[1]).map(([key, value]) => ({ key, value, source: 'vehicle', verified: false }));
  const raw = new Set(synthetic.map((f) => f.key));
  const labels: Record<string, string> = {
    plate: t.workshop.identity.plate,
    make: t.truth.make,
    model: t.truth.model,
    year: t.workshop.identity.year,
    engine_variant: t.workshop.datasheet.variant,
    usage: t.truth.usage,
    engine_oil: t.workshop.datasheet.engineOil,
  };
  const label = (key: string) => {
    const fluid = /^(.+)_fluid$/.exec(key)?.[1];
    return labels[key] ?? (fluid ? (fluidLabel(fluid, locale) ?? factLabel(`${fluid}_type`, locale)) : factLabel(key, locale));
  };
  const values = (f: VehicleFact) => (raw.has(f.key) ? [f.value] : factValues(f.value, f.unit, locale, { identifier: isIdentifier(f.key) }));
  const split = (fs: VehicleFact[]) => {
    const parts = splitVarying(fs.filter((f) => !raw.has(f.key)));
    return { fixed: [...fs.filter((f) => raw.has(f.key)), ...parts.fixed], varying: parts.varying };
  };
  const tags = (list: string[], prefix: string) =>
    list.map((value, i) => (
      <span key={`${prefix}-${i}`} className={value.length > PROSE ? 'w-tag-value w-tag-value--prose' : 'w-data w-tag-value'}>
        {value}
      </span>
    ));
  const groups = factGroups([...synthetic, ...facts]);
  if (!groups.some((g) => g.system === 'identity')) groups.unshift({ system: 'identity', entries: [] });
  return (
    <div className="w-sheet-page">
      {nodes.length > 0 && p?.kind === 'facts' && (
        <Module title={p.title ?? t.workshop.projection.facts} labelledBy="w-mod-nodes" className="is-summoned w-sheet-summoned">
          <NodeRows nodes={nodes} />
        </Module>
      )}
      {facts.length === 0 && <p className="w-muted w-sheet-empty">{t.workshop.facts.empty}</p>}
      <SheetMasonry
        items={groups.map((g) => ({
          key: g.system,
          node: (
            <section key={g.system} className="w-sheet-box" aria-labelledby={`w-sheet-${g.system}`}>
              <div className="w-sheet-box-head">
                <h2 id={`w-sheet-${g.system}`} className="w-mod-title">
                  {t.workshop.datasheet.systems[g.system]}
                </h2>
                {g.system === 'identity' && host && !editing && (
                  <button ref={editButton} type="button" className="w-act" aria-label={t.workshop.edit.editVehicle} onClick={() => setEditing(true)}>
                    {t.workshop.edit.edit}
                  </button>
                )}
              </div>
              {g.system === 'identity' && editing && <VehicleEditor vehicle={v} onDone={stopEditing} />}
              {!(g.system === 'identity' && editing) &&
                TIERS.map((tier) => {
                  const row = g.entries.filter((e) => e.tier === tier);
                  if (row.length === 0) return null;
                  return (
                    <div key={tier} className={`w-sheet-tags w-sheet-tags--${tier}`}>
                      {row.map((e) => (
                        <div key={e.key} className={`w-tag w-tag--${e.tier}${e.facts.some((f) => lit.has(f.key)) ? ' is-lit' : ''}`}>
                          <span className="w-tag-label">{label(e.key)}</span>
                          <span className="w-tag-values">
                            {split(e.facts).fixed.flatMap((f) => tags(values(f), f.key))}
                            {split(e.facts).varying.length > 0 && (
                              <details className="w-tag-varies">
                                <summary>{t.workshop.datasheet.varies}</summary>
                                <span className="w-tag-values w-tag-variants">{split(e.facts).varying.flatMap((f) => tags(values(f), f.key))}</span>
                              </details>
                            )}
                          </span>
                          {e.facts.every((f) => f.verified) && split(e.facts).varying.length === 0 && <span className="w-verified">{t.workshop.facts.verified}</span>}
                        </div>
                      ))}
                    </div>
                  );
                })}
            </section>
          ),
        }))}
      />
    </div>
  );
}

const SHEET_COL_MIN = 380;
const SHEET_COLS_MAX = 3;

/** Boxes packed shortest-column-first, measured: no column leaves a void while another runs past the fold. */
function SheetMasonry({ items }: { items: Array<{ key: string; node: ReactNode }> }) {
  const host = useRef<HTMLDivElement>(null);
  const [cols, setCols] = useState(SHEET_COLS_MAX);
  const [heights, setHeights] = useState<number[]>([]);
  useLayoutEffect(() => {
    const el = host.current;
    if (!el) return;
    const measure = () => {
      setCols(Math.max(1, Math.min(SHEET_COLS_MAX, Math.floor(el.clientWidth / SHEET_COL_MIN))));
      const next = items.map((it) => el.querySelector<HTMLElement>(`[data-box="${it.key}"]`)?.offsetHeight ?? 0);
      setHeights((prev) => (prev.length === next.length && prev.every((h, i) => h === next[i]) ? prev : next));
    };
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    el.querySelectorAll('[data-box]').forEach((box) => ro.observe(box));
    return () => ro.disconnect();
  }, [items]);
  const packed = packColumns(heights.length === items.length ? heights : items.map(() => 1), cols);
  return (
    <div className="w-sheet" ref={host} style={{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }}>
      {packed.map((col, c) => (
        <div key={c} className="w-sheet-col">
          {col.map((i) => (
            <div key={items[i].key} data-box={items[i].key}>
              {items[i].node}
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}
