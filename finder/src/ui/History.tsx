import { useState } from 'react';
import type { FinderApi } from '../client';
import { formatRelative, formatWhen, withoutRemoved, type HistoryEntry } from '../history';
import { useToast } from './Toast';

// Logged-in home: the last searches from the journal; one click re-reads the cached result.
// Removing only hides a row from this list: the journal drawer keeps every one.
export function History({ api, entries, loading, onOpen }: { api: FinderApi; entries: HistoryEntry[]; loading: boolean; onOpen: (e: HistoryEntry) => void }) {
  const toast = useToast();
  const [removed, setRemoved] = useState<ReadonlySet<string>>(new Set());
  const shown = withoutRemoved(entries, removed);

  if (!loading && shown.length === 0) return null;

  const remove = async (e: HistoryEntry) => {
    setRemoved((r) => new Set(r).add(e.id));
    try {
      await Promise.all(e.ids.map((id) => api.hideFromHistory(id)));
    } catch {
      setRemoved((r) => {
        const next = new Set(r);
        next.delete(e.id);
        return next;
      });
      toast('Recherche non retirée. Réessayez.');
    }
  };

  return (
    <section className="history" aria-labelledby="history-title" aria-busy={loading}>
      <div className="history-head">
        <h2 id="history-title">Recherches récentes</h2>
      </div>
      {loading ? (
        <p className="history-loading">Chargement…</p>
      ) : (
        <ol>
          {shown.map((e) => (
            <li key={e.id}>
              <a href={e.hash} onClick={() => onOpen(e)}>
                <span className="history-input">{e.input || '—'}</span>
                <span className="history-vehicle">
                  {e.vehicle.plate && <span className="m">{e.vehicle.plate}</span>}
                  {e.vehicle.name && <span>{e.vehicle.name}</span>}
                </span>
                <span className="tag" data-tone={e.outcome.tone}>
                  {e.outcome.label}
                </span>
                <time dateTime={e.startedAt} title={formatWhen(e.startedAt)}>
                  {formatRelative(e.startedAt)}
                </time>
              </a>
              <button type="button" className="history-remove" aria-label="Retirer de l’historique" onClick={() => remove(e)}>
                <svg viewBox="0 0 12 12" width="12" height="12" aria-hidden="true">
                  <path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" strokeWidth="1.5" fill="none" strokeLinecap="round" />
                </svg>
              </button>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
