import { useId, useState } from 'react';
import { coerceInt } from '../edit';
import { formatDate, formatReading } from '../format';
import { serviceLabel, SERVICE_TYPES } from '../labels';
import { taskTitle } from '../tasks';
import { dueDescriptor, signalTag } from '../derive';
import { useLocale, useT } from '../i18n/LocaleProvider';
import type { Locale } from '../i18n/locale';
import { messages } from '../i18n/messages';
import type { ForecastDue, PlannedPart, PlannedTask } from '../types';
import type { PlannedTaskInput, TaskEdit, TaskPartInput } from '../garage';
import { closedTasks, type RailItem } from './rail';
import { laterFrom, rowClock, visitClock, visitRegions, visitSignal, type Visit, type VisitClock, type VisitPlan } from './visits';
import type { BodyRegion } from './anchors';
import './checklist.css';
import './rail.css';

// "Entretien" — the PERSISTENT LEFT RAIL, screen-locked on the hero's left edge. Grouped by VISIT —
// the trip to the lift — because that is the unit a garage books (CHOREOGRAPHY.md § "The rail is
// grouped by VISIT"). Three horizons:
//  · "Maintenant" — late work + decided-but-undated tasks, one card per BODY REGION (what shares a
//    teardown). Every late row keeps its red/amber text tag.
//  · "Prochaine visite" — the soonest dated cluster, one card.
//  · "Plus tard · n" — a COUNT that projects the ladder into the right column. Never a list: seven rows
//    saying "OK" is seven rows of cost for no information.
//  · gaps — dashed "· non suivi" rows, no affordance.
// Row shapes: a task row is checkable/editable (checklist handlers, optimistic); a due row is a
// prediction. NO cyan (rationed to Le Noyau); a task is not a signal.
//
// Parts are a COUNT here, never a catalogue: three brands at three prices is a decision, and the rail is
// a status surface. The count opens the row's own editor, where the lines are editable.
//
// The rail is the AMBIENT layer (docs/design-inspiration.md § 2) and it is built out of the same material
// as the summoned surfaces in the right column — card ground, hairline, radius, a hair leader toward the
// car — so the two columns bracket the stage as one instrument instead of a sidebar beside a canvas. What
// only this layer has: a severity spine per card, and a head that can aim the earned camera at the part
// of the car the visit is about.

export interface ChecklistHandlers {
  /** open + done (and dismissed when showDismissed) — the source for counts + the retirées section. */
  tasks: PlannedTask[];
  showDismissed: boolean;
  onToggleDismissed: () => void;
  onSetStatus: (taskIri: string, status: 'open' | 'done' | 'dismissed') => void;
  onEditTask: (taskIri: string, fields: TaskEdit) => void;
  onAddTask: (input: PlannedTaskInput) => void;
  onAddPart: (taskIri: string, part: TaskPartInput) => void;
  onEditPart: (taskIri: string, partId: string, fields: TaskPartInput) => void;
  onRemovePart: (taskIri: string, partId: string) => void;
  onArchiveDone: () => void;
  /** The vehicle's usage-axis unit (km|mi|h) — seeds a new task's target unit. */
  defaultUnit: string;
}

/** What a region-grouped visit is called — the teardown it shares, in the operator's words. */
const regionLabel = (region: BodyRegion, locale: Locale): string => messages(locale).region[region];

/**
 * The row caption under a task title. Under a card head that already carries the booking's clock the row
 * says only what the head does NOT — nothing at all on the head's own day. Under a clockless head (the
 * region cards of « Maintenant ») it falls back the way it always did: horizon, else target, else date.
 */
function taskCaption(clock: VisitClock, headHasClock: boolean, task: PlannedTask | undefined, locale: Locale): string | null {
  if (clock.horizon) return clock.horizon;
  if (clock.date) return formatDate(clock.date, locale);
  if (headHasClock) return null;
  if (task?.target) return formatReading(task.target.value, task.target.unit ?? undefined, locale);
  if (task?.dueDate) return formatDate(task.dueDate, locale);
  return null;
}

/** Cards and rows assemble in order, capped so the last one is never late. Paint only — nothing waits. */
const STAGGER_CAP = 4;
const step = (i: number) => ({ '--at-step': Math.min(i, STAGGER_CAP) }) as React.CSSProperties;

export function EntretienRail({
  plan,
  checklist,
  onPlan,
  onShowLaterVisits,
  dimmed = false,
  spotlit,
  heldRegion = null,
  onAimRegion,
  reduced = false,
}: {
  /** The rail regrouped by trip to the lift — the ONLY model this component renders. */
  plan: VisitPlan;
  checklist: ChecklistHandlers;
  onPlan: (due: ForecastDue) => void;
  /** Projects `plan.later` as the ladder. Absent → the count renders as text, never a dead control. */
  onShowLaterVisits?: () => void;
  /** dims (not hides) the rail when an on-demand surface projects — same cue as the stage. */
  dimmed?: boolean;
  /** The body region the earned camera currently holds — the cards that read off it light their tie to
   *  the car (CHOREOGRAPHY.md § "the camera is held on a region"). */
  heldRegion?: BodyRegion | null;
  /** Aim the earned camera at a visit's region. Absent → the card head is a plain caption, never a dead
   *  control. The TOGGLE (re-aim vs release) is resolved by the caller, which owns `cameraFocus`. */
  onAimRegion?: (region: BodyRegion) => void;
  /** Collapses the card/row arrival to an instant state change. Every projection takes this; the rail
   *  read it only through a CSS media query, so `[data-reduced]` rules above it never matched. */
  reduced?: boolean;
  /** service types the agent spotlighted (`focusView {panel:'tasks', keys}`) — those rows carry the
   *  operator cue so the projected answer and the persistent rail name the SAME rows. Documented in
   *  CHOREOGRAPHY.md since Stage B; the rows never actually lit until 2026-08-15. */
  spotlit?: string[];
}) {
  const lit = spotlit && spotlit.length > 0 ? new Set(spotlit) : null;
  const [adding, setAdding] = useState(false);
  const [locale] = useLocale();
  const t = useT();
  const { tasks, showDismissed, onToggleDismissed, defaultUnit } = checklist;
  const doneCount = tasks.filter((t) => t.status === 'done').length;
  const lateCount = plan.now.reduce((n, v) => n + v.items.filter((i) => i.due?.overdue).length, 0);
  // Done AND dismissed: both are closed, and both need a way back. The drawer used to hold only the
  // dismissed, which made ticking a task off — a whole-row click — irreversible (rail.ts closedTasks).
  const closed = closedTasks(tasks);

  const laterServices = plan.later.reduce((n, v) => n + v.items.length, 0);
  const empty =
    plan.now.length === 0 && !plan.next && plan.later.length === 0 && plan.gaps.length === 0 && closed.length === 0;

  const renderItem = (visit: Visit, item: RailItem, index: number, inTodo: boolean) => {
    // ONE clock per booking: the card head carries the visit's date + horizon, so a member falling on
    // that same day says nothing more. Only a member on a DIFFERENT day still names its own.
    const clock = rowClock(visit, item);
    const headHasClock = visitClock(visit).date !== null;
    if (item.kind === 'due') {
      const desc = dueDescriptor(item.due!, locale);
      const tag = signalTag(desc.overdue, desc.signal, locale);
      return (
        <li
          key={item.id}
          className="at-rail-row at-rail-row--due"
          style={step(index)}
          data-signal={item.signal}
          data-lit={lit?.has(item.type) ? 'true' : undefined}
        >
          <button
            type="button"
            className="at-rail-plan"
            aria-label={t.rail.planAria(item.label)}
            title={t.rail.planThis}
            onClick={() => onPlan(item.due!)}
          >
            <span className="at-rail-plan-glyph" aria-hidden="true">○</span>
          </button>
          <span className="at-rail-body">
            <span className="at-rail-svc">{item.label}</span>
            {(clock.horizon || clock.date) && (
              <span className="at-rail-meta">
                {clock.horizon && <span className="at-rail-horizon">{clock.horizon}</span>}
                {clock.date && <span className="at-rail-date">{formatDate(clock.date, locale)}</span>}
              </span>
            )}
          </span>
          <span className={`at-rail-tag at-rail-tag--${tag.tone}`}>{tag.text}</span>
        </li>
      );
    }
    // A task row keeps its text tag whenever a forecast binds it — a late task is still late (the "À
    // faire" bucket must read red row by row, not only by its section header).
    const tag = item.due ? signalTag(item.due.overdue && !item.done, item.done ? 'green' : item.signal, locale) : null;
    return (
      <TaskRow
        key={item.id}
        step={step(index)}
        caption={taskCaption(clock, headHasClock, item.task, locale)}
        tag={inTodo || (tag && tag.tone !== 'ok') ? tag : null}
        signal={item.due && !item.done ? item.signal : undefined}
        lit={lit?.has(item.type) ?? false}
        task={item.task!}
        defaultUnit={defaultUnit}
        onToggle={() => checklist.onSetStatus(item.task!.id, item.done ? 'open' : 'done')}
        onDismiss={() => checklist.onSetStatus(item.task!.id, 'dismissed')}
        onRestore={() => checklist.onSetStatus(item.task!.id, 'open')}
        onEdit={(fields) => checklist.onEditTask(item.task!.id, fields)}
        onAddPart={(p) => checklist.onAddPart(item.task!.id, p)}
        onEditPart={(pid, f) => checklist.onEditPart(item.task!.id, pid, f)}
        onRemovePart={(pid) => checklist.onRemovePart(item.task!.id, pid)}
      />
    );
  };

  return (
    <aside className="at-rail" data-reduced={reduced ? 'true' : undefined} aria-label={t.rail.title} data-dim={dimmed ? 'true' : undefined}>
      <div className="at-rail-head">
        <h2 className="at-rail-title">{t.rail.title}</h2>
        {lateCount > 0 && (
          <span className="at-rail-count at-rail-count--late">
            {lateCount} en retard
          </span>
        )}
      </div>

      <div className="at-rail-actions">
        <button type="button" className="at-check-btn" aria-expanded={adding} onClick={() => setAdding((a) => !a)}>
          {adding ? t.rail.cancel : t.rail.addTask}
        </button>
        {doneCount > 0 && (
          <button type="button" className="at-check-btn" onClick={checklist.onArchiveDone}>
            {t.rail.archiveDone(doneCount)}
          </button>
        )}
      </div>

      {adding && (
        <AddTaskForm
          defaultUnit={defaultUnit}
          onSubmit={(input) => {
            checklist.onAddTask(input);
            setAdding(false);
          }}
        />
      )}

      {empty ? (
        <p className="at-check-empty">{t.rail.empty}</p>
      ) : (
        <>
          {/* The three horizons are NOT three equal headings. Emphasis is vibrancy + the card spine, never
              another font size (CHOREOGRAPHY § Vibrancy tiers): tier 1 here, tier 2 for the next visit,
              tier 3 for the tail. The red is spent only when something is ACTUALLY late — "Maintenant"
              also holds decided-but-undated work, and colouring it red then is a false alarm. */}
          {plan.now.length > 0 && (
            <section className="at-rail-section at-rail-section--now" aria-label={t.rail.now}>
              <h3 className="at-rail-section-title" data-late={lateCount > 0 ? 'true' : undefined}>
                {t.rail.now}
              </h3>
              {plan.now.map((visit, i) => (
                <VisitCard key={visit.id} visit={visit} index={i} held={heldRegion} onAim={onAimRegion}>
                  {visit.items.map((item, n) => renderItem(visit, item, n, true))}
                </VisitCard>
              ))}
            </section>
          )}
          {plan.next && (
            <section className="at-rail-section at-rail-section--next" aria-label={t.rail.nextVisit}>
              <h3 className="at-rail-section-title">{t.rail.nextVisit}</h3>
              <VisitCard visit={plan.next} index={0} held={heldRegion} onAim={onAimRegion}>
                {plan.next.items.map((item, n) => renderItem(plan.next as Visit, item, n, false))}
              </VisitCard>
            </section>
          )}
          {plan.later.length > 0 && (
            <section className="at-rail-section at-rail-section--later" aria-label={t.rail.later}>
              {/* A count, not a list — and a CONTROL, because compression is only honest if there is a
                  way to look closer. It projects the ladder into the right column. It also sits FAR from
                  the next visit: distance is how the tail reads as far away rather than merely hidden
                  (Gibson's rule, docs/design-inspiration.md § 4), the same language the ladder speaks. */}
              {onShowLaterVisits ? (
                <button type="button" className="at-rail-later" onClick={onShowLaterVisits}>
                  <span className="at-rail-later-label">{t.rail.later}</span>
                  <span className="at-rail-later-count">{plan.later.length}</span>
                  <span className="at-rail-later-meta">
                    {laterFrom(plan.later) ? t.rail.laterFrom(laterFrom(plan.later)!) : t.rail.laterServices(laterServices)}
                  </span>
                </button>
              ) : (
                <p className="at-rail-later at-rail-later--static">
                  {t.rail.laterCount(plan.later.length)}
                </p>
              )}
            </section>
          )}
          {plan.gaps.length > 0 && (
            <ul className="at-rail-rows at-rail-rows--gaps" role="list">
              {plan.gaps.map((item) => (
                <li
                  key={item.id}
                  className="at-rail-row at-rail-row--gap"
                  data-signal="unknown"
                  data-lit={lit?.has(item.type) ? 'true' : undefined}
                >
                  <span className="at-rail-svc">{item.label}</span>
                  <span className="at-rail-tag at-rail-tag--gap">{t.rail.untracked}</span>
                </li>
              ))}
            </ul>
          )}
        </>
      )}

      <div className="at-rail-foot">
        <button type="button" className="at-check-btn at-check-btn--ghost" aria-pressed={showDismissed} onClick={onToggleDismissed}>
          {showDismissed ? t.rail.hideClosed : t.rail.showClosed(closed.length)}
        </button>
        {showDismissed && closed.length > 0 && (
          <ul className="at-rail-rows at-rail-rows--dismissed" role="list">
            {closed.map((t) => (
              <TaskRow
                key={t.id}
                caption={taskCaption({ date: null, horizon: null }, false, t, locale)}
                task={t}
                defaultUnit={defaultUnit}
                // Both closed states reopen from here — that is what the drawer is for. `×` is the one
                // control that keeps its own meaning: on a DONE row it still retires the task rather
                // than reopening it, so the two closed states stay reachable from each other.
                onToggle={() => checklist.onSetStatus(t.id, 'open')}
                onDismiss={() => checklist.onSetStatus(t.id, t.status === 'done' ? 'dismissed' : 'open')}
                onRestore={() => checklist.onSetStatus(t.id, 'open')}
                onEdit={(fields) => checklist.onEditTask(t.id, fields)}
                onAddPart={(p) => checklist.onAddPart(t.id, p)}
                onEditPart={(pid, f) => checklist.onEditPart(t.id, pid, f)}
                onRemovePart={(pid) => checklist.onRemovePart(t.id, pid)}
              />
            ))}
          </ul>
        )}
      </div>
    </aside>
  );
}

/**
 * One trip to the lift, as an OBJECT — the same material as the projection surfaces on the right (dark
 * ground, hairline, radius, a hair leader toward the car), plus one thing only the ambient layer carries:
 * a severity spine down its gutter, coloured by the visit's worst signal. That spine is what makes
 * « Maintenant » read louder than « Prochaine visite » without a second font size.
 *
 * The HEAD is the camera control (the rows tick tasks off, so they cannot also be it): it names the
 * booking — the teardown for undated work, the date for a dated cluster — and aiming it dollies the
 * earned camera to the part of the car the work is on. A card that says « Moteur » can show the moteur.
 */
function VisitCard({
  visit,
  index,
  held,
  onAim,
  children,
}: {
  visit: Visit;
  index: number;
  held: BodyRegion | null;
  onAim?: (region: BodyRegion) => void;
  children: React.ReactNode;
}) {
  const [locale] = useLocale();
  const t = useT();
  const clock = visitClock(visit);
  const caption = visit.region ? regionLabel(visit.region, locale) : clock.date ? formatDate(clock.date, locale) : null;
  const regions = visitRegions(visit);
  const aim = regions[0];
  const isHeld = !!held && regions.includes(held);
  const count = visit.items.length;

  const head = caption && (
    <>
      <span className="at-rail-visit-caption">{caption}</span>
      {count > 1 && <span className="at-rail-visit-count">{count}</span>}
      {aim && onAim && <span className="at-rail-visit-aim" aria-hidden="true">⌖</span>}
      {clock.horizon && <span className="at-rail-visit-horizon">{clock.horizon}</span>}
    </>
  );

  return (
    <div className="at-rail-visit" style={step(index)} data-worst={visitSignal(visit)} data-held={isHeld ? 'true' : undefined}>
      {caption &&
        (aim && onAim ? (
          <button
            type="button"
            className="at-rail-visit-head at-rail-visit-head--aim"
            aria-pressed={isHeld}
            title={isHeld ? t.rail.backToOverview : t.rail.seeRegionOnCar(regionLabel(aim, locale))}
            aria-label={t.rail.cardAria(caption ?? '', isHeld ? t.rail.backToOverviewLower : t.rail.seeRegionOnCarLower(regionLabel(aim, locale)))}
            onClick={() => onAim(aim)}
          >
            {head}
          </button>
        ) : (
          <div className="at-rail-visit-head">{head}</div>
        ))}
      <ul className="at-rail-rows" role="list">
        {children}
      </ul>
    </div>
  );
}

// ── task row + inline forms (lifted from the old Checklist; the rail is their only consumer now) ────────

function TaskRow({
  caption,
  step: stagger,
  tag = null,
  signal,
  lit = false,
  task,
  defaultUnit,
  onToggle,
  onDismiss,
  onRestore,
  onEdit,
  onAddPart,
  onEditPart,
  onRemovePart,
}: {
  caption: string | null;
  /** the row's place in its card's assembly order (`--at-step`) — paint stagger only, absent = no delay. */
  step?: React.CSSProperties;
  /** the forecast text tag (EN RETARD / BIENTÔT) when the task binds a due — null hides it. */
  tag?: { text: string; tone: string } | null;
  /** the bound forecast's signal — colours the caption horizon exactly like a due row's (a late task's
   * "DÉPASSÉ de…" must read as red as an unplanned one's). Absent → neutral caption. */
  signal?: string;
  /** the agent spotlighted this service — carries the operator cue (see EntretienRail `spotlit`). */
  lit?: boolean;
  task: PlannedTask;
  defaultUnit: string;
  onToggle: () => void;
  onDismiss: () => void;
  onRestore: () => void;
  onEdit: (fields: TaskEdit) => void;
  onAddPart: (part: TaskPartInput) => void;
  onEditPart: (partId: string, fields: TaskPartInput) => void;
  onRemovePart: (partId: string) => void;
}) {
  const done = task.status === 'done';
  const dismissed = task.status === 'dismissed';
  const [editing, setEditing] = useState(false);
  const cls = `at-task at-rail-row${dismissed ? ' at-task--dismissed' : done ? ' at-task--done' : ''}`;

  const [locale] = useLocale();
  const t = useT();

  // Clicking the row body toggles the task (the same act as its checkbox) — but let its own controls
  // (checkbox, ✎ edit, × dismiss, and every input while the editor is open) own their clicks, and never
  // toggle mid-edit (a stray body click must not collapse the form the operator is filling in).
  const onRowClick = (e: React.MouseEvent) => {
    if (editing) return;
    if ((e.target as HTMLElement).closest('button, input, a, form, label')) return;
    (dismissed ? onRestore : onToggle)();
  };

  return (
    <li className={cls} style={stagger} data-signal={signal} data-lit={lit ? 'true' : undefined} data-activatable="true" onClick={onRowClick}>
      <button
        type="button"
        className="at-task-check"
        role="checkbox"
        aria-checked={done}
        aria-label={dismissed ? t.rail.restoreTask : done ? t.rail.reopenTask : t.rail.markDone}
        onClick={dismissed ? onRestore : onToggle}
      >
        {dismissed ? '↺' : done ? '✓' : '○'}
      </button>
      <div className="at-task-body">
        <div className="at-task-head">
          <span className="at-task-title">{taskTitle(task, locale)}</span>
          {tag && <span className={`at-rail-tag at-rail-tag--${tag.tone}`}>{tag.text}</span>}
          {!dismissed && (
            <button type="button" className="at-task-edit-toggle" aria-label={t.rail.editTask} aria-expanded={editing} title={t.rail.edit} onClick={() => setEditing((e) => !e)}>
              ✎
            </button>
          )}
        </div>
        {editing ? (
          <TaskEditForm
            task={task}
            defaultUnit={defaultUnit}
            onSubmit={(fields) => {
              onEdit(fields);
              setEditing(false);
            }}
            onCancel={() => setEditing(false)}
            onAddPart={onAddPart}
            onEditPart={onEditPart}
            onRemovePart={onRemovePart}
          />
        ) : (
          <>
            {caption && <span className="at-rail-caption">{caption}</span>}
            {task.parts.length > 0 && (
              // A COUNT, not the catalogue: brands and prices are a decision, and the rail states. The
              // control opens this row's own editor, where the lines are readable AND editable.
              <button
                type="button"
                className="at-task-parts-count"
                onClick={() => setEditing(true)}
                aria-label={t.rail.seeRefs(task.parts.length, taskTitle(task, locale))}
              >
                {t.rail.refShort(task.parts.length)}
              </button>
            )}
          </>
        )}
      </div>
      <button
        type="button"
        className="at-task-dismiss"
        aria-label={dismissed ? t.rail.restoreTask : t.rail.dismissTask}
        title={dismissed ? t.rail.restore : t.rail.remove}
        onClick={dismissed ? onRestore : onDismiss}
      >
        {dismissed ? '↺' : '×'}
      </button>
    </li>
  );
}

function AddTaskForm({ defaultUnit, onSubmit }: { defaultUnit: string; onSubmit: (input: PlannedTaskInput) => void }) {
  const [locale] = useLocale();
  const t = useT();
  const listId = useId();
  const [type, setType] = useState('oil_change');
  const [label, setLabel] = useState('');
  const [target, setTarget] = useState('');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!type.trim()) return;
    const n = coerceInt(target);
    onSubmit({ type: type.trim(), label: label.trim() || null, target: n != null ? { value: n, unit: defaultUnit } : null });
  };

  return (
    <form className="at-task-form at-task-form--add" onSubmit={submit}>
      <input list={listId} className="at-task-input" placeholder={t.rail.typePlaceholder} aria-label={t.rail.typeLabel} value={type} onChange={(e) => setType(e.target.value)} />
      <datalist id={listId}>
        {SERVICE_TYPES.map((t) => (
          <option key={t} value={t}>
            {serviceLabel(t, locale)}
          </option>
        ))}
      </datalist>
      <input className="at-task-input at-task-input--grow" placeholder={t.rail.labelPlaceholder} aria-label={t.rail.labelAria} value={label} onChange={(e) => setLabel(e.target.value)} />
      <input className="at-task-input at-task-input--sm" inputMode="numeric" placeholder={t.rail.targetPlaceholder(defaultUnit)} aria-label={t.rail.targetAria} value={target} onChange={(e) => setTarget(e.target.value)} />
      <button type="submit" className="at-check-btn at-check-btn--do" disabled={!type.trim()}>
        {t.rail.add}
      </button>
    </form>
  );
}

/** The inline editor a task row opens: the definition (type/label/target/dueDate) + a parts editor. */
function TaskEditForm({
  task,
  defaultUnit,
  onSubmit,
  onCancel,
  onAddPart,
  onEditPart,
  onRemovePart,
}: {
  task: PlannedTask;
  defaultUnit: string;
  onSubmit: (fields: TaskEdit) => void;
  onCancel: () => void;
  onAddPart: (part: TaskPartInput) => void;
  onEditPart: (partId: string, fields: TaskPartInput) => void;
  onRemovePart: (partId: string) => void;
}) {
  const [locale] = useLocale();
  const t = useT();
  const listId = useId();
  const unit = task.target?.unit ?? defaultUnit;
  const [type, setType] = useState(task.type);
  const [label, setLabel] = useState(task.label ?? '');
  const [usage, setUsage] = useState(task.target ? String(task.target.value) : '');
  const [dueDate, setDueDate] = useState(task.dueDate ?? '');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!type.trim()) return;
    const n = coerceInt(usage);
    const target = n != null ? { value: n, unit } : null;
    // A usage target wins over a date (milestoneOf prefers it); clear the date when one is set.
    onSubmit({ type: type.trim(), label: label.trim() || null, target, dueDate: target ? null : dueDate || null });
  };

  return (
    <div className="at-task-edit">
      <form className="at-task-form" onSubmit={submit}>
        <input list={listId} className="at-task-input" placeholder={t.rail.typePlaceholder} aria-label={t.rail.typeLabel} value={type} onChange={(e) => setType(e.target.value)} />
        <datalist id={listId}>
          {SERVICE_TYPES.map((t) => (
            <option key={t} value={t}>
              {serviceLabel(t, locale)}
            </option>
          ))}
        </datalist>
        <input className="at-task-input at-task-input--grow" placeholder={t.rail.labelPlaceholder} aria-label={t.rail.labelAria} value={label} onChange={(e) => setLabel(e.target.value)} />
        <input className="at-task-input at-task-input--sm" inputMode="numeric" placeholder={t.rail.targetPlaceholder(unit)} aria-label={t.rail.targetAria} value={usage} onChange={(e) => setUsage(e.target.value)} />
        <input className="at-task-input at-task-input--sm" type="date" aria-label={t.rail.dueDateAria} value={dueDate} disabled={usage.trim() !== ''} onChange={(e) => setDueDate(e.target.value)} />
        <div className="at-task-edit-actions">
          <button type="submit" className="at-check-btn at-check-btn--do" disabled={!type.trim()}>
            {t.rail.save}
          </button>
          <button type="button" className="at-check-btn" onClick={onCancel}>
            {t.rail.cancel}
          </button>
        </div>
      </form>
      <div className="at-task-parts-edit">
        {task.parts.map((p, i) =>
          p.id ? (
            <PartEditRow key={p.id} part={p} onSave={(fields) => onEditPart(p.id as string, fields)} onRemove={() => onRemovePart(p.id as string)} />
          ) : (
            // A derived fluid line has no id — read-only (it is projected, not stored).
            <div key={`derived-${i}`} className="at-task-part-row at-task-part-row--derived">
              <span>{p.label ?? t.fluid.fallbackLabel}</span>
            </div>
          ),
        )}
        <AddPartRow onAdd={onAddPart} />
      </div>
    </div>
  );
}

/** One editable shopping-list line: commits on blur, only when a field actually changed. */
function PartEditRow({ part, onSave, onRemove }: { part: PlannedPart; onSave: (fields: TaskPartInput) => void; onRemove: () => void }) {
  const t = useT();
  const [ref, setRef] = useState(part.ref);
  const [brand, setBrand] = useState(part.brand ?? '');
  const [label, setLabel] = useState(part.label ?? '');
  const [qty, setQty] = useState(part.qty != null ? String(part.qty) : '');

  const commit = () => {
    const next: TaskPartInput = { ref: ref.trim(), brand: brand.trim() || null, label: label.trim() || null, qty: qty.trim() ? coerceInt(qty) : null };
    const unchanged =
      next.ref === part.ref && (next.brand ?? null) === (part.brand ?? null) && (next.label ?? null) === (part.label ?? null) && (next.qty ?? null) === (part.qty ?? null);
    if (unchanged) return;
    onSave(next);
  };

  return (
    <div className="at-task-part-row">
      <input className="at-task-input at-task-input--sm" placeholder={t.rail.refPlaceholder} aria-label={t.rail.refAria} value={ref} onChange={(e) => setRef(e.target.value)} onBlur={commit} />
      <input className="at-task-input at-task-input--sm" placeholder={t.rail.brandPlaceholder} aria-label={t.rail.brandAria} value={brand} onChange={(e) => setBrand(e.target.value)} onBlur={commit} />
      <input className="at-task-input at-task-input--grow" placeholder={t.rail.partLabelPlaceholder} aria-label={t.rail.partLabelAria} value={label} onChange={(e) => setLabel(e.target.value)} onBlur={commit} />
      <input className="at-task-input at-task-input--xs" inputMode="numeric" placeholder={t.rail.qtyPlaceholder} aria-label={t.rail.qtyAria} value={qty} onChange={(e) => setQty(e.target.value)} onBlur={commit} />
      <button type="button" className="at-task-part-del" aria-label={t.rail.removePart} title={t.rail.remove} onClick={onRemove}>
        ×
      </button>
    </div>
  );
}

/** The blank add-a-part row at the foot of the parts editor (its own form — nested forms are illegal). */
function AddPartRow({ onAdd }: { onAdd: (part: TaskPartInput) => void }) {
  const t = useT();
  const [ref, setRef] = useState('');
  const [brand, setBrand] = useState('');
  const [label, setLabel] = useState('');
  const [qty, setQty] = useState('');

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!ref.trim() && !label.trim()) return;
    onAdd({ ref: ref.trim(), brand: brand.trim() || null, label: label.trim() || null, qty: qty.trim() ? coerceInt(qty) : null });
    setRef('');
    setBrand('');
    setLabel('');
    setQty('');
  };

  return (
    <form className="at-task-part-row at-task-part-row--add" onSubmit={add}>
      <input className="at-task-input at-task-input--sm" placeholder={t.rail.refPlaceholder} aria-label={t.rail.refAria} value={ref} onChange={(e) => setRef(e.target.value)} />
      <input className="at-task-input at-task-input--sm" placeholder={t.rail.brandPlaceholder} aria-label={t.rail.brandAria} value={brand} onChange={(e) => setBrand(e.target.value)} />
      <input className="at-task-input at-task-input--grow" placeholder={t.rail.partLabelPlaceholder} aria-label={t.rail.partLabelAria} value={label} onChange={(e) => setLabel(e.target.value)} />
      <input className="at-task-input at-task-input--xs" inputMode="numeric" placeholder={t.rail.qtyPlaceholder} aria-label={t.rail.qtyAria} value={qty} onChange={(e) => setQty(e.target.value)} />
      <button type="submit" className="at-check-btn at-check-btn--do" disabled={!ref.trim() && !label.trim()}>
        {t.rail.addPart}
      </button>
    </form>
  );
}
