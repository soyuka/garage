import './carnet.css';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { formatDate, formatEuros, formatReading } from '../format';
import type { CarnetRollup } from '../carnet';

// The carnet projection (D2) — an on-demand surface in the shared projection column (car's RIGHT): the
// last visits + the total spent + the visit count, from the client-held maintenance records. Deterministic
// (the "Carnet · dépenses" wayfinder) and the agent's `showHistory` converge here. Read-only: every value
// also lives in Le Carnet drawer / the truth panel, so hiding the canvas loses nothing. Rows unfurl staggered.

export function CarnetProjection({
  model,
  reduced = false,
  onDismiss,
  onOpenAll,
}: {
  model: CarnetRollup;
  reduced?: boolean;
  onDismiss?: () => void;
  /** opens Le Carnet drawer (the FULL visit history) — the projection is only the spend summary. */
  onOpenAll?: () => void;
}) {
  const [locale] = useLocale();
  const t = useT();
  if (model.count === 0) return null;
  return (
    <aside className="at-carnet" data-reduced={reduced ? 'true' : undefined} role="group" aria-label={t.proj.carnet}>
      <div className="at-proj-head">
        <span className="at-proj-tick" aria-label={t.proj.placedByNoyau} role="img" />
        <div className="at-carnet-head">{t.proj.carnetHead}</div>
        {onDismiss && (
          <button type="button" className="at-proj-dismiss" onClick={onDismiss} aria-label={t.proj.closeCarnet}>
            ✕
          </button>
        )}
      </div>
      <div className="at-carnet-rows">
        {model.rows.map((r, i) => (
          <div key={r.id} className="at-carnet-row" style={reduced ? undefined : { animationDelay: `${Math.min(i, 3) * 70 + 60}ms` }}>
            <div className="at-carnet-line">
              <span className="at-carnet-date">{formatDate(r.performedAt, locale)}</span>
              {r.costCents != null && (
                <span className="at-carnet-cost">
                  {r.costSource === 'estimated' ? '≈ ' : ''}
                  {formatEuros(r.costCents, locale)}
                </span>
              )}
            </div>
            <div className="at-carnet-meta">
              {r.items.join(' · ') || '—'}
              {r.usageValue != null ? ` — ${formatReading(r.usageValue, r.usageUnit, locale)}` : ''}
            </div>
          </div>
        ))}
      </div>
      <div className="at-carnet-foot">
        <span className="at-carnet-total">
          {model.hasEstimate ? '≈ ' : ''}
          {formatEuros(model.totalCents, locale)}
        </span>
        <span className="at-carnet-count">
          {model.count} visite{model.count > 1 ? 's' : ''}
          {model.unknownCount > 0 ? t.proj.withoutCost(model.unknownCount) : ''}
        </span>
        {onOpenAll && (
          <button type="button" className="at-carnet-all" onClick={onOpenAll}>
            tout l’historique →
          </button>
        )}
      </div>
      <span className="at-carnet-lead" aria-hidden="true" />
    </aside>
  );
}
