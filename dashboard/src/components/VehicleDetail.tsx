import { useEffect, useRef, useState } from 'react';
import { Badge } from '../design/components/Badge';
import { Card } from '../design/components/Card';
import { MetricStat } from '../design/components/MetricStat';
import type { MaintenanceRecordInput, PlannedTaskInput } from '../garage';
import { serviceLabel, SERVICE_TYPES } from '../labels';
import type { FactPanel, PlannedTask, Vehicle } from '../types';
import { FactRow, factLabel } from './facts';
import { InlineEdit } from './InlineEdit';
import { PastMaintenanceRail } from './PastMaintenanceRail';
import { TaskListSkeleton } from './Skeletons';

/** The card's editable scalars — the subset the PATCH endpoint accepts. */
export interface VehicleEdit {
  make?: string;
  model?: string;
  year?: number | null;
  modification?: string | null;
  engineCode?: string | null;
  kType?: number | null;
  plate?: string | null;
  usageValue?: number;
  usageUnit?: string;
}

function healthTone(v: number): 'ok' | 'warn' | 'alarm' {
  if (v >= 80) return 'ok';
  if (v >= 60) return 'warn';
  return 'alarm';
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
}: {
  vehicle: Vehicle;
  onEdit: (edit: VehicleEdit) => void;
  onSetTaskStatus: (taskIri: string, status: 'open' | 'done' | 'dismissed') => void;
  onArchiveDone: () => void;
  onAddTask: (input: PlannedTaskInput) => void;
  tasksLoading: boolean;
  showAllTasks: boolean;
  onToggleShowAllTasks: () => void;
  // The agent's focused fact answer, drawn into the Données panel instead of a separate card.
  factPanel: FactPanel | null;
  onExpandFacts: () => void;
  onDismissFacts: () => void;
  onFillFact: (key: string, value: string) => void;
  onDeleteFact: (key: string) => void;
  recordsLoading: boolean;
  onAddRecord: (input: MaintenanceRecordInput) => void;
  onEditRecord: (recordIri: string, input: MaintenanceRecordInput) => void;
  onDeleteRecord: (recordIri: string) => void;
  onUploadAttachment: (recordIri: string, file: File) => void;
  onDeleteAttachment: (attachmentUrl: string) => void;
}) {
  const tone = healthTone(vehicle.health);
  const next = vehicle.nextService;
  const nextTone = next.value < 0 ? 'alarm' : next.value < 1500 ? 'warn' : 'accent';
  const nextValue = next.value < 0 ? `${Math.abs(next.value)}` : `${next.value}`;
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
      <div className="title-row">
        <h1>
          <InlineEdit value={vehicle.make} placeholder="marque" ariaLabel="Marque" onCommit={(v) => onEdit({ make: v })} />{' '}
          <InlineEdit value={vehicle.model} placeholder="modèle" ariaLabel="Modèle" onCommit={(v) => onEdit({ model: v })} />
        </h1>
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
            value={vehicle.modification ?? ''}
            placeholder="+ finition"
            ariaLabel="Finition / motorisation"
            className="badge-edit"
            onCommit={(v) => onEdit({ modification: v || null })}
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

      <Card className="kpi-card hud-panel" style={{ marginBottom: 'var(--mpa-gap-grid)' }} padding="var(--mpa-pad-card-lg) 0">
        <div className="kpi-strip">
          <div style={{ fontFamily: 'var(--mpa-font)', fontVariantNumeric: 'tabular-nums' }}>
            <div style={{ fontSize: 13, color: 'var(--mpa-muted)', marginBottom: 12 }}>Kilométrage</div>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 7 }}>
              <span style={{ fontSize: 44, fontWeight: 500, letterSpacing: '-0.02em', lineHeight: 1 }}>
                <InlineEdit
                  value={vehicle.usageValue != null ? vehicle.mileage : ''}
                  placeholder="—"
                  type="text"
                  ariaLabel="Relevé de kilométrage"
                  className="usage-edit"
                  onCommit={commitUsage}
                />
              </span>
              {vehicle.usageValue != null ? (
                <select
                  aria-label="Unité de relevé"
                  className="usage-unit-edit"
                  value={vehicle.usageUnit ?? defaultUnit}
                  onChange={(e) => onEdit({ usageUnit: e.target.value })}
                >
                  <option value="km">km</option>
                  <option value="mi">mi</option>
                  <option value="h">h</option>
                </select>
              ) : null}
            </div>
          </div>
          <MetricStat
            label="Prochain entretien"
            value={nextValue}
            unit={next.value < 0 ? `${next.unit} de retard` : next.unit}
            tone={nextTone}
          />
          <MetricStat label="Indice de santé" value={String(vehicle.health)} unit="/100" tone={tone} />
          <MetricStat label="Tâches ouvertes" value={String(vehicle.openTasks)} unit="en attente" />
        </div>
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
            <button type="button" className="task-clean" aria-pressed={showAllTasks} onClick={onToggleShowAllTasks}>
              {showAllTasks ? `Masquer les retirées${dismissedCount > 0 ? ` (${dismissedCount})` : ''}` : 'Voir les tâches retirées'}
            </button>
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
                    />
                  ))}
                </div>
              </section>
            ))}
          </div>
        )}
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
                  <FactRow key={f.key} fact={f} onDelete={focused ? undefined : onDeleteFact} />
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

function TaskRow({
  task,
  onToggle,
  onDismiss,
  onRestore,
}: {
  task: PlannedTask;
  onToggle: () => void;
  onDismiss: () => void;
  onRestore: () => void;
}) {
  const done = task.status === 'done';
  const dismissed = task.status === 'dismissed';
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
        </div>
        {task.parts.length > 0 ? (
          <ul className="task-parts">
            {task.parts.map((p, i) => (
              <li key={`${p.ref}-${i}`}>
                <span className="part-ref">{p.ref}</span>
                {p.brand ? <span className="part-brand"> · {p.brand}</span> : null}
                {p.label ? <span className="part-label"> — {p.label}</span> : null}
                {p.qty ? <span className="part-qty"> ×{p.qty}</span> : null}
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
