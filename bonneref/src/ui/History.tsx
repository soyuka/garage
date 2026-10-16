import { useLayoutEffect, useRef, useState } from 'react';
import type { FinderApi } from '../client';
import { formatRelative, formatWhen, withoutRemoved, type HistoryEntry } from '../history';
import { useToast } from './Toast';

// Logged-in home: the last searches from the journal; one click re-reads the cached result.
// Removing only hides a row from this list: the journal drawer keeps every one.
interface Props {
  api: FinderApi;
  entries: HistoryEntry[];
  loading: boolean;
  onOpen: (e: HistoryEntry) => void;
  title?: string;
  // One vehicle's searches: the vehicle column says nothing there.
  compact?: boolean;
}

export function History({ api, entries, loading, onOpen, title = 'Recherches récentes', compact = false }: Props) {
  const toast = useToast();
  const [removed, setRemoved] = useState<ReadonlySet<string>>(new Set());
  const [leaving, setLeaving] = useState<ReadonlySet<string>>(new Set());
  const rows = useRef(new Map<string, HTMLLIElement>());
  const before = useRef<Map<string, number> | null>(null);
  const shown = withoutRemoved(entries, removed);

  // FLIP: the rows under a removed one slide up into its place instead of jumping.
  useLayoutEffect(() => {
    const tops = before.current;
    before.current = null;
    if (!tops) return;
    for (const [id, el] of rows.current) {
      const was = tops.get(id);
      const dy = was === undefined ? 0 : was - el.getBoundingClientRect().top;
      if (dy) el.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration: 260, easing: 'cubic-bezier(0.2, 0, 0, 1)' });
    }
  }, [removed]);

  if (!loading && shown.length === 0) return null;

  const hide = (e: HistoryEntry) => {
    before.current = new Map([...rows.current].map(([id, el]) => [id, el.getBoundingClientRect().top]));
    setRemoved((r) => new Set(r).add(e.id));
  };

  const remove = async (e: HistoryEntry) => {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    setLeaving((l) => new Set(l).add(e.id));
    window.setTimeout(() => hide(e), reduced ? 0 : 180);
    try {
      await Promise.all(e.ids.map((id) => api.hideFromHistory(id)));
    } catch {
      const restore = (r: ReadonlySet<string>) => {
        const next = new Set(r);
        next.delete(e.id);
        return next;
      };
      window.setTimeout(() => {
        setRemoved(restore);
        setLeaving(restore);
      }, 200);
      toast('Recherche non retirée. Réessayez.');
    }
  };

  return (
    <section className={compact ? 'history history--compact' : 'history'} aria-labelledby="history-title" aria-busy={loading}>
      <div className="history-head">
        <h2 id="history-title">{title}</h2>
      </div>
      {loading ? (
        <p className="history-loading">Chargement…</p>
      ) : (
        <ol>
          {shown.map((e) => (
            <li
              key={e.id}
              data-leaving={leaving.has(e.id) || undefined}
              ref={(el) => {
                if (el) rows.current.set(e.id, el);
                else rows.current.delete(e.id);
              }}
            >
              <a href={e.hash} onClick={() => onOpen(e)}>
                <span className="history-input">{e.input || '—'}</span>
                {!compact && (
                  <span className="history-vehicle">
                    {e.vehicle.plate && <span className="m">{e.vehicle.plate}</span>}
                    {e.vehicle.name && <span>{e.vehicle.name}</span>}
                  </span>
                )}
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
