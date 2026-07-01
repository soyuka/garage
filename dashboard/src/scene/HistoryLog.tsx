import { useEffect } from 'react';
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

export function HistoryLog({
  open,
  onClose,
  records,
  reduced = false,
}: {
  open: boolean;
  onClose: () => void;
  records: MaintenanceRecord[];
  reduced?: boolean;
}) {
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
        aria-label="Carnet d’entretien"
      >
        <header className="at-hlog-head">
          <h2>Carnet d’entretien</h2>
          <button type="button" className="at-hlog-close" onClick={onClose} aria-label="Fermer">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
              <path d="M1 1l12 12M13 1L1 13" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
            </svg>
          </button>
        </header>

        {visits.length === 0 ? (
          <p className="at-hlog-empty">Aucun entretien enregistré.</p>
        ) : (
          <ol className="at-hlog-entries">
            {visits.map((record, i) => (
              <li
                key={record.id}
                className="at-hlog-entry"
                style={reduced ? undefined : { animationDelay: `${Math.min(i, 8) * 45}ms` }}
              >
                <div className="at-hlog-entry-head">
                  <span className="at-hlog-date">{formatDate(record.performedAt)}</span>
                  <span className="at-hlog-title">{visitTitle(record)}</span>
                  {record.costCents != null && <span className="at-hlog-cost">{formatEuros(record.costCents)}</span>}
                </div>
                <div className="at-hlog-entry-meta">
                  {record.usageValue != null && <span>{formatReading(record.usageValue, record.usageUnit)}</span>}
                  {record.performedBy && <span>{record.performedBy}</span>}
                </div>
                {record.items.length > 0 && (
                  <ul className="at-hlog-items">
                    {record.items.map((it, j) => (
                      <li key={j} className="at-hlog-item">
                        <span className="at-hlog-item-name">{it.label?.trim() || serviceLabel(it.type)}</span>
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
            ))}
          </ol>
        )}
      </section>
    </div>
  );
}
