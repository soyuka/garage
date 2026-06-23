import { useState } from 'react';
import { StatusPill } from '../design/components/StatusPill';
import { formatDue } from '../forecast';
import type { MaintenanceRecordInput } from '../garage';
import { healthView } from '../health';
import { serviceLabel } from '../labels';
import type { ForecastDue, MaintenanceHealth, VehicleForecast } from '../types';

/** The vehicle's current reading — seeds the inline gap-log so "fait à … h" defaults sensibly. */
export interface CurrentUsage {
  value: number | null;
  unit: string;
}

/** Health-segment colour per status — mirrors the StatusPill mapping (ok→green … info→cyan). */
const HEALTH_COLOR: Record<NonNullable<ReturnType<typeof healthView>>['status'], string> = {
  ok: 'var(--mpa-ok)',
  soon: 'var(--mpa-warn)',
  overdue: 'var(--mpa-alarm)',
  info: 'var(--mpa-accent)',
};

const HEALTH_SEGMENTS = 14;

/**
 * Maintenance forecast — a split card: the health rollup on the left (score + segment gauge + verdict),
 * the predicted-due services on the right as compact bars (soonest-first). Below, an "À renseigner"
 * sub-section lists recurring services with a known cadence but no logged baseline; each gap carries an
 * inline quick-log (date + reading → one minimal record) so a baseline can be set without leaving the
 * panel, with a "détails…" escape hatch into the full past-maintenance form.
 */
export function ForecastSection({
  forecast,
  health,
  loading = false,
  currentUsage,
  onQuickLogGap,
  onOpenFullForm,
}: {
  forecast: VehicleForecast | undefined;
  health: MaintenanceHealth | undefined;
  loading?: boolean;
  currentUsage: CurrentUsage;
  /** Log a minimal baseline for a gap type (one item, date + reading) — no jump, no form. */
  onQuickLogGap: (input: MaintenanceRecordInput) => void;
  /** Escape hatch: open the full past-maintenance form prefilled with this gap's service type. */
  onOpenFullForm: (type: string) => void;
}) {
  const due = forecast?.due ?? [];
  const gaps = forecast?.gaps ?? [];

  return (
    <div className="forecast">
      <div className="forecast-split">
        <HealthColumn health={health} />
        <div className="forecast-vrule" aria-hidden="true" />
        <div className="forecast-due">
          {loading ? (
            <div className="forecast-bars">
              {[0, 1, 2].map((i) => (
                <div key={i} className="forecast-row" aria-hidden="true">
                  <span className="skel" style={{ width: '60%', height: 13 }} />
                  <span className="skel" style={{ width: '100%', height: 5, marginTop: 8 }} />
                </div>
              ))}
            </div>
          ) : due.length === 0 ? (
            <div className="forecast-empty">Aucune échéance prévisible pour le moment.</div>
          ) : (
            <div className="forecast-bars">
              {due.map((d, i) => (
                <DueRow key={`${d.type}-${i}`} due={d} />
              ))}
            </div>
          )}
        </div>
      </div>

      {gaps.length > 0 ? (
        <div className="forecast-gaps">
          <h3 className="forecast-gaps-title">À renseigner — {gaps.length} service{gaps.length > 1 ? 's' : ''} sans date de référence</h3>
          <p className="forecast-gaps-why">
            La fréquence est connue, mais aucune intervention passée n’est consignée : impossible de prévoir la prochaine
            échéance. Indiquez quand (et à combien {currentUsage.unit === 'km' || currentUsage.unit === 'mi' ? 'de km' : "d'heures"}) chacun a été fait pour la dernière fois.
          </p>
          <div className="forecast-gaps-list">
            {gaps.map((type) => (
              <GapRow
                key={type}
                type={type}
                currentUsage={currentUsage}
                onLog={onQuickLogGap}
                onFull={() => onOpenFullForm(type)}
              />
            ))}
          </div>
        </div>
      ) : null}
    </div>
  );
}

/** One coverage gap: a header (service + cadence hint) over an inline quick-log row. */
function GapRow({
  type,
  currentUsage,
  onLog,
  onFull,
}: {
  type: string;
  currentUsage: CurrentUsage;
  onLog: (input: MaintenanceRecordInput) => void;
  onFull: () => void;
}) {
  const today = new Date().toISOString().slice(0, 10);
  const [date, setDate] = useState(today);
  const [reading, setReading] = useState(currentUsage.value != null ? String(currentUsage.value) : '');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const usageValue = Number.parseInt(reading.replace(/[^\d]/g, ''), 10);
    onLog({
      items: [{ type, parts: [] }],
      performedAt: date,
      usageValue: Number.isNaN(usageValue) ? null : usageValue,
      usageUnit: Number.isNaN(usageValue) ? null : currentUsage.unit,
    });
  };

  return (
    <div className="forecast-gap">
      <div className="forecast-gap-top">
        <span className="forecast-gap-name">{serviceLabel(type)}</span>
        <span className="forecast-gap-note">cadence connue, jamais consigné</span>
      </div>
      <form className="forecast-gap-log" onSubmit={submit}>
        <span className="forecast-gap-lbl">fait le</span>
        <input className="add-input add-input-sm" type="date" value={date} onChange={(e) => setDate(e.target.value)} aria-label="Date de la dernière intervention" />
        <span className="forecast-gap-lbl">à</span>
        <input
          className="add-input add-input-xs"
          inputMode="numeric"
          value={reading}
          onChange={(e) => setReading(e.target.value)}
          aria-label="Relevé à cette date"
        />
        <span className="forecast-gap-lbl">{currentUsage.unit}</span>
        <button type="submit" className="add-submit">Consigner</button>
        <button type="button" className="forecast-gap-full" onClick={onFull}>détails (pièces, coût)…</button>
      </form>
    </div>
  );
}

/** The left column: the maintenance-health rollup as a score + skewed segment gauge + verdict line.
 *  Renders a neutral "—" placeholder when nothing is tracked yet (status unknown → no score). */
function HealthColumn({ health }: { health: MaintenanceHealth | undefined }) {
  const v = healthView(health);
  const color = v ? HEALTH_COLOR[v.status] : 'var(--mpa-faint)';
  const score = v?.score ?? null;
  // At least one lit segment whenever there IS a score, so a low score still reads as "alive".
  const lit = score == null ? 0 : Math.max(1, Math.round((score / 100) * HEALTH_SEGMENTS));

  return (
    <div
      className="forecast-health"
      role="progressbar"
      aria-valuenow={score ?? undefined}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-label={`Santé d'entretien${v ? ` : ${v.verdict}` : ''}`}
    >
      <div className="forecast-health-top">
        <span className="forecast-health-score" style={{ color }}>{score ?? '—'}</span>
        <span className="forecast-health-cap">santé</span>
      </div>
      <span className="forecast-health-track" style={{ ['--seg' as string]: color }} aria-hidden="true">
        {Array.from({ length: HEALTH_SEGMENTS }, (_, i) => (
          <span key={i} className={`forecast-health-seg${i < lit ? ' on' : ''}`} />
        ))}
      </span>
      {v ? <span className="forecast-health-verdict">{v.verdict}</span> : null}
    </div>
  );
}

/** One forecast row — usage-axis rows lead with a consumed/cadence readout + a progress bar against the
 *  cadence; overdue rows swap the readout for a red "en retard" pill; time-axis rows show a firm or
 *  estimated date instead of a bar. */
function DueRow({ due }: { due: ForecastDue }) {
  const v = formatDue(due);

  if (v.axis === 'time') {
    return (
      <div className="forecast-row forecast-row--time">
        <div className="forecast-row-line">
          <span className="forecast-row-name">{v.title}</span>
          <span className={`forecast-date${v.estimate ? ' forecast-date--est' : ''}`}>
            <span className="forecast-date-dot" />
            {v.lead}
          </span>
        </div>
      </div>
    );
  }

  const overdue = v.status === 'overdue';
  return (
    <div className="forecast-row">
      <div className="forecast-row-line">
        <span className="forecast-row-name">{v.title}</span>
        {overdue ? (
          <StatusPill status="overdue">{v.lead}</StatusPill>
        ) : v.bar ? (
          <span className="forecast-readout">
            <b>{v.bar.consumed}</b> / {v.bar.cadence} {v.bar.unit} · reste {v.bar.remaining} {v.bar.unit}
          </span>
        ) : null}
      </div>
      {v.progress != null ? (
        <div className="forecast-track" role="progressbar" aria-valuenow={v.progress} aria-valuemin={0} aria-valuemax={100}>
          <span className={`forecast-fill forecast-fill--${v.status}`} style={{ width: `${v.progress}%` }} />
        </div>
      ) : null}
    </div>
  );
}
