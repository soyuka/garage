import './visitprojection.css';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { formatDate } from '../format';
import { ladder, visitRegions, type Visit } from './visits';
import type { BodyRegion } from './anchors';

// The visit ladder — what « Plus tard · n » opens, in the shared projection column at the car's RIGHT.
//
// It is the magnification the compressed rail owes the operator: a bucket collapsed to a count is only
// honest if there is a way to look closer. Read-only,
// like the worklist — every one of these services is editable in the rail once its visit comes due.
//
// The rungs are spaced by `ladder()`: the gap before a rung is the share of the widest calendar hop, so
// the shape of the next four years is legible without reading a single date.
//
// A rung aims the earned camera at its region, exactly as a rail visit card's head does — the same
// gesture one surface further away, never a second grammar for the same act. Dolly only, no cyan pulse:
// the operator moved the camera, Le Noyau did not.

export function VisitProjection({
  visits,
  reduced = false,
  onDismiss,
  onAimRegion,
  heldRegion = null,
}: {
  visits: Visit[];
  reduced?: boolean;
  onDismiss?: () => void;
  /** Aim the earned camera at a rung's region. Absent → rungs are plain text, never dead controls. */
  onAimRegion?: (region: BodyRegion) => void;
  heldRegion?: BodyRegion | null;
}) {
  const t = useT();
  if (visits.length === 0) return null;
  const rungs = ladder(visits);
  const services = visits.reduce((n, v) => n + v.items.length, 0);

  return (
    <aside className="at-ladder" data-reduced={reduced ? 'true' : undefined} role="group" aria-label={t.rail.later}>
      <div className="at-proj-head">
        <span className="at-proj-tick" aria-label={t.proj.placedByNoyau} role="img" />
        <div className="at-ladder-head">PLUS TARD</div>
        {onDismiss && (
          <button type="button" className="at-proj-dismiss" onClick={onDismiss} aria-label={t.misc.closeSchedule}>
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
            <RungBody visit={visit} onAim={onAimRegion} held={heldRegion} />
          </li>
        ))}
      </ol>

      <div className="at-ladder-foot">
        {visits.length} visite{visits.length > 1 ? 's' : ''} · {services} service{services > 1 ? 's' : ''}
      </div>

      {/* the hair leader toward the car — the tie every other projection wears and this one lacked. It is
          also what answers the rail's leaders on the opposite edge: one instrument, two columns. */}
      <span className="at-ladder-lead" aria-hidden="true" />
    </aside>
  );
}

/** A rung's content: aimable when the visit reads off a body region, plain text otherwise. */
function RungBody({ visit, onAim, held }: { visit: Visit; onAim?: (region: BodyRegion) => void; held: BodyRegion | null }) {
  const [locale] = useLocale();
  const t = useT();
  const aim = visitRegions(visit)[0];
  const date = visit.date ? formatDate(visit.date, locale) : t.forecast.noDueDateLower;
  const services = visit.items.map((item) => item.label).join(' + ');
  if (!aim || !onAim) {
    return (
      <>
        <span className="at-ladder-date">{date}</span>
        <span className="at-ladder-svc">{services}</span>
      </>
    );
  }
  const isHeld = held === aim;
  return (
    <button
      type="button"
      className="at-ladder-aim"
      data-held={isHeld ? 'true' : undefined}
      title={isHeld ? t.proj.backToOverview : t.proj.seeOnCar}
      onClick={() => onAim(aim)}
    >
      <span className="at-ladder-date">{date}</span>
      <span className="at-ladder-svc">{services}</span>
    </button>
  );
}
