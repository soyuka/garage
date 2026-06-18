import { useState } from 'react';
import { Badge } from '../design/components/Badge';
import { Card } from '../design/components/Card';
import { MetricStat } from '../design/components/MetricStat';
import type { PlannedTask, Vehicle } from '../types';
import { FactRow } from './facts';
import { InlineEdit } from './InlineEdit';

/** The card's editable scalars — the subset the PATCH endpoint accepts. */
export interface VehicleEdit {
  usageValue?: number;
  usageUnit?: string;
  plate?: string | null;
}

function healthTone(v: number): 'ok' | 'warn' | 'alarm' {
  if (v >= 80) return 'ok';
  if (v >= 60) return 'warn';
  return 'alarm';
}

const TASK_LABELS: Record<string, string> = {
  oil_change: 'Vidange',
  oil_filter: 'Filtre à huile',
  air_filter: 'Filtre à air',
  fuel_filter: 'Filtre à carburant',
  cabin_filter: 'Filtre d’habitacle',
  spark_plug: 'Bougies',
  tires: 'Pneus',
  brakes: 'Freins',
  coolant: 'Liquide de refroidissement',
  brake_fluid: 'Liquide de frein',
  timing_belt: 'Courroie de distribution',
  gearbox_oil: 'Huile de boîte',
  battery: 'Batterie',
  inspection: 'Contrôle technique',
  repair: 'Réparation',
  other: 'Autre',
};

function taskTitle(task: PlannedTask): string {
  const base = TASK_LABELS[task.type] ?? task.type;
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
  onClearDone,
}: {
  vehicle: Vehicle;
  onEdit: (edit: VehicleEdit) => void;
  onSetTaskStatus: (taskIri: string, status: 'open' | 'done' | 'dismissed') => void;
  onClearDone: (taskIris: string[]) => void;
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
  const milestones = groupByMilestone(tasks);
  // The full data list is on-demand reference; the agent's show_fact panel is the answer surface.
  const [dataOpen, setDataOpen] = useState(false);

  const commitUsage = (raw: string) => {
    const value = Number.parseInt(raw.replace(/[^\d]/g, ''), 10);
    if (Number.isNaN(value)) return;
    onEdit({ usageValue: value, usageUnit: defaultUnit });
  };

  return (
    <>
      <div className="breadcrumb">Atelier / Véhicules</div>
      <div className="title-row">
        <h1>{vehicle.name}</h1>
        {vehicle.year ? <Badge>{vehicle.year}</Badge> : null}
        <Badge style={{ padding: 0 }}>
          <InlineEdit
            value={vehicle.plate ?? ''}
            placeholder="+ plaque"
            ariaLabel="Plaque d'immatriculation"
            className="plate-edit"
            onCommit={(next) => onEdit({ plate: next || null })}
          />
        </Badge>
        {vehicle.kType ? <Badge>KType {vehicle.kType}</Badge> : null}
        {vehicle.verified ? (
          <Badge variant="verified" check>
            TecDoc vérifié
          </Badge>
        ) : null}
      </div>

      <Card className="kpi-card" style={{ marginBottom: 'var(--mpa-gap-grid)' }} padding="var(--mpa-pad-card-lg) 0">
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
              {vehicle.usageValue != null ? <span style={{ fontSize: 16, color: 'var(--mpa-muted)' }}>{vehicle.mileageUnit}</span> : null}
            </div>
          </div>
          <MetricStat
            label="Prochain entretien"
            value={nextValue}
            unit={next.value < 0 ? `${next.unit} de retard` : next.unit}
            tone={nextTone}
          />
          <MetricStat label="Indice de santé" value={vehicle.health} unit="/100" tone={tone} />
          <MetricStat label="Tâches ouvertes" value={vehicle.openTasks} unit="en attente" />
        </div>
      </Card>

      {/* État du véhicule + Coût d'entretien panels hidden for now — too noisy, freeing vertical space */}

      <Card style={{ marginTop: 'var(--mpa-gap-grid)' }}>
        <div className="panel-title">
          <span className="t">Tâches &amp; liste de courses</span>
          <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--mpa-space-3)' }}>
            {doneIris.length > 0 ? (
              <button type="button" className="task-clean" onClick={() => onClearDone(doneIris)}>
                Nettoyer les tâches faites ({doneIris.length})
              </button>
            ) : null}
            <span style={{ color: 'var(--mpa-muted)', fontSize: 'var(--mpa-text-label)' }}>
              {openCount} ouverte{openCount > 1 ? 's' : ''}
            </span>
          </span>
        </div>
        {tasks.length === 0 ? (
          <div style={{ color: 'var(--mpa-faint)', fontSize: 'var(--mpa-text-small)' }}>
            Aucune tâche planifiée. Demandez à l'assistant « quel entretien à X km ? ».
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
                    />
                  ))}
                </div>
              </section>
            ))}
          </div>
        )}
      </Card>

      <Card style={{ marginTop: 'var(--mpa-gap-grid)' }}>
        <button
          type="button"
          className="panel-title panel-disclosure"
          aria-expanded={dataOpen}
          onClick={() => setDataOpen((o) => !o)}
        >
          <span className="t">
            <span className="disclosure-caret">{dataOpen ? '▾' : '▸'}</span> Données
          </span>
          <span style={{ color: 'var(--mpa-muted)', fontSize: 'var(--mpa-text-label)' }}>
            {facts.length} enregistrée{facts.length > 1 ? 's' : ''}
          </span>
        </button>
        {dataOpen ? (
          facts.length === 0 ? (
            <div style={{ color: 'var(--mpa-faint)', fontSize: 'var(--mpa-text-small)' }}>Aucune donnée enregistrée.</div>
          ) : (
            <div className="facts">
              {facts.map((f) => (
                <FactRow key={f.key} fact={f} />
              ))}
            </div>
          )
        ) : null}
      </Card>
    </>
  );
}

function TaskRow({ task, onToggle, onDismiss }: { task: PlannedTask; onToggle: () => void; onDismiss: () => void }) {
  const done = task.status === 'done';

  return (
    <div className={done ? 'task-row task-row--done' : 'task-row'}>
      <button
        type="button"
        className="task-check"
        aria-label={done ? 'Rouvrir la tâche' : 'Marquer comme fait'}
        aria-pressed={done}
        onClick={onToggle}
      >
        {done ? '✓' : '○'}
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
      <button type="button" className="task-dismiss" aria-label="Retirer la tâche" title="Retirer" onClick={onDismiss}>
        ×
      </button>
    </div>
  );
}

