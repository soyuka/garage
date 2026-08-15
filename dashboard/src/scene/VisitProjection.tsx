import './visitprojection.css';
import { formatDate } from '../format';
import { ladder, type Visit } from './visits';

// The visit ladder — what « Plus tard · n » opens, in the shared projection column at the car's RIGHT.
//
// It is the magnification the compressed rail owes the operator: a bucket collapsed to a count is only
// honest if there is a way to look closer (CHOREOGRAPHY.md § "Why a count and not a scroll"). Read-only,
// like the worklist — every one of these services is editable in the rail once its visit comes due.
//
// The rungs are spaced by `ladder()`: the gap before a rung is the share of the widest calendar hop, so
// the shape of the next four years is legible without reading a single date.

export function VisitProjection({
  visits,
  reduced = false,
  onDismiss,
}: {
  visits: Visit[];
  reduced?: boolean;
  onDismiss?: () => void;
}) {
  if (visits.length === 0) return null;
  const rungs = ladder(visits);
  const services = visits.reduce((n, v) => n + v.items.length, 0);

  return (
    <aside className="at-ladder" data-reduced={reduced ? 'true' : undefined} role="group" aria-label="Plus tard">
      <div className="at-proj-head">
        <span className="at-proj-tick" aria-label="placé par Le Noyau" role="img" />
        <div className="at-ladder-head">PLUS TARD</div>
        {onDismiss && (
          <button type="button" className="at-proj-dismiss" onClick={onDismiss} aria-label="Fermer l’échéancier">
            ✕
          </button>
        )}
      </div>

      <ol className="at-ladder-rungs">
        {rungs.map(({ visit, gap }, i) => (
          <li
            key={visit.id}
            className="at-ladder-rung"
            style={
              {
                '--at-gap': gap,
                ...(reduced ? {} : { animationDelay: `${Math.min(i, 4) * 60 + 50}ms` }),
              } as React.CSSProperties
            }
          >
            <span className="at-ladder-date">{visit.date ? formatDate(visit.date) : 'sans échéance'}</span>
            <span className="at-ladder-svc">{visit.items.map((item) => item.label).join(' + ')}</span>
          </li>
        ))}
      </ol>

      <div className="at-ladder-foot">
        {visits.length} visite{visits.length > 1 ? 's' : ''} · {services} service{services > 1 ? 's' : ''}
      </div>
    </aside>
  );
}
