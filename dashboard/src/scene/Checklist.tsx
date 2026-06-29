import { useId, useState } from 'react';
import { coerceInt } from '../edit';
import { formatEuros } from '../format';
import { serviceLabel, SERVICE_TYPES } from '../labels';
import { groupByMilestone, taskTitle } from '../tasks';
import type { PlannedPart, PlannedTask } from '../types';
import type { PlannedTaskInput, TaskEdit, TaskPartInput } from '../garage';
import './checklist.css';

// "Entretien en cours" — the interactive open-checklist (#10). The actionable counterpart to the
// truth-panel échéances (which are forecasts, not tasks): each PlannedTask can be ticked done / reopened
// / dismissed / restored, its definition (type/label/target/dueDate) edited, and its shopping-list parts
// added / edited / removed (the parts subresource). Grouped by milestone (usage target → date → none).
// A live-hero glass panel; rebuilt fresh in the Atelier idiom (the old VehicleDetail is reference only).
// NO cyan (rationed to Le Noyau); a task is not a signal, so no signal colour.

export interface ChecklistHandlers {
  /** open + done (and dismissed when showDismissed) — the list to render. */
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

export function Checklist({
  tasks,
  showDismissed,
  onToggleDismissed,
  onSetStatus,
  onEditTask,
  onAddTask,
  onAddPart,
  onEditPart,
  onRemovePart,
  onArchiveDone,
  defaultUnit,
}: ChecklistHandlers) {
  const [adding, setAdding] = useState(false);
  const openCount = tasks.filter((t) => t.status === 'open').length;
  const doneCount = tasks.filter((t) => t.status === 'done').length;
  const dismissedCount = tasks.filter((t) => t.status === 'dismissed').length;
  const milestones = groupByMilestone(tasks);

  return (
    <section className="at-check" aria-label="Entretien en cours">
      <div className="at-check-head">
        <h3 className="at-check-title">Entretien en cours</h3>
        <span className="at-check-count">
          {openCount} ouverte{openCount > 1 ? 's' : ''}
        </span>
      </div>

      <div className="at-check-actions">
        <button type="button" className="at-check-btn" aria-expanded={adding} onClick={() => setAdding((a) => !a)}>
          {adding ? 'Annuler' : '+ Tâche'}
        </button>
        {doneCount > 0 && (
          <button type="button" className="at-check-btn" onClick={onArchiveDone}>
            Archiver les terminées ({doneCount})
          </button>
        )}
      </div>

      {adding && (
        <AddTaskForm
          defaultUnit={defaultUnit}
          onSubmit={(input) => {
            onAddTask(input);
            setAdding(false);
          }}
        />
      )}

      {tasks.length === 0 ? (
        <p className="at-check-empty">Aucune tâche planifiée.</p>
      ) : (
        <div className="at-check-milestones">
          {milestones.map((m) => (
            <div className="at-check-milestone" key={m.key}>
              <h4 className="at-check-milestone-label">{m.label}</h4>
              <ul className="at-check-tasks">
                {m.tasks.map((t) => (
                  <TaskRow
                    key={t.id}
                    task={t}
                    defaultUnit={defaultUnit}
                    onToggle={() => onSetStatus(t.id, t.status === 'done' ? 'open' : 'done')}
                    onDismiss={() => onSetStatus(t.id, 'dismissed')}
                    onRestore={() => onSetStatus(t.id, 'open')}
                    onEdit={(fields) => onEditTask(t.id, fields)}
                    onAddPart={(p) => onAddPart(t.id, p)}
                    onEditPart={(pid, f) => onEditPart(t.id, pid, f)}
                    onRemovePart={(pid) => onRemovePart(t.id, pid)}
                  />
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}

      <div className="at-check-foot">
        <button type="button" className="at-check-btn at-check-btn--ghost" aria-pressed={showDismissed} onClick={onToggleDismissed}>
          {showDismissed ? `Masquer les retirées${dismissedCount > 0 ? ` (${dismissedCount})` : ''}` : 'Voir les tâches retirées'}
        </button>
      </div>
    </section>
  );
}

function TaskRow({
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
  const cls = `at-task${dismissed ? ' at-task--dismissed' : done ? ' at-task--done' : ''}`;

  return (
    <li className={cls}>
      <button
        type="button"
        className="at-task-check"
        aria-label={dismissed ? 'Restaurer la tâche' : done ? 'Rouvrir la tâche' : 'Marquer comme fait'}
        aria-pressed={done}
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
        ) : task.parts.length > 0 ? (
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
