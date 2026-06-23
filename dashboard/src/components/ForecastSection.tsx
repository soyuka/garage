import { useState } from 'react';
import { StatusPill } from '../design/components/StatusPill';
import { formatDue } from '../forecast';
import type { MaintenanceRecordInput } from '../garage';
import { healthView } from '../health';
import { serviceLabel } from '../labels';
import { formatCadenceShort } from '../schedule';
import type { ForecastDue, MaintenanceHealth, ServiceRule, VehicleForecast } from '../types';

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

/** Relative-date shortcuts for the "date inconnue ?" row — set an approximate last-done date with one
 *  tap instead of opening the date picker. Each offset is months back from today. */
const APPROX_DATES: { months: number; label: string }[] = [
  { months: 1, label: 'récemment' },
  { months: 6, label: '~6 mois' },
  { months: 12, label: '~1 an' },
  { months: 24, label: '~2 ans' },
];

/** Known per-visit service bundles — services a shop does in one go, so one baseline entry covers the
 *  set. Only these collapse; everything else stays on its own card. Order within a bundle is preserved. */
const VISIT_BUNDLES: readonly (readonly string[])[] = [
  ['oil_change', 'oil_filter'],
];

/** A bundle of gaps logged together as one visit (one record, an item per type). */
interface GapGroup {
  key: string;
  types: string[];
  cadence: string; // "tous les 10 000 km / 1 an", or '' when no rule was found
}

/** Collapse gap types into per-visit cards using the VISIT_BUNDLES whitelist: a bundle merges only when
 *  two or more of its members are gaps; anything else stays solo. First-appearance order is kept. */
function groupGaps(gaps: string[], rules: ServiceRule[]): GapGroup[] {
  const ruleFor = (type: string) => rules.find((r) => r.type === type);
  const gapSet = new Set(gaps);
  const consumed = new Set<string>();
  const groups: GapGroup[] = [];

  for (const type of gaps) {
    if (consumed.has(type)) continue;
    const bundle = VISIT_BUNDLES.find((b) => b.includes(type));
    const types = bundle ? bundle.filter((t) => gapSet.has(t)) : [type];
    types.forEach((t) => consumed.add(t));
    // Cadence comes from the first bundled member that actually has a rule.
    const rule = types.map(ruleFor).find((r): r is ServiceRule => r != null);
    groups.push({ key: types.join('+'), types, cadence: rule ? formatCadenceShort(rule) : '' });
  }
  return groups;
}

/**
 * Maintenance forecast — a split card: the health rollup on the left (score + segment gauge + verdict),
 * the predicted-due services on the right as compact bars (soonest-first). Below, an "À renseigner"
 * sub-section bundles recurring services that have a cadence but no logged baseline into per-visit cards;
 * each card shows the cadence and carries a baseline form (date + reading → one record covering the whole
 * bundle), with relative-date shortcuts for when the exact date is unknown.
 */
export function ForecastSection({
  forecast,
  health,
  loading = false,
  currentUsage,
  rules = [],
  onQuickLogGap,
}: {
  forecast: VehicleForecast | undefined;
  health: MaintenanceHealth | undefined;
  loading?: boolean;
  currentUsage: CurrentUsage;
  /** The vehicle's recurring rules — supplies each gap's cadence and drives per-visit bundling. */
  rules?: ServiceRule[];
  /** Log a baseline for a gap bundle (one record, an item per bundled type) — no jump, no form. */
  onQuickLogGap: (input: MaintenanceRecordInput) => void;
}) {
  const due = forecast?.due ?? [];
  const gaps = forecast?.gaps ?? [];
  const groups = groupGaps(gaps, rules);

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
          <div className="forecast-gaps-head">
            <h3 className="forecast-gaps-title">À renseigner</h3>
            <span className="forecast-gaps-count">{gaps.length} service{gaps.length > 1 ? 's' : ''}</span>
          </div>
          <p className="forecast-gaps-why">Indiquez la dernière intervention pour activer les prévisions.</p>
          <div className="forecast-gaps-list">
            {groups.map((group) => (
              <GapCard key={group.key} group={group} currentUsage={currentUsage} onLog={onQuickLogGap} />
            ))}
          </div>
        </div>
      ) : null}
    </div>
  );
}

/** One baseline card for a gap bundle: the service name(s) + cadence over a big date/reading form,
 *  plus relative-date chips ("date inconnue ?") that fill an approximate last-done date in one tap. */
function GapCard({
  group,
  currentUsage,
  onLog,
}: {
  group: GapGroup;
  currentUsage: CurrentUsage;
  onLog: (input: MaintenanceRecordInput) => void;
}) {
  const today = new Date().toISOString().slice(0, 10);
  const [date, setDate] = useState(today);
  const [reading, setReading] = useState(currentUsage.value != null ? String(currentUsage.value) : '');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const usageValue = Number.parseInt(reading.replace(/[^\d]/g, ''), 10);
    onLog({
      items: group.types.map((type) => ({ type, parts: [] })),
      performedAt: date,
      usageValue: Number.isNaN(usageValue) ? null : usageValue,
      usageUnit: Number.isNaN(usageValue) ? null : currentUsage.unit,
    });
  };

  const monthsAgo = (months: number): string => {
    const d = new Date();
    d.setMonth(d.getMonth() - months);
    return d.toISOString().slice(0, 10);
  };

  const name = group.types.map(serviceLabel).join(' + ');

  return (
    <div className="gap-card">
      <div className="gap-card-head">
        <span className="gap-card-name">{name}</span>
        {group.cadence ? <span className="gap-card-cadence">{group.cadence}</span> : null}
      </div>
      <form className="gap-card-form" onSubmit={submit}>
        <label className="gap-field">
          <span className="gap-field-lbl">Dernière fois</span>
          <input className="gap-input" type="date" max={today} value={date} onChange={(e) => setDate(e.target.value)} />
        </label>
        <label className="gap-field">
          <span className="gap-field-lbl">Relevé ({currentUsage.unit})</span>
          <input
            className="gap-input"
            inputMode="numeric"
            placeholder="—"
            value={reading}
            onChange={(e) => setReading(e.target.value)}
          />
        </label>
        <button type="submit" className="gap-submit">Consigner</button>
      </form>
      <div className="gap-approx">
        <span className="gap-approx-lbl">Date inconnue&nbsp;?</span>
        {APPROX_DATES.map((a) => (
          <button key={a.months} type="button" className="gap-chip" onClick={() => setDate(monthsAgo(a.months))}>
            {a.label}
          </button>
        ))}
      </div>
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
