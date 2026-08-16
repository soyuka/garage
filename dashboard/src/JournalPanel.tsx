import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { useT } from './i18n/LocaleProvider';
import './journal.css';
import { getTurn, getTurns, type TurnDetail, type TurnSummary } from './journal';

// The agent debug journal (ported from the old dashboard — the user's favourite debug surface, rebuilt
// in the Atelier idiom): a right-side glass drawer listing the owner's turns (newest first), each
// expandable to its full record — built LLM context, every tool call (args + result), web searches,
// token usage, raw payloads. Turns load on open + on refresh; a row's detail loads lazily.

const STATUS_TONE: Record<TurnSummary['status'], 'ok' | 'warn' | 'alarm'> = {
  completed: 'ok',
  capped: 'warn',
  failed: 'alarm',
};

export function JournalPanel({ open, onClose }: { open: boolean; onClose: () => void }) {
  const t = useT();
  const [turns, setTurns] = useState<TurnSummary[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<TurnDetail | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    getTurns()
      .then(setTurns)
      .catch((e) => setError((e as Error).message))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    if (open) load();
  }, [open, load]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  const toggle = useCallback((iri: string) => {
    setSelectedId((current) => {
      if (current === iri) {
        setDetail(null);
        return null;
      }
      setDetail(null);
      getTurn(iri)
        .then(setDetail)
        .catch((e) => setError((e as Error).message));
      return iri;
    });
  }, []);

  if (!open) return null;

  return (
    <div className="at-journal-overlay" onClick={onClose}>
      <aside className="at-journal glass" onClick={(e) => e.stopPropagation()} aria-label={t.journal.title}>
        <header className="at-journal-head">
          <h2>{t.journal.title}</h2>
          <div className="at-journal-head-actions">
            <button type="button" className="at-journal-btn" onClick={load} title={t.journal.refresh} aria-label={t.journal.refresh}>↻</button>
            <button type="button" className="at-journal-btn" onClick={onClose} aria-label={t.journal.close}>✕</button>
          </div>
        </header>

        {error && <div className="at-journal-error">{t.journal.loadFailed} {error}</div>}

        <ol className="at-journal-list">
          {loading && turns.length === 0 && <li className="at-journal-empty">{t.journal.loading}</li>}
          {!loading && turns.length === 0 && !error && <li className="at-journal-empty">{t.journal.empty}</li>}
          {turns.map((t) => (
            <li key={t.id}>
              <button
                type="button"
                className={`at-journal-row${selectedId === t.id ? ' at-journal-row--on' : ''}`}
                onClick={() => toggle(t.id)}
                aria-expanded={selectedId === t.id}
              >
                <span className={`at-journal-status at-journal-status--${STATUS_TONE[t.status]}`}>{t.status}</span>
                <span className="at-journal-instruction">{t.instruction || '(vide)'}</span>
                <span className="at-journal-meta">
                  {fmtTime(t.startedAt)} · {t.toolCallCount} outil{t.toolCallCount > 1 ? 's' : ''}
                  {t.webSearchCount > 0 ? ` · 🌐${t.webSearchCount}` : ''}
                  {t.durationMs != null ? ` · ${fmtDuration(t.durationMs)}` : ''}
                </span>
              </button>
              {selectedId === t.id && <TurnDetailView detail={detail} />}
            </li>
          ))}
        </ol>
      </aside>
    </div>
  );
}

function TurnDetailView({ detail }: { detail: TurnDetail | null }) {
  const t = useT();
  if (!detail) return <div className="at-journal-detail at-journal-muted">{t.journal.loadingDetail}</div>;
  return (
    <div className="at-journal-detail">
      <Section title="Prompt">
        <pre className="at-journal-pre">{detail.instruction || '(vide)'}</pre>
      </Section>

      <dl className="at-journal-facts">
        <dt>{t.journal.model}</dt>
        <dd>
          {detail.provider || '?'} / {detail.model || '?'} {detail.stream ? '(stream)' : ''}
        </dd>
        <dt>Tokens</dt>
        <dd>
          {detail.totalTokens != null
            ? `${detail.totalTokens} (in ${detail.promptTokens ?? '?'} / out ${detail.completionTokens ?? '?'})`
            : 'inconnu'}
        </dd>
        {detail.selectedId && (
          <>
            <dt>{t.journal.vehicle}</dt>
            <dd className="at-journal-mono">{detail.selectedId}</dd>
          </>
        )}
        {detail.error && (
          <>
            <dt>{t.journal.error}</dt>
            <dd className="at-journal-mono at-journal-err-text">{detail.error}</dd>
          </>
        )}
      </dl>

      {detail.context && (
        <Section title={t.journal.llmContext}>
          <pre className="at-journal-pre">{detail.context}</pre>
        </Section>
      )}

      <Section title={`Appels d'outils (${detail.toolCalls.length})`}>
        {detail.toolCalls.length === 0 ? (
          <p className="at-journal-muted">{t.journal.none}</p>
        ) : (
          <ol className="at-journal-tools">
            {detail.toolCalls.map((c) => (
              <li key={c.seq}>
                <code className="at-journal-tool-name">
                  {c.name}
                  {c.serverSide ? ' ⚙' : ''}
                </code>
                <pre className="at-journal-pre at-journal-pre--sm">{JSON.stringify(c.arguments, null, 2)}</pre>
                <pre className="at-journal-pre at-journal-pre--sm at-journal-muted">{c.result}</pre>
              </li>
            ))}
          </ol>
        )}
      </Section>

      {detail.webSearchCount > 0 && (
        <Section title={`Recherche web (${detail.webSearchCount})`}>
          {detail.webSearchQueries.length === 0 ? (
            <p className="at-journal-muted">{t.journal.noCalls}</p>
          ) : (
            <ul className="at-journal-queries">
              {detail.webSearchQueries.map((q, i) => (
                <li key={i} className="at-journal-mono">
                  {q}
                </li>
              ))}
            </ul>
          )}
        </Section>
      )}

      {detail.finalText && (
        <Section title={t.journal.finalAnswer}>
          <pre className="at-journal-pre">{detail.finalText}</pre>
        </Section>
      )}

      {detail.rawCalls.length > 0 && (
        <details className="at-journal-raw">
          <summary>{t.journal.rawData(detail.rawCalls.length)}</summary>
          <pre className="at-journal-pre at-journal-pre--sm">{JSON.stringify(detail.rawCalls, null, 2)}</pre>
        </details>
      )}
    </div>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="at-journal-section">
      <h3>{title}</h3>
      {children}
    </section>
  );
}

function fmtTime(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'medium' });
}

function fmtDuration(ms: number): string {
  return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;
}
