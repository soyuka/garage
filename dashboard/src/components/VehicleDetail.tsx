import { useEffect, useId, useRef, useState } from 'react';
import { Badge } from '../design/components/Badge';
import { Card } from '../design/components/Card';
import type { MaintenanceRecordInput, TaskPartInput, PlannedTaskInput, ServiceRuleInput, TaskEdit } from '../garage';
import { serviceLabel, SERVICE_TYPES } from '../labels';
import type { FactPanel, FluidSpec, PlannedPart, PlannedTask, Vehicle } from '../types';
import { FactRow, factLabel } from './facts';
import { ForecastSection } from './ForecastSection';
import { InlineEdit } from './InlineEdit';
import { ScheduleSection } from './ScheduleSection';
import { PastMaintenanceRail } from './PastMaintenanceRail';
import { TaskListSkeleton } from './Skeletons';

/** The card's editable scalars — the subset the PATCH endpoint accepts. */
export interface VehicleEdit {
  make?: string;
  model?: string;
  year?: number | null;
  engineVariant?: string | null;
  engineCode?: string | null;
  kType?: number | null;
  plate?: string | null;
  usageValue?: number;
  usageUnit?: string;
}

function taskTitle(task: PlannedTask): string {
  const base = serviceLabel(task.type);
  return task.label ? `${base} — ${task.label}` : base;
}

/** The milestone a task is pegged to — its usage target, else its due date, else none. */
function milestoneOf(task: PlannedTask): { key: string; label: string } {
  if (task.target) {
    const unit = task.target.unit ? ` ${task.target.unit}` : '';
    return { key: `u:${task.target.value}:${task.target.unit ?? ''}`, label: `À ${task.target.value.toLocaleString('fr-FR')}${unit}` };
  }
  if (task.dueDate) {
    return { key: `d:${task.dueDate}`, label: `Avant le ${task.dueDate}` };
  }
  return { key: 'none', label: 'Sans échéance' };
}

interface Milestone {
  key: string;
  label: string;
  tasks: PlannedTask[];
}

/** Bucket the checklist by milestone, preserving the backend order (open before done). */
function groupByMilestone(tasks: PlannedTask[]): Milestone[] {
  const groups: Milestone[] = [];
  const byKey = new Map<string, Milestone>();
  for (const task of tasks) {
    const { key, label } = milestoneOf(task);
    let group = byKey.get(key);
    if (!group) {
      group = { key, label, tasks: [] };
      byKey.set(key, group);
      groups.push(group);
    }
    group.tasks.push(task);
  }
  return groups;
}

export function VehicleDetail({
  vehicle,
  onEdit,
  onSetTaskStatus,
  onEditTask,
  onAddTaskPart,
  onEditTaskPart,
  onRemoveTaskPart,
  onArchiveDone,
  onAddTask,
  tasksLoading,
  showAllTasks,
  onToggleShowAllTasks,
  factPanel,
  onExpandFacts,
  onDismissFacts,
  onFillFact,
  onDeleteFact,
  recordsLoading,
  onAddRecord,
  onEditRecord,
  onDeleteRecord,
  onUploadAttachment,
  onDeleteAttachment,
  forecastLoading,
  scheduleLoading,
  onSaveRule,
  onDeleteRule,
  onTitleVisibility,
}: {
  vehicle: Vehicle;
  onEdit: (edit: VehicleEdit) => void;
  onSetTaskStatus: (taskIri: string, status: 'open' | 'done' | 'dismissed') => void;
  onEditTask: (taskIri: string, fields: TaskEdit) => void;
  onAddTaskPart: (taskIri: string, part: TaskPartInput) => void;
  onEditTaskPart: (taskIri: string, partId: string, fields: TaskPartInput) => void;
  onRemoveTaskPart: (taskIri: string, partId: string) => void;
  onArchiveDone: () => void;
  onAddTask: (input: PlannedTaskInput) => void;
  tasksLoading: boolean;
  showAllTasks: boolean;
  onToggleShowAllTasks: () => void;
  // The agent's focused fact answer, drawn into the Données panel instead of a separate card.
  factPanel: FactPanel | null;
  onExpandFacts: () => void;
  onDismissFacts: () => void;
  onFillFact: (key: string, value: string, unit?: string | null) => void;
  onDeleteFact: (key: string) => void;
  recordsLoading: boolean;
  onAddRecord: (input: MaintenanceRecordInput) => void;
  onEditRecord: (recordIri: string, input: MaintenanceRecordInput) => void;
  onDeleteRecord: (recordIri: string) => void;
  onUploadAttachment: (recordIri: string, file: File) => void;
  onDeleteAttachment: (attachmentUrl: string) => void;
  forecastLoading: boolean;
  scheduleLoading: boolean;
  onSaveRule: (input: ServiceRuleInput) => void;
  onDeleteRule: (ruleId: string) => void;
  // Fires as the vehicle title scrolls in/out of view, so the chrome can echo its identity once lost.
  onTitleVisibility?: (visible: boolean) => void;
}) {
  // The API's maintenance-health rollup (item GET only) → meaning-locked status chip. Null on the
  // listing (rollup absent) — the chip simply doesn't render until the item GET fills it.
  // Road vehicles read in km; ground-care machinery accrues engine hours.
  const defaultUnit = vehicle.usageUnit ?? (vehicle.vehicleType === 'groundscare' ? 'h' : 'km');
  const facts = vehicle.facts ?? [];
  const tasks = vehicle.plannedTasks ?? [];
  const openCount = tasks.filter((t) => t.status === 'open').length;
  const doneIris = tasks.filter((t) => t.status === 'done').map((t) => t.id);
  const dismissedCount = tasks.filter((t) => t.status === 'dismissed').length;
  const milestones = groupByMilestone(tasks);
  const [addingTask, setAddingTask] = useState(false);
  // The Données list is on-demand reference, collapsed by default. The agent's show_fact answer drives
  // this same panel — it forces it open and (until "Tout afficher") narrows it to the asked keys —
  // rather than docking a second card. focused ⇒ a live, unexpanded fact answer is spotlighting keys.
  const [localOpen, setLocalOpen] = useState(false);
  const [addingFact, setAddingFact] = useState(false);
  const focused = factPanel != null && !factPanel.expanded;
  const dataOpen = localOpen || factPanel != null;
  const shownFacts = focused ? facts.filter((f) => factPanel!.keys.includes(f.key)) : facts;
  // Asked keys the vehicle has no stored fact for — surfaced as click-to-fill rows so the answer is honest.
  const missing = focused ? factPanel!.keys.filter((k) => !facts.some((f) => f.key === k)) : [];

  // Scroll the panel into view when the agent answers, since it sits below the fold of the detail.
  const dataRef = useRef<HTMLElement>(null);
  useEffect(() => {
    if (factPanel) dataRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }, [factPanel]);

  // Tell the chrome when the title leaves the viewport so the top bar can echo the vehicle identity.
  const titleRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const el = titleRef.current;
    if (!el || !onTitleVisibility) return;
    onTitleVisibility(true);
    const obs = new IntersectionObserver(([entry]) => onTitleVisibility(entry.isIntersecting), { threshold: 0 });
    obs.observe(el);
    return () => {
      obs.disconnect();
      onTitleVisibility(true);
    };
  }, [onTitleVisibility]);

  // Disclosure click: when an agent answer is spotlighting facts, a click dismisses it (and collapses);
  // otherwise it's the plain manual open/close toggle.
  const toggleData = () => {
    if (factPanel) {
      onDismissFacts();
      setLocalOpen(false);
    } else {
      setLocalOpen((o) => !o);
    }
  };

  const commitUsage = (raw: string) => {
    const value = Number.parseInt(raw.replace(/[^\d]/g, ''), 10);
    if (Number.isNaN(value)) return;
    onEdit({ usageValue: value, usageUnit: defaultUnit });
  };

  const commitInt = (raw: string): number | null => {
    const value = Number.parseInt(raw.replace(/[^\d]/g, ''), 10);
    return Number.isNaN(value) ? null : value;
  };

  return (
    <>
      <div className="title-row" ref={titleRef}>
        <h1>
          <InlineEdit value={vehicle.make} placeholder="marque" ariaLabel="Marque" onCommit={(v) => onEdit({ make: v })} />{' '}
          <InlineEdit value={vehicle.model} placeholder="modèle" ariaLabel="Modèle" onCommit={(v) => onEdit({ model: v })} />
        </h1>
        <Badge style={{ padding: 0 }}>
          <span className="usage-badge">
            <InlineEdit
              value={vehicle.usageValue != null ? vehicle.mileage : ''}
              placeholder="+ relevé"
              type="text"
              ariaLabel="Relevé (km / heures)"
              className="badge-edit"
              onCommit={commitUsage}
            />
            {vehicle.usageValue != null ? (
              <select
                aria-label="Unité de relevé"
                className="usage-unit-badge"
                value={vehicle.usageUnit ?? defaultUnit}
                onChange={(e) => onEdit({ usageUnit: e.target.value })}
              >
                <option value="km">km</option>
                <option value="mi">mi</option>
                <option value="h">h</option>
              </select>
            ) : (
              <span className="usage-unit-static">{defaultUnit}</span>
            )}
          </span>
        </Badge>
        <Badge style={{ padding: 0 }}>
          <InlineEdit
            value={vehicle.year ? String(vehicle.year) : ''}
            placeholder="+ année"
            ariaLabel="Année"
            className="badge-edit"
            onCommit={(v) => onEdit({ year: commitInt(v) })}
          />
        </Badge>
        <Badge style={{ padding: 0 }}>
          <InlineEdit
            value={vehicle.engineVariant ?? ''}
            placeholder="+ finition"
            ariaLabel="Finition / motorisation"
            className="badge-edit"
            onCommit={(v) => onEdit({ engineVariant: v || null })}
          />
        </Badge>
        <Badge style={{ padding: 0 }}>
          <InlineEdit
            value={vehicle.engineCode ?? ''}
            placeholder="+ moteur"
            ariaLabel="Code moteur"
            className="badge-edit"
            onCommit={(v) => onEdit({ engineCode: v || null })}
          />
        </Badge>
        <Badge style={{ padding: 0 }}>
          <InlineEdit
            value={vehicle.plate ?? ''}
            placeholder="+ plaque"
            ariaLabel="Plaque d'immatriculation"
            className="plate-edit"
            onCommit={(next) => onEdit({ plate: next || null })}
          />
        </Badge>
        <Badge style={{ padding: 0 }}>
          <InlineEdit
            value={vehicle.kType ? String(vehicle.kType) : ''}
            placeholder="+ kType"
            ariaLabel="TecDoc kType"
            className="badge-edit"
            onCommit={(v) => onEdit({ kType: commitInt(v) })}
          />
        </Badge>
        {vehicle.verified ? (
          <Badge variant="verified" check>
            TecDoc vérifié
          </Badge>
        ) : null}
      </div>

      <Card className="hud-panel" style={{ marginTop: 'var(--mpa-gap-grid)' }}>
        <ForecastSection
          forecast={vehicle.forecast}
          health={vehicle.maintenanceHealth}
          loading={forecastLoading}
          currentUsage={{ value: vehicle.usageValue ?? null, unit: defaultUnit }}
          rules={vehicle.serviceSchedule ?? []}
          onQuickLogGap={onAddRecord}
        />
      </Card>

      <Card className="hud-panel" style={{ marginTop: 'var(--mpa-gap-grid)' }}>
        <div className="panel-title">
          <span className="t">Entretien en cours</span>
          <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--mpa-space-3)' }}>
            <button type="button" className="task-clean" aria-expanded={addingTask} onClick={() => setAddingTask((a) => !a)}>
              {addingTask ? 'Annuler' : '+ Ajouter une tâche'}
            </button>
            {doneIris.length > 0 ? (
              <button type="button" className="task-clean" onClick={() => onArchiveDone()}>
                Archiver les entretiens terminés ({doneIris.length})
              </button>
            ) : null}
            <span style={{ color: 'var(--mpa-muted)', fontSize: 'var(--mpa-text-label)' }}>
              {openCount} ouverte{openCount > 1 ? 's' : ''}
            </span>
          </span>
        </div>
        {addingTask ? (
          <AddTaskForm
            onSubmit={(input) => {
              onAddTask(input);
              setAddingTask(false);
            }}
          />
        ) : null}
        {tasksLoading ? (
          <TaskListSkeleton />
        ) : tasks.length === 0 ? (
          <div style={{ color: 'var(--mpa-faint)', fontSize: 'var(--mpa-text-small)' }}>
            Aucune tâche planifiée. Ajoutez-en une, ou demandez à l'assistant « quel entretien à X km ? ».
          </div>
        ) : (
          <div className="milestones">
            {milestones.map((m) => (
              <section key={m.key} className="milestone">
                <h3 className="milestone-title">{m.label}</h3>
                <div className="tasks">
                  {m.tasks.map((t) => (
                    <TaskRow
                      key={t.id}
                      task={t}
                      onToggle={() => onSetTaskStatus(t.id, t.status === 'done' ? 'open' : 'done')}
                      onDismiss={() => onSetTaskStatus(t.id, 'dismissed')}
                      onRestore={() => onSetTaskStatus(t.id, 'open')}
                      onEdit={(fields) => onEditTask(t.id, fields)}
                      onAddPart={(part) => onAddTaskPart(t.id, part)}
                      onEditPart={(partId, fields) => onEditTaskPart(t.id, partId, fields)}
                      onRemovePart={(partId) => onRemoveTaskPart(t.id, partId)}
                    />
                  ))}
                </div>
              </section>
            ))}
          </div>
        )}
        {tasks.length > 0 || showAllTasks ? (
          <div className="task-foot">
            <button type="button" aria-pressed={showAllTasks} onClick={onToggleShowAllTasks}>
              {showAllTasks ? `Masquer les tâches retirées${dismissedCount > 0 ? ` (${dismissedCount})` : ''}` : 'Voir les tâches retirées'}
            </button>
          </div>
        ) : null}
      </Card>

      <Card className="hud-panel" style={{ marginTop: 'var(--mpa-gap-grid)' }}>
        <PastMaintenanceRail
          records={vehicle.maintenanceRecords}
          loading={recordsLoading}
          onAddRecord={onAddRecord}
          onEditRecord={onEditRecord}
          onDeleteRecord={onDeleteRecord}
          onUploadAttachment={onUploadAttachment}
          onDeleteAttachment={onDeleteAttachment}
        />
      </Card>

      <Card ref={dataRef} className="hud-panel" style={{ marginTop: 'var(--mpa-gap-grid)' }}>
        <div className="panel-title panel-disclosure-row">
          <button type="button" className="panel-disclosure" aria-expanded={dataOpen} onClick={toggleData}>
            <span className="t">
              <span className="disclosure-caret">{focused ? '×' : dataOpen ? '▾' : '▸'}</span>{' '}
              {focused && factPanel!.title ? factPanel!.title : 'Données'}
            </span>
          </button>
          <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--mpa-space-3)' }}>
            {!focused ? (
              <button
                type="button"
                className="task-clean"
                aria-expanded={addingFact}
                onClick={() => {
                  setLocalOpen(true);
                  setAddingFact((a) => !a);
                }}
              >
                {addingFact ? 'Annuler' : '+ Ajouter une donnée'}
              </button>
            ) : null}
            <span style={{ color: 'var(--mpa-muted)', fontSize: 'var(--mpa-text-label)' }}>
              {facts.length} enregistrée{facts.length > 1 ? 's' : ''}
            </span>
          </span>
        </div>
        {dataOpen ? (
          <>
            {addingFact && !focused ? (
              <AddFactForm
                onSubmit={(key, value) => {
                  onFillFact(key, value);
                  setAddingFact(false);
                }}
              />
            ) : null}
            {shownFacts.length === 0 && missing.length === 0 ? (
              <div style={{ color: 'var(--mpa-faint)', fontSize: 'var(--mpa-text-small)' }}>Aucune donnée enregistrée.</div>
            ) : (
              <div className="facts">
                {shownFacts.map((f) => (
                  <FactRow key={f.key} fact={f} onDelete={focused ? undefined : onDeleteFact} onEdit={focused ? undefined : onFillFact} />
                ))}
                {missing.map((k) => (
                  <div key={k} className="fact-row fact-row-missing">
                    <span className="fact-key">{factLabel(k)}</span>
                    <span className="fact-value">
                      <InlineEdit
                        value=""
                        placeholder="non renseignée — cliquer pour saisir"
                        ariaLabel={`Saisir ${factLabel(k)}`}
                        className="fact-fill"
                        onCommit={(value) => onFillFact(k, value)}
                      />
                    </span>
                  </div>
                ))}
              </div>
            )}
          </>
        ) : null}
        {focused && facts.length > shownFacts.length ? (
          <button type="button" className="fact-panel-expand" onClick={onExpandFacts}>
            Tout afficher ({facts.length})
          </button>
        ) : null}
      </Card>

      <Card className="hud-panel" style={{ marginTop: 'var(--mpa-gap-grid)' }}>
        <ScheduleSection
          rules={vehicle.serviceSchedule}
          loading={scheduleLoading}
          onSave={onSaveRule}
          onDelete={onDeleteRule}
        />
      </Card>
    </>
  );
}

function AddTaskForm({ onSubmit }: { onSubmit: (input: PlannedTaskInput) => void }) {
  const [type, setType] = useState('oil_change');
  const [label, setLabel] = useState('');
  const [target, setTarget] = useState('');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!type.trim()) return;
    const km = Number.parseInt(target.replace(/[^\d]/g, ''), 10);
    onSubmit({
      type: type.trim(),
      label: label.trim() || null,
      target: Number.isNaN(km) ? null : { value: km, unit: 'km' },
    });
  };

  return (
    <form className="add-form" onSubmit={submit}>
      <input list="task-service-types" className="add-input" placeholder="type (ex. brakes)" value={type} onChange={(e) => setType(e.target.value)} />
      <datalist id="task-service-types">
        {SERVICE_TYPES.map((t) => (
          <option key={t} value={t}>
            {serviceLabel(t)}
          </option>
        ))}
      </datalist>
      <input className="add-input add-input-grow" placeholder="libellé (optionnel)" value={label} onChange={(e) => setLabel(e.target.value)} />
      <input className="add-input add-input-sm" inputMode="numeric" placeholder="à … km" value={target} onChange={(e) => setTarget(e.target.value)} />
      <button type="submit" className="add-submit" disabled={!type.trim()}>
        Ajouter
      </button>
    </form>
  );
}

/** The inline editor a checklist row opens: the task definition (type/label/échéance) plus a
 *  line-by-line shopping-list editor backed by the parts subresource. */
function TaskEditForm({
  task,
  onSubmit,
  onCancel,
  onAddPart,
  onEditPart,
  onRemovePart,
}: {
  task: PlannedTask;
  onSubmit: (fields: TaskEdit) => void;
  onCancel: () => void;
  onAddPart: (part: TaskPartInput) => void;
  onEditPart: (partId: string, fields: TaskPartInput) => void;
  onRemovePart: (partId: string) => void;
}) {
  const listId = useId();
  const unit = task.target?.unit ?? 'km';
  const [type, setType] = useState(task.type);
  const [label, setLabel] = useState(task.label ?? '');
  const [usage, setUsage] = useState(task.target ? String(task.target.value) : '');
  const [dueDate, setDueDate] = useState(task.dueDate ?? '');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!type.trim()) return;
    const km = Number.parseInt(usage.replace(/[^\d]/g, ''), 10);
    const target = Number.isNaN(km) ? null : { value: km, unit };
    onSubmit({
      type: type.trim(),
      label: label.trim() || null,
      target,
      // A usage target wins over a date (milestoneOf prefers it); clear the date when one is set.
      dueDate: target ? null : dueDate || null,
    });
  };

  return (
    <div className="task-edit-form">
      <form className="add-form" onSubmit={submit}>
        <input list={listId} className="add-input" placeholder="type" value={type} onChange={(e) => setType(e.target.value)} />
        <datalist id={listId}>
          {SERVICE_TYPES.map((t) => (
            <option key={t} value={t}>
              {serviceLabel(t)}
            </option>
          ))}
        </datalist>
        <input className="add-input add-input-grow" placeholder="libellé (optionnel)" value={label} onChange={(e) => setLabel(e.target.value)} />
        <input className="add-input add-input-sm" inputMode="numeric" placeholder={`à … ${unit}`} value={usage} onChange={(e) => setUsage(e.target.value)} />
        <input className="add-input add-input-sm" type="date" aria-label="échéance" value={dueDate} disabled={usage.trim() !== ''} onChange={(e) => setDueDate(e.target.value)} />
        <button type="submit" className="add-submit" disabled={!type.trim()}>
          Enregistrer
        </button>
        <button type="button" className="task-clean" onClick={onCancel}>
          Annuler
        </button>
      </form>
      <div className="task-parts-edit">
        {task.parts.map((p, i) =>
          p.id ? (
            <PartEditRow key={p.id} part={p} onSave={(fields) => onEditPart(p.id as string, fields)} onRemove={() => onRemovePart(p.id as string)} />
          ) : (
            // A derived fluid line has no id — read-only here (it is projected, not stored).
            <div key={`derived-${i}`} className="part-edit-row part-edit-row--derived">
              <span className="part-label">{p.label ?? 'Fluide'}</span>
              {p.spec ? <PartSpec spec={p.spec} /> : null}
            </div>
          ),
        )}
        <AddPartRow onAdd={onAddPart} />
      </div>
    </div>
  );
}

/** One editable shopping-list line: commits on blur (only when a field actually changed). */
function PartEditRow({ part, onSave, onRemove }: { part: PlannedPart; onSave: (fields: TaskPartInput) => void; onRemove: () => void }) {
  const [ref, setRef] = useState(part.ref);
  const [brand, setBrand] = useState(part.brand ?? '');
  const [label, setLabel] = useState(part.label ?? '');
  const [qty, setQty] = useState(part.qty != null ? String(part.qty) : '');

  const commit = () => {
    const next: TaskPartInput = {
      ref: ref.trim(),
      brand: brand.trim() || null,
      label: label.trim() || null,
      qty: qty.trim() ? Number.parseInt(qty, 10) : null,
    };
    const unchanged =
      next.ref === part.ref &&
      (next.brand ?? null) === (part.brand ?? null) &&
      (next.label ?? null) === (part.label ?? null) &&
      (next.qty ?? null) === (part.qty ?? null);
    if (unchanged) return;
    onSave(next);
  };

  return (
    <div className="part-edit-row">
      <input className="add-input add-input-sm" placeholder="réf." value={ref} onChange={(e) => setRef(e.target.value)} onBlur={commit} />
      <input className="add-input add-input-sm" placeholder="marque" value={brand} onChange={(e) => setBrand(e.target.value)} onBlur={commit} />
      <input className="add-input add-input-grow" placeholder="libellé" value={label} onChange={(e) => setLabel(e.target.value)} onBlur={commit} />
      <input className="add-input add-input-xs" inputMode="numeric" placeholder="qté" value={qty} onChange={(e) => setQty(e.target.value)} onBlur={commit} />
      <button type="button" className="rf-remove" aria-label="Retirer cette pièce" title="Retirer" onClick={onRemove}>
        ×
      </button>
    </div>
  );
}

/** The blank add-a-part row at the foot of the parts editor. */
function AddPartRow({ onAdd }: { onAdd: (part: TaskPartInput) => void }) {
  const [ref, setRef] = useState('');
  const [brand, setBrand] = useState('');
  const [label, setLabel] = useState('');
  const [qty, setQty] = useState('');

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!ref.trim() && !label.trim()) return;
    onAdd({
      ref: ref.trim(),
      brand: brand.trim() || null,
      label: label.trim() || null,
      qty: qty.trim() ? Number.parseInt(qty, 10) : null,
    });
    setRef('');
    setBrand('');
    setLabel('');
    setQty('');
  };

  return (
    <form className="part-edit-row part-edit-row--add" onSubmit={add}>
      <input className="add-input add-input-sm" placeholder="réf." value={ref} onChange={(e) => setRef(e.target.value)} />
      <input className="add-input add-input-sm" placeholder="marque" value={brand} onChange={(e) => setBrand(e.target.value)} />
      <input className="add-input add-input-grow" placeholder="libellé" value={label} onChange={(e) => setLabel(e.target.value)} />
      <input className="add-input add-input-xs" inputMode="numeric" placeholder="qté" value={qty} onChange={(e) => setQty(e.target.value)} />
      <button type="submit" className="add-submit" disabled={!ref.trim() && !label.trim()}>
        + Pièce
      </button>
    </form>
  );
}

function AddFactForm({ onSubmit }: { onSubmit: (key: string, value: string) => void }) {
  const [key, setKey] = useState('');
  const [value, setValue] = useState('');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!key.trim() || !value.trim()) return;
    // Normalise the key to the snake_case the projection stores by.
    onSubmit(key.trim().toLowerCase().replace(/\s+/g, '_'), value.trim());
    setKey('');
    setValue('');
  };

  return (
    <form className="add-form" onSubmit={submit}>
      <input className="add-input" placeholder="donnée (ex. oil_capacity)" value={key} onChange={(e) => setKey(e.target.value)} />
      <input className="add-input add-input-grow" placeholder="valeur (ex. 4.3 L)" value={value} onChange={(e) => setValue(e.target.value)} />
      <button type="submit" className="add-submit" disabled={!key.trim() || !value.trim()}>
        Ajouter
      </button>
    </form>
  );
}

/** The derived fluid line: which oil (viscosity · standard) and what quantity (capacity), projected from the vehicle facts. */
function PartSpec({ spec }: { spec: FluidSpec }) {
  const grade = [spec.viscosity, spec.standard?.split('|').join(' / ')].filter(Boolean).join(' · ');
  const qty = spec.capacity ? `${spec.capacity.replace('.', ',')}${spec.unit ? ` ${spec.unit}` : ''}` : null;
  return (
    <>
      {grade ? <span className="part-spec"> · {grade}</span> : null}
      {qty ? <span className="part-qty"> — {qty}</span> : null}
      {!spec.verified ? (
        <span className="part-spec-unverified" title="Spécification non vérifiée (hors catalogue)">
          {' '}≈
        </span>
      ) : null}
    </>
  );
}

function TaskRow({
  task,
  onToggle,
  onDismiss,
  onRestore,
  onEdit,
  onAddPart,
  onEditPart,
  onRemovePart,
}: {
  task: PlannedTask;
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
  const rowClass = dismissed ? 'task-row task-row--dismissed' : done ? 'task-row task-row--done' : 'task-row';

  return (
    <div className={rowClass}>
      <button
        type="button"
        className="task-check"
        aria-label={dismissed ? 'Restaurer la tâche' : done ? 'Rouvrir la tâche' : 'Marquer comme fait'}
        aria-pressed={done}
        onClick={dismissed ? onRestore : onToggle}
      >
        {dismissed ? '↺' : done ? '✓' : '○'}
      </button>
      <div className="task-body">
        <div className="task-head">
          <span className="task-title">{taskTitle(task)}</span>
          {!dismissed ? (
            <button
              type="button"
              className="task-edit-toggle"
              aria-label="Modifier la tâche"
              aria-expanded={editing}
              title="Modifier"
              onClick={() => setEditing((e) => !e)}
            >
              ✎
            </button>
          ) : null}
        </div>
        {editing ? (
          <TaskEditForm
            task={task}
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
          <ul className="task-parts">
            {task.parts.map((p, i) => (
              <li key={p.id ?? `${p.ref}-${i}`}>
                {p.ref ? <span className="part-ref">{p.ref}</span> : null}
                {p.brand ? <span className="part-brand">{p.ref ? ' · ' : ''}{p.brand}</span> : null}
                {p.label ? <span className="part-label">{p.ref || p.brand ? ' — ' : ''}{p.label}</span> : null}
                {p.spec ? (
                  <PartSpec spec={p.spec} />
                ) : p.qty ? (
                  <span className="part-qty"> ×{p.qty}</span>
                ) : null}
              </li>
            ))}
          </ul>
        ) : null}
      </div>
      {dismissed ? (
        <button type="button" className="task-dismiss" aria-label="Restaurer la tâche" title="Restaurer" onClick={onRestore}>
          ↺
        </button>
      ) : (
        <button type="button" className="task-dismiss" aria-label="Retirer la tâche" title="Retirer" onClick={onDismiss}>
          ×
        </button>
      )}
    </div>
  );
}
