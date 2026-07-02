import { useId, useState } from 'react';
import { coerceInt } from '../edit';
import { formatDate, formatEuros, formatReading } from '../format';
import { serviceLabel, SERVICE_TYPES } from '../labels';
import { taskTitle } from '../tasks';
import { dueDescriptor, signalTag } from '../derive';
import type { ForecastDue, PlannedPart, PlannedTask } from '../types';
import type { PlannedTaskInput, TaskEdit, TaskPartInput } from '../garage';
import type { RailItem } from './rail';
import './checklist.css';
import './rail.css';

// "Entretien" — the PERSISTENT LEFT RAIL (B1). The reading half of the split ring: the pins index the
// échéances spatially on the disc, this rail carries their prose, screen-locked on the hero's left edge.
// One severity-sorted list (the rail model), one row per item, three shapes:
//  · open/done PlannedTask → a checkable, editable task row (checklist handlers, optimistic).
//  · unplanned ForecastDue → a prediction: NO checkbox, a "planifier ○" that promotes it to a task.
//  · coverage gap → a dashed "· non suivi" row, no affordance.
// A row shares its index NN with its ring pin; hovering/focusing either half brightens the pair. The
// task-row machinery below was lifted verbatim from the old right-dock Checklist (milestone grouping
// dropped — the rail is severity-ordered). NO cyan (rationed to Le Noyau); a task is not a signal.

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

/** The row caption under a task title: its bound forecast horizon, else its target reading, else its date. */
function taskCaption(horizon: string | null, task: PlannedTask | undefined): string | null {
  if (horizon) return horizon;
  if (task?.target) return formatReading(task.target.value, task.target.unit ?? undefined);
  if (task?.dueDate) return formatDate(task.dueDate);
  return null;
}

function pad2(n: number): string {
  return String(n).padStart(2, '0');
}

export function EntretienRail({
  items,
  checklist,
  onPlan,
  hoveredIdx,
  onHoverIdx,
  dimmed = false,
}: {
  items: RailItem[];
  checklist: ChecklistHandlers;
  onPlan: (due: ForecastDue) => void;
  hoveredIdx: number | null;
  onHoverIdx: (idx: number | null) => void;
  /** dims (not hides) the rail when an on-demand surface projects — same cue as the stage/pins. */
  dimmed?: boolean;
}) {
  const [adding, setAdding] = useState(false);
  const { tasks, showDismissed, onToggleDismissed, defaultUnit } = checklist;
  const openCount = tasks.filter((t) => t.status === 'open').length;
  const doneCount = tasks.filter((t) => t.status === 'done').length;
  const dismissed = tasks.filter((t) => t.status === 'dismissed');

  // A row is highlighted when the shared index is the one hovered/focused (on either half). Focus moving
  // BETWEEN a row's own controls must not clear it — guard blur against a related target still inside.
  const hoverProps = (idx: number | null) =>
    idx == null
      ? {}
      : {
          'data-idx': idx,
          'data-active': hoveredIdx === idx ? 'true' : undefined,
          onMouseEnter: () => onHoverIdx(idx),
          onMouseLeave: () => onHoverIdx(null),
          onFocus: () => onHoverIdx(idx),
          onBlur: (e: React.FocusEvent) => {
            if (!e.currentTarget.contains(e.relatedTarget as Node)) onHoverIdx(null);
          },
        };

  const empty = items.length === 0 && dismissed.length === 0;

  return (
    <aside className="at-rail" aria-label="Entretien" data-dim={dimmed ? 'true' : undefined}>
      <div className="at-rail-head">
        <h2 className="at-rail-title">Entretien</h2>
        <span className="at-rail-count">
          {openCount} ouverte{openCount > 1 ? 's' : ''}
        </span>
      </div>

      <div className="at-rail-actions">
        <button type="button" className="at-check-btn" aria-expanded={adding} onClick={() => setAdding((a) => !a)}>
          {adding ? 'Annuler' : '+ Tâche'}
        </button>
        {doneCount > 0 && (
          <button type="button" className="at-check-btn" onClick={checklist.onArchiveDone}>
            Archiver les terminées ({doneCount})
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
        <p className="at-check-empty">Rien à suivre pour l’instant.</p>
      ) : (
        <ul className="at-rail-rows" role="list">
          {items.map((item) => {
            if (item.kind === 'gap') {
              return (
                <li key={item.id} className="at-rail-row at-rail-row--gap" data-signal="unknown">
                  <span className="at-rail-idx at-rail-idx--gap" aria-hidden="true">·</span>
                  <span className="at-rail-svc">{item.label}</span>
                  <span className="at-rail-tag at-rail-tag--gap">non suivi</span>
                </li>
              );
            }
            if (item.kind === 'due') {
              const desc = dueDescriptor(item.due!);
              const tag = signalTag(desc.overdue, desc.signal);
              const date = formatDate(item.due!.dueAt ?? item.due!.projectedDueAt);
              return (
                <li
                  key={item.id}
                  className="at-rail-row at-rail-row--due"
                  data-signal={item.signal}
                  {...hoverProps(item.index)}
                >
                  <button
                    type="button"
                    className="at-rail-plan"
                    aria-label={`${item.label} — planifier`}
                    title="Planifier cette échéance"
                    onClick={() => onPlan(item.due!)}
                  >
                    <span className="at-rail-idx" aria-hidden="true">{pad2(item.index!)}</span>
                    <span className="at-rail-plan-glyph" aria-hidden="true">○</span>
                  </button>
                  <span className="at-rail-body">
                    <span className="at-rail-svc">{item.label}</span>
                    <span className="at-rail-meta">
                      {item.horizon && <span className="at-rail-horizon">{item.horizon}</span>}
                      {date && <span className="at-rail-date">{date}</span>}
                    </span>
                  </span>
                  <span className={`at-rail-tag at-rail-tag--${tag.tone}`}>{tag.text}</span>
                </li>
              );
            }
            return (
              <TaskRow
                key={item.id}
                index={item.index}
                caption={taskCaption(item.horizon, item.task)}
                task={item.task!}
                defaultUnit={defaultUnit}
                hoverProps={hoverProps(item.index)}
                onToggle={() => checklist.onSetStatus(item.task!.id, item.done ? 'open' : 'done')}
                onDismiss={() => checklist.onSetStatus(item.task!.id, 'dismissed')}
                onRestore={() => checklist.onSetStatus(item.task!.id, 'open')}
                onEdit={(fields) => checklist.onEditTask(item.task!.id, fields)}
                onAddPart={(p) => checklist.onAddPart(item.task!.id, p)}
                onEditPart={(pid, f) => checklist.onEditPart(item.task!.id, pid, f)}
                onRemovePart={(pid) => checklist.onRemovePart(item.task!.id, pid)}
              />
            );
          })}
        </ul>
      )}

      <div className="at-rail-foot">
        <button type="button" className="at-check-btn at-check-btn--ghost" aria-pressed={showDismissed} onClick={onToggleDismissed}>
          {showDismissed ? `Masquer les retirées${dismissed.length > 0 ? ` (${dismissed.length})` : ''}` : 'Voir les tâches retirées'}
        </button>
        {showDismissed && dismissed.length > 0 && (
          <ul className="at-rail-rows at-rail-rows--dismissed" role="list">
            {dismissed.map((t) => (
              <TaskRow
                key={t.id}
                index={null}
                caption={taskCaption(null, t)}
                task={t}
                defaultUnit={defaultUnit}
                hoverProps={{}}
                onToggle={() => checklist.onSetStatus(t.id, 'open')}
                onDismiss={() => checklist.onSetStatus(t.id, 'open')}
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

// ── task row + inline forms (lifted from the old Checklist; the rail is their only consumer now) ────────

function TaskRow({
  index,
  caption,
  task,
  defaultUnit,
  hoverProps,
  onToggle,
  onDismiss,
  onRestore,
  onEdit,
  onAddPart,
  onEditPart,
  onRemovePart,
}: {
  index: number | null;
  caption: string | null;
  task: PlannedTask;
  defaultUnit: string;
  hoverProps: Record<string, unknown>;
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

  return (
    <li className={cls} {...hoverProps}>
      {index != null ? (
        <span className="at-rail-idx" aria-hidden="true">{pad2(index)}</span>
      ) : (
        <span className="at-rail-idx at-rail-idx--gap" aria-hidden="true" />
      )}
      <button
        type="button"
        className="at-task-check"
        role="checkbox"
        aria-checked={done}
        aria-label={dismissed ? 'Restaurer la tâche' : done ? 'Rouvrir la tâche' : 'Marquer comme fait'}
        onClick={dismissed ? onRestore : onToggle}
      >
        {dismissed ? '↺' : done ? '✓' : '○'}
      </button>
      <div className="at-task-body">
        <div className="at-task-head">
          <span className="at-task-title">{taskTitle(task)}</span>
          {!dismissed && (
            <button type="button" className="at-task-edit-toggle" aria-label="Modifier la tâche" aria-expanded={editing} title="Modifier" onClick={() => setEditing((e) => !e)}>
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
            {task.parts.length > 0 ? (
              <ul className="at-task-parts">
                {task.parts.map((p, i) => (
                  <li className="at-task-part" key={p.id ?? `${p.ref}-${i}`}>
                    {p.ref && <span className="at-task-part-ref">{p.ref}</span>}
                    {p.brand && <span className="at-task-part-brand">{p.brand}</span>}
                    {p.label && <span className="at-task-part-label">{p.label}</span>}
                    {p.priceCents != null && <span className="at-task-part-price">{formatEuros(p.priceCents)}</span>}
                    {p.qty != null && p.qty > 1 && <span className="at-task-part-qty">×{p.qty}</span>}
                  </li>
                ))}
              </ul>
            ) : null}
          </>
        )}
      </div>
      <button
        type="button"
        className="at-task-dismiss"
        aria-label={dismissed ? 'Restaurer la tâche' : 'Retirer la tâche'}
        title={dismissed ? 'Restaurer' : 'Retirer'}
        onClick={dismissed ? onRestore : onDismiss}
      >
        {dismissed ? '↺' : '×'}
      </button>
    </li>
  );
}

function AddTaskForm({ defaultUnit, onSubmit }: { defaultUnit: string; onSubmit: (input: PlannedTaskInput) => void }) {
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
      <input list={listId} className="at-task-input" placeholder="type (ex. brake_pads)" aria-label="Type de service" value={type} onChange={(e) => setType(e.target.value)} />
      <datalist id={listId}>
        {SERVICE_TYPES.map((t) => (
          <option key={t} value={t}>
            {serviceLabel(t)}
          </option>
        ))}
      </datalist>
      <input className="at-task-input at-task-input--grow" placeholder="libellé (optionnel)" aria-label="Libellé" value={label} onChange={(e) => setLabel(e.target.value)} />
      <input className="at-task-input at-task-input--sm" inputMode="numeric" placeholder={`à … ${defaultUnit}`} aria-label="Échéance kilométrage" value={target} onChange={(e) => setTarget(e.target.value)} />
      <button type="submit" className="at-check-btn at-check-btn--do" disabled={!type.trim()}>
        Ajouter
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
        <input list={listId} className="at-task-input" placeholder="type" aria-label="Type de service" value={type} onChange={(e) => setType(e.target.value)} />
        <datalist id={listId}>
          {SERVICE_TYPES.map((t) => (
            <option key={t} value={t}>
              {serviceLabel(t)}
            </option>
          ))}
        </datalist>
        <input className="at-task-input at-task-input--grow" placeholder="libellé (optionnel)" aria-label="Libellé" value={label} onChange={(e) => setLabel(e.target.value)} />
        <input className="at-task-input at-task-input--sm" inputMode="numeric" placeholder={`à … ${unit}`} aria-label="Échéance kilométrage" value={usage} onChange={(e) => setUsage(e.target.value)} />
        <input className="at-task-input at-task-input--sm" type="date" aria-label="Échéance date" value={dueDate} disabled={usage.trim() !== ''} onChange={(e) => setDueDate(e.target.value)} />
        <div className="at-task-edit-actions">
          <button type="submit" className="at-check-btn at-check-btn--do" disabled={!type.trim()}>
            Enregistrer
          </button>
          <button type="button" className="at-check-btn" onClick={onCancel}>
            Annuler
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
              <span>{p.label ?? 'Fluide'}</span>
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
      <input className="at-task-input at-task-input--sm" placeholder="réf." aria-label="Référence" value={ref} onChange={(e) => setRef(e.target.value)} onBlur={commit} />
      <input className="at-task-input at-task-input--sm" placeholder="marque" aria-label="Marque" value={brand} onChange={(e) => setBrand(e.target.value)} onBlur={commit} />
      <input className="at-task-input at-task-input--grow" placeholder="libellé" aria-label="Libellé de la pièce" value={label} onChange={(e) => setLabel(e.target.value)} onBlur={commit} />
      <input className="at-task-input at-task-input--xs" inputMode="numeric" placeholder="qté" aria-label="Quantité" value={qty} onChange={(e) => setQty(e.target.value)} onBlur={commit} />
      <button type="button" className="at-task-part-del" aria-label="Retirer cette pièce" title="Retirer" onClick={onRemove}>
        ×
      </button>
    </div>
  );
}

/** The blank add-a-part row at the foot of the parts editor (its own form — nested forms are illegal). */
function AddPartRow({ onAdd }: { onAdd: (part: TaskPartInput) => void }) {
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
      <input className="at-task-input at-task-input--sm" placeholder="réf." aria-label="Référence" value={ref} onChange={(e) => setRef(e.target.value)} />
      <input className="at-task-input at-task-input--sm" placeholder="marque" aria-label="Marque" value={brand} onChange={(e) => setBrand(e.target.value)} />
      <input className="at-task-input at-task-input--grow" placeholder="libellé" aria-label="Libellé de la pièce" value={label} onChange={(e) => setLabel(e.target.value)} />
      <input className="at-task-input at-task-input--xs" inputMode="numeric" placeholder="qté" aria-label="Quantité" value={qty} onChange={(e) => setQty(e.target.value)} />
      <button type="submit" className="at-check-btn at-check-btn--do" disabled={!ref.trim() && !label.trim()}>
        + Pièce
      </button>
    </form>
  );
}
