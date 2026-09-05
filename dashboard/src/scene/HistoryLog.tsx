import { useEffect } from 'react';
import { useLocale, useT } from '../i18n/LocaleProvider';
import './historylog.css';
import { formatEuros, formatDate, formatReading } from '../format';
import { serviceLabel } from '../labels';
import { visitTitle } from '../records';
import type { MaintenanceRecord } from '../types';

// Le Carnet — a centered glass popup listing past maintenance visits as a chronological ledger
// (garage-log idiom, distinct from Le Dossier's side-drawer edit surface). Read-only: no forms, no
// mutation — this is the "flip through the logbook" surface a quick action opens on demand.

function sortedRecords(records: MaintenanceRecord[]): MaintenanceRecord[] {
  return [...records].sort((a, b) => b.performedAt.localeCompare(a.performedAt));
}

// Effective cost mirrors carnet.ts's rollup: prefer the server-derived effectiveCostCents, fall
// back to costCents for a record the API hasn't re-serialized with the new field yet.
function effectiveCost(record: MaintenanceRecord): { cents: number; estimated: boolean } | null {
  const cents = record.effectiveCostCents ?? record.costCents ?? null;
  if (cents == null) return null;
  return { cents, estimated: record.costSource === 'estimated' };
}

export function HistoryLog({
  open,
  onClose,
  records,
  reduced = false,
  onHoverRecord,
}: {
  open: boolean;
  onClose: () => void;
  records: MaintenanceRecord[];
  reduced?: boolean;
  /** Absent → no ring wired to this ledger (the fixture playground). */
  onHoverRecord?: (id: string | null) => void;
}) {
  const [locale] = useLocale();
  const t = useT();
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;
  const visits = sortedRecords(records);

  return (
    <div className="at-hlog-overlay" data-reduced={reduced ? 'true' : undefined} onClick={onClose}>
      <section
        className="at-hlog"
        onClick={(e) => e.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label={t.carnet.title}
      >
        <header className="at-hlog-head">
          <h2>{t.carnet.title}</h2>
          <button type="button" className="at-hlog-close" onClick={onClose} aria-label={t.scene.close}>
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
              <path d="M1 1l12 12M13 1L1 13" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
            </svg>
          </button>
        </header>

        {visits.length === 0 ? (
          <p className="at-hlog-empty">{t.misc.noServiceLogged}</p>
        ) : (
          <ol className="at-hlog-entries">
            {visits.map((record, i) => {
              const cost = effectiveCost(record);
              return (
                <li
                  key={record.id}
                  className="at-hlog-entry"
                  style={reduced ? undefined : { animationDelay: `${Math.min(i, 8) * 45}ms` }}
                  tabIndex={onHoverRecord ? 0 : undefined}
                  onMouseEnter={onHoverRecord ? () => onHoverRecord(record.id) : undefined}
                  onMouseLeave={onHoverRecord ? () => onHoverRecord(null) : undefined}
                  onFocus={onHoverRecord ? () => onHoverRecord(record.id) : undefined}
                  onBlur={onHoverRecord ? () => onHoverRecord(null) : undefined}
                >
                  <div className="at-hlog-entry-head">
                    <span className="at-hlog-date">{formatDate(record.performedAt, locale)}</span>
                    <span className="at-hlog-title">{visitTitle(record, locale)}</span>
                    {cost && (
                      <span className="at-hlog-cost">
                        {cost.estimated ? '≈ ' : ''}
                        {formatEuros(cost.cents, locale)}
                      </span>
                    )}
                  </div>
                  <div className="at-hlog-entry-meta">
                    {record.usageValue != null && <span>{formatReading(record.usageValue, record.usageUnit, locale)}</span>}
                    {record.performedBy && <span>{record.performedBy}</span>}
                  </div>
                  {record.items.length > 0 && (
                    <ul className="at-hlog-items">
                      {record.items.map((it, j) => (
                        <li key={j} className="at-hlog-item">
                          <span className="at-hlog-item-name">{it.label?.trim() || serviceLabel(it.type, locale)}</span>
                          {it.parts.length > 0 && (
                            <span className="at-hlog-parts">
                              {it.parts
                                .map((p) => [p.brand, p.ref || p.label].filter(Boolean).join(' '))
                                .filter(Boolean)
                                .join(' · ')}
                            </span>
                          )}
                        </li>
                      ))}
                    </ul>
                  )}
                  {record.notes && <p className="at-hlog-notes">{record.notes}</p>}
                </li>
              );
            })}
          </ol>
        )}
      </section>
    </div>
  );
}
