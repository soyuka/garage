import { formatWhen, type HistoryEntry } from '../history';

// Logged-in home: the last searches from the journal; one click re-reads the cached result.
export function History({ entries, loading, onOpen, base }: { entries: HistoryEntry[]; loading: boolean; onOpen: (e: HistoryEntry) => void; base: string }) {
  if (!loading && entries.length === 0) return null;
  return (
    <section className="history" aria-labelledby="history-title" aria-busy={loading}>
      <h2 id="history-title">Recherches récentes</h2>
      {loading ? (
        <p className="history-loading">Lecture du journal…</p>
      ) : (
        <ol>
          {entries.map((e) => (
            <li key={e.id}>
              <a href={`${base}${e.hash}`} onClick={() => onOpen(e)}>
                <span className="history-input">{e.input || '—'}</span>
                <span className="history-vehicle">
                  {e.vehicle.plate && <span className="m">{e.vehicle.plate}</span>}
                  {e.vehicle.name && <span>{e.vehicle.name}</span>}
                </span>
                <span className="tag" data-tone={e.outcome.tone}>
                  {e.outcome.label}
                </span>
                <time className="m" dateTime={e.startedAt}>
                  {formatWhen(e.startedAt)}
                </time>
              </a>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
