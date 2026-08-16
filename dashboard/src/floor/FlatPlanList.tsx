import { dueDescriptor, signalTag } from '../derive';
import { formatDate } from '../format';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { serviceLabel } from '../labels';
import type { VehicleForecast } from '../types';
import './floor.css';

// The flat "plan" fallback list (DESIGN-VISION §8 / BUILD-NOTE 5) — an honest mono list of next-due
// per service. FIRST-CLASS and permanently reachable, NOT a Plateau sub-feature: this is the view
// that survives when the GPU is weak, and the floor every visual layer is allowed to flourish on top
// of. Every value here is full precision; signal is always paired with a TEXT tag, never color alone.
export function FlatPlanList({ forecast }: { forecast: VehicleForecast }) {
  const [locale] = useLocale();
  const t = useT();
  const rows = forecast.due;
  return (
    <section className="at-plan" aria-label={t.plan.title}>
      <h2 className="at-plan-title">{t.plan.title}</h2>
      {rows.length === 0 ? (
        <p className="at-plan-empty">{t.plan.empty}</p>
      ) : (
        <ol className="at-plan-list">
          {rows.map((due, i) => {
            const d = dueDescriptor(due, locale);
            const date = formatDate(due.projectedDueAt ?? due.dueAt, locale);
            const tag = signalTag(d.overdue, d.signal, locale);
            return (
              <li className="at-plan-row" key={i} data-signal={d.signal}>
                <span className="at-plan-dot" aria-hidden="true" />
                <span className="at-plan-name">{serviceLabel(due.type, locale)}</span>
                <span className="at-plan-when">
                  <span className="at-plan-horizon">{d.horizon}</span>
                  {date && <span className="at-plan-date">{date}</span>}
                </span>
                <span className="at-plan-until">{d.until ?? ''}</span>
                {/* TEXT tag — the signal is never conveyed by color alone */}
                <span className={`at-tag at-tag--${tag.tone}`}>{tag.text}</span>
              </li>
            );
          })}
        </ol>
      )}
      {forecast.gaps.length > 0 && (
        <p className="at-plan-gaps">
          {t.plan.untracked} {forecast.gaps.map((g) => serviceLabel(g, locale)).join(' · ')}
        </p>
      )}
    </section>
  );
}
