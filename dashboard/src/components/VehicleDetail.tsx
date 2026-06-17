import { Badge } from '../design/components/Badge';
import { Card } from '../design/components/Card';
import { HealthRing } from '../design/components/HealthRing';
import { MetricStat } from '../design/components/MetricStat';
import { StatusPill } from '../design/components/StatusPill';
import type { Vehicle } from '../types';

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

export function VehicleDetail({ vehicle }: { vehicle: Vehicle }) {
  const tone = healthTone(vehicle.health);
  const next = vehicle.nextService;
  const nextTone = next.value < 0 ? 'alarm' : next.value < 1500 ? 'warn' : 'accent';
  const nextValue = next.value < 0 ? `${Math.abs(next.value)}` : `${next.value}`;

  return (
    <>
      <div className="breadcrumb">Atelier / Véhicules</div>
      <div className="title-row">
        <h1>{vehicle.name}</h1>
        {vehicle.year ? <Badge>{vehicle.year}</Badge> : null}
        {vehicle.kType ? <Badge>KType {vehicle.kType}</Badge> : null}
        {vehicle.verified ? (
          <Badge variant="verified" check>
            TecDoc vérifié
          </Badge>
        ) : null}
      </div>

      <Card className="kpi-card" style={{ marginBottom: 'var(--mpa-gap-grid)' }} padding="var(--mpa-pad-card-lg) 0">
        <div className="kpi-strip">
          <MetricStat label="Kilométrage" value={vehicle.mileage} unit={vehicle.mileageUnit} />
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
    </>
  );
}
