import { Badge } from '../design/components/Badge';
import { Card } from '../design/components/Card';
import { HealthRing } from '../design/components/HealthRing';
import { MetricStat } from '../design/components/MetricStat';
import { StatusPill } from '../design/components/StatusPill';
import type { PlannedTask, Vehicle, VehicleFact } from '../types';
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

function statusPill(v: Vehicle) {
  if (v.status === 'overdue') return <StatusPill status="overdue">{v.healthLabel}</StatusPill>;
  if (v.status === 'soon') return <StatusPill status="soon">{v.healthLabel}</StatusPill>;
  return <StatusPill status="ok">{v.healthLabel}</StatusPill>;
}

// Static spark heights — the real cost series is step-4 data.
const SPARK = [38, 22, 54, 30, 70, 44, 88, 52, 66, 40, 96, 60];

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
  battery: 'Batterie',
  inspection: 'Contrôle technique',
  repair: 'Réparation',
  other: 'Autre',
};

function taskTitle(task: PlannedTask): string {
  const base = TASK_LABELS[task.type] ?? task.type;
  return task.label ? `${base} — ${task.label}` : base;
}

const FACT_LABELS: Record<string, string> = {
  oil_type: 'Type d’huile',
  oil_capacity: 'Capacité d’huile',
  coolant: 'Liquide de refroidissement',
  brake_fluid: 'Liquide de frein',
  spark_plug_ref: 'Référence bougie',
  oil_filter_ref: 'Référence filtre à huile',
};

function factLabel(key: string): string {
  return FACT_LABELS[key] ?? key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

export function VehicleDetail({
  vehicle,
  onEdit,
  onCompleteTask,
}: {
  vehicle: Vehicle;
  onEdit: (edit: VehicleEdit) => void;
  onCompleteTask: (taskIri: string) => void;
}) {
  const tone = healthTone(vehicle.health);
  const next = vehicle.nextService;
  const nextTone = next.value < 0 ? 'alarm' : next.value < 1500 ? 'warn' : 'accent';
  const nextValue = next.value < 0 ? `${Math.abs(next.value)}` : `${next.value}`;
  // Road vehicles read in km; ground-care machinery accrues engine hours.
  const defaultUnit = vehicle.usageUnit ?? (vehicle.vehicleType === 'groundscare' ? 'h' : 'km');
  const facts = vehicle.facts ?? [];
  const tasks = vehicle.plannedTasks ?? [];

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

      <div className="two-col">
        <Card>
          <div className="panel-title">
            <span className="t">État du véhicule</span>
            {statusPill(vehicle)}
          </div>
          <div className="health-wrap">
            <HealthRing value={vehicle.health} tone={tone} />
          </div>
        </Card>

        <Card>
          <div className="cost-head">
            <div className="panel-title" style={{ marginBottom: 0 }}>
              <span className="t">Coût d'entretien</span>
            </div>
            <div className="cost-total">
              <div className="big">{vehicle.cost.total}</div>
              <div className="delta">{vehicle.cost.delta}</div>
            </div>
          </div>
          <div style={{ color: 'var(--mpa-muted)', fontSize: 'var(--mpa-text-small)' }}>
            {vehicle.cost.period}
          </div>
          <div className="spark">
            {SPARK.map((h, i) => (
              <div key={i} className="bar" style={{ height: `${h}%` }} />
            ))}
          </div>
        </Card>
      </div>

      <Card style={{ marginTop: 'var(--mpa-gap-grid)' }}>
        <div className="panel-title">
          <span className="t">Tâches &amp; liste de courses</span>
          <span style={{ color: 'var(--mpa-muted)', fontSize: 'var(--mpa-text-label)' }}>
            {tasks.length} ouverte{tasks.length > 1 ? 's' : ''}
          </span>
        </div>
        {tasks.length === 0 ? (
          <div style={{ color: 'var(--mpa-faint)', fontSize: 'var(--mpa-text-small)' }}>
            Aucune tâche planifiée. Demandez à l'assistant « quel entretien à X km ? ».
          </div>
        ) : (
          <div className="tasks">
            {tasks.map((t) => (
              <TaskRow key={t.id} task={t} onComplete={() => onCompleteTask(t.id)} />
            ))}
          </div>
        )}
      </Card>

      <Card style={{ marginTop: 'var(--mpa-gap-grid)' }}>
        <div className="panel-title">
          <span className="t">Faits</span>
          <span style={{ color: 'var(--mpa-muted)', fontSize: 'var(--mpa-text-label)' }}>
            {facts.length} enregistré{facts.length > 1 ? 's' : ''}
          </span>
        </div>
        {facts.length === 0 ? (
          <div style={{ color: 'var(--mpa-faint)', fontSize: 'var(--mpa-text-small)' }}>Aucun fait enregistré.</div>
        ) : (
          <div className="facts">
            {facts.map((f) => (
              <FactRow key={f.id} fact={f} />
            ))}
          </div>
        )}
      </Card>
    </>
  );
}

function TaskRow({ task, onComplete }: { task: PlannedTask; onComplete: () => void }) {
  const target = task.target
    ? `à ${task.target.value.toLocaleString('fr-FR')}${task.target.unit ? ` ${task.target.unit}` : ''}`
    : task.dueDate
      ? `avant le ${task.dueDate}`
      : null;

  return (
    <div className="task-row">
      <button type="button" className="task-check" aria-label="Marquer comme fait" onClick={onComplete}>
        ○
      </button>
      <div className="task-body">
        <div className="task-head">
          <span className="task-title">{taskTitle(task)}</span>
          {target ? <span className="task-target">{target}</span> : null}
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
    </div>
  );
}

function FactRow({ fact }: { fact: VehicleFact }) {
  return (
    <div className="fact-row">
      <span className="fact-key">{factLabel(fact.key)}</span>
      <span className="fact-value">
        {fact.value}
        {fact.unit ? <span className="fact-unit"> {fact.unit}</span> : null}
      </span>
      {fact.verified ? (
        <Badge variant="verified" check>
          {fact.source}
        </Badge>
      ) : (
        <Badge>{fact.source}</Badge>
      )}
    </div>
  );
}
