import { useCallback, useEffect, useState } from 'react';
import { getTurn, getTurns, type TurnDetail, type TurnSummary } from '../journal';

interface JournalPanelProps {
  open: boolean;
  onClose: () => void;
}

const STATUS_TONE: Record<TurnSummary['status'], string> = {
  completed: 'ok',
  capped: 'warn',
  failed: 'alarm',
};

/**
 * The agent debug journal: a right-side drawer listing the owner's turns (newest first), each
 * expandable to its full record — the built LLM context, every tool call with arguments and result,
 * the web searches, the token usage and the raw payloads. The dashboard is otherwise a black box once
 * a turn runs; this is where you see what the agent actually did.
 *
 * Turns load on open (and on demand via refresh). Selecting a row fetches its detail lazily — the
 * listing stays light, the heavy payload rides the item read.
 */
export function JournalPanel({ open, onClose }: JournalPanelProps) {
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

  // Load the listing whenever the drawer opens.
  useEffect(() => {
    if (open) load();
  }, [open, load]);

  // Close on Escape, like the account menu.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  const select = useCallback((iri: string) => {
    setSelectedId(iri);
    setDetail(null);
    getTurn(iri)
      .then(setDetail)
      .catch((e) => setError((e as Error).message));
  }, []);

  if (!open) return null;

  return (
    <div className="journal-overlay" onClick={onClose}>
      <aside className="journal-drawer" onClick={(e) => e.stopPropagation()} aria-label="Journal de l'agent">
        <header className="journal-head">
          <h2>Journal de l'agent</h2>
          <div className="journal-head-actions">
            <button type="button" className="journal-refresh" onClick={load} title="Rafraîchir">↻</button>
            <button type="button" className="journal-close" onClick={onClose} aria-label="Fermer">✕</button>
          </div>
        </header>

        {error ? <div className="journal-error">Échec du chargement : {error}</div> : null}

        <div className="journal-body">
          <ol className="journal-list">
            {loading && turns.length === 0 ? <li className="journal-empty">Chargement…</li> : null}
            {!loading && turns.length === 0 && !error ? <li className="journal-empty">Aucun tour enregistré.</li> : null}
            {turns.map((t) => (
              <li key={t.id}>
                <button
                  type="button"
                  className={`journal-row${selectedId === t.id ? ' selected' : ''}`}
                  onClick={() => select(t.id)}
                >
                  <span className={`journal-status journal-status--${STATUS_TONE[t.status]}`}>{t.status}</span>
                  <span className="journal-instruction">{t.instruction || '(vide)'}</span>
                  <span className="journal-meta">
                    {fmtTime(t.startedAt)} · {t.toolCallCount} outil{t.toolCallCount > 1 ? 's' : ''}
                    {t.webSearchCount > 0 ? ` · 🌐${t.webSearchCount}` : ''}
                    {t.durationMs != null ? ` · ${fmtDuration(t.durationMs)}` : ''}
                  </span>
                </button>
                {selectedId === t.id ? <TurnDetailView detail={detail} /> : null}
              </li>
            ))}
          </ol>
        </div>
      </aside>
    </div>
  );
}

function TurnDetailView({ detail }: { detail: TurnDetail | null }) {
  if (!detail) return <div className="journal-detail journal-detail--loading">Chargement du détail…</div>;

  return (
    <div className="journal-detail">
      <dl className="journal-facts">
        <dt>Modèle</dt>
        <dd>{detail.provider || '?'} / {detail.model || '?'} {detail.stream ? '(stream)' : ''}</dd>
        <dt>Tokens</dt>
        <dd>
          {detail.totalTokens != null
            ? `${detail.totalTokens} (in ${detail.promptTokens ?? '?'} / out ${detail.completionTokens ?? '?'})`
            : 'inconnu'}
        </dd>
        {detail.selectedId ? (
          <>
            <dt>Véhicule</dt>
            <dd className="journal-mono">{detail.selectedId}</dd>
          </>
        ) : null}
        {detail.error ? (
          <>
            <dt>Erreur</dt>
            <dd className="journal-mono journal-detail--error">{detail.error}</dd>
          </>
        ) : null}
      </dl>

      <Section title="Contexte LLM">
        <pre className="journal-pre">{detail.context || '(vide)'}</pre>
      </Section>

      <Section title={`Appels d'outils (${detail.toolCalls.length})`}>
        {detail.toolCalls.length === 0 ? (
          <p className="journal-muted">Aucun</p>
        ) : (
          <ol className="journal-tools">
            {detail.toolCalls.map((c) => (
              <li key={c.seq}>
                <code className="journal-tool-name">
                  {c.name}
                  {c.serverSide ? ' ⚙' : ''}
                </code>
                <pre className="journal-pre journal-pre--sm">{JSON.stringify(c.arguments, null, 2)}</pre>
                <pre className="journal-pre journal-pre--sm journal-pre--muted">{c.result}</pre>
              </li>
            ))}
          </ol>
        )}
      </Section>

      {detail.webSearchCount > 0 ? (
        <Section title={`Recherche web (${detail.webSearchCount})`}>
          {detail.webSearchQueries.length === 0 ? (
            <p className="journal-muted">Aucune requête capturée (stream).</p>
          ) : (
            <ul className="journal-queries">
              {detail.webSearchQueries.map((q, i) => (
                <li key={i} className="journal-mono">{q}</li>
              ))}
            </ul>
          )}
        </Section>
      ) : null}

      {detail.finalText ? (
        <Section title="Réponse finale">
          <pre className="journal-pre">{detail.finalText}</pre>
        </Section>
      ) : null}

      {detail.rawCalls.length > 0 ? (
        <details className="journal-raw">
          <summary>Données brutes ({detail.rawCalls.length})</summary>
          <pre className="journal-pre journal-pre--sm">{JSON.stringify(detail.rawCalls, null, 2)}</pre>
        </details>
      ) : null}
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="journal-section">
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
