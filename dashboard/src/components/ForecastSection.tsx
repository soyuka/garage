import { StatusPill } from '../design/components/StatusPill';
import { formatDue } from '../forecast';
import { serviceLabel } from '../labels';
import type { ForecastDue, VehicleForecast } from '../types';

/**
 * "Prévisions d'entretien" — one card per predicted-due service (soonest-first), plus an
 * "À renseigner" sub-section listing recurring services with a known cadence but no logged baseline.
 * Each gap is a CTA to log that type's baseline (it opens the past-maintenance form prefilled).
 */
export function ForecastSection({
  forecast,
  loading = false,
  onLogGap,
}: {
  forecast: VehicleForecast | undefined;
  loading?: boolean;
  /** Log a baseline for a gap type — opens the past-maintenance form prefilled with that service. */
  onLogGap: (type: string) => void;
}) {
  const due = forecast?.due ?? [];
  const gaps = forecast?.gaps ?? [];

  return (
    <div className="forecast">
      <div className="panel-title">
        <span className="t">Prévisions d'entretien</span>
        <span style={{ color: 'var(--mpa-muted)', fontSize: 'var(--mpa-text-label)' }}>
          {due.length} à venir
        </span>
      </div>

      {loading ? (
        <div className="forecast-grid">
          {[0, 1].map((i) => (
            <div key={i} className="forecast-card" aria-hidden="true">
              <span className="skel" style={{ width: '55%', height: 13 }} />
              <span className="skel" style={{ width: '35%', height: 11, marginTop: 10 }} />
            </div>
          ))}
        </div>
      ) : due.length === 0 ? (
        <div className="forecast-empty">Aucune échéance prévisible pour le moment.</div>
      ) : (
        <div className="forecast-grid">
          {due.map((d, i) => (
            <DueCard key={`${d.type}-${i}`} due={d} />
          ))}
        </div>
      )}

      {gaps.length > 0 ? (
        <div className="forecast-gaps">
          <h3 className="forecast-gaps-title">À renseigner</h3>
          <div className="forecast-gaps-list">
            {gaps.map((type) => (
              <div key={type} className="forecast-gap">
                <span className="forecast-gap-name">{serviceLabel(type)}</span>
                <span className="forecast-gap-note">cadence connue, jamais consigné</span>
                <button type="button" className="forecast-gap-cta" onClick={() => onLogGap(type)}>
                  Consigner
                </button>
              </div>
            ))}
          </div>
        </div>
      ) : null}
    </div>
  );
}

/** One forecast card — usage-axis cards lead with the remaining usage + a progress bar against the
 *  cadence; time-axis cards show the firm or estimated date. Overdue/estimate/générique are tagged. */
function DueCard({ due }: { due: ForecastDue }) {
  const v = formatDue(due);
  const cardClass = `forecast-card${v.status === 'overdue' ? ' forecast-card--overdue' : ''}`;
  return (
    <div className={cardClass}>
      <div className="forecast-card-head">
        <span className="forecast-card-title">{v.title}</span>
        <span className="forecast-card-tags">
          {v.tags.includes('en retard') ? (
            <StatusPill status="overdue" showDot={false}>en retard</StatusPill>
          ) : null}
          {v.tags.includes('générique') ? <span className="forecast-tag-generic">générique</span> : null}
        </span>
      </div>
      <div className={`forecast-lead${v.estimate ? ' forecast-lead--estimate' : ''}${v.status === 'overdue' ? ' forecast-lead--overdue' : ''}`}>
        {v.lead}
      </div>
      {v.axis === 'usage' && v.progress != null ? (
        <div className="forecast-bar" role="progressbar" aria-valuenow={v.progress} aria-valuemin={0} aria-valuemax={100}>
          <span
            className={`forecast-bar-fill${v.status === 'overdue' ? ' forecast-bar-fill--overdue' : ''}`}
            style={{ width: `${v.progress}%` }}
          />
        </div>
      ) : null}
      {v.sub ? <div className="forecast-sub">{v.sub}</div> : null}
    </div>
  );
}
