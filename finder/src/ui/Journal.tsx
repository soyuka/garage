import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { login } from '../auth';
import type { FinderApi } from '../client';
import { formatCount } from '../format';
import { excerpt, formatCost, framesOf, outcomeOf, todayUsage, type JournalDetail, type JournalRow } from '../journal';

const QUOTA_PER_METHOD = 50;

const RULES: Record<string, string> = {
  plate: 'plaque',
  'plate-fni': 'plaque FNI',
  vin: 'VIN',
  category: 'catégorie',
  recent: 'récente',
  'carte-grise': 'carte grise',
  url: 'lien',
};

const time = (iso: string): string => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
};

const day = (iso: string): string => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
};

const duration = (ms: number | null): string => (ms === null ? '—' : ms < 1000 ? `${ms} ms` : `${(ms / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} s`);

const kb = (bytes: number): string => (bytes < 1024 ? `${bytes} o` : bytes < 1024 * 1024 ? `${formatCount(Math.round(bytes / 1024))} ko` : `${(bytes / 1024 / 1024).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} Mo`);

function CloseIcon() {
  return (
    <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
      <path d="M3.5 3.5l9 9M12.5 3.5l-9 9" />
    </svg>
  );
}

function RefreshIcon() {
  return (
    <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <path d="M13 8a5 5 0 1 1-1.5-3.6M13 2.5v3h-3" />
    </svg>
  );
}

type DetailState = { status: 'loading' } | { status: 'ok'; data: JournalDetail } | { status: 'error'; message: string };

interface Props {
  api: FinderApi;
  open: boolean;
  // Closing: still mounted for its exit slide.
  leaving?: boolean;
  authed: boolean;
  docked: boolean;
  onClose: () => void;
}

export function Journal({ api, open, leaving = false, authed, docked, onClose }: Props) {
  const [rows, setRows] = useState<JournalRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState<string | null>(null);
  const [detail, setDetail] = useState<DetailState | null>(null);
  const heading = useRef<HTMLHeadingElement>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api
      .journal()
      .then(setRows)
      .catch((e: unknown) => setError(e instanceof Error ? e.message : 'Journal indisponible.'))
      .finally(() => setLoading(false));
  }, [api]);

  useEffect(() => {
    if (!open) return;
    heading.current?.focus();
    if (authed) load();
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, authed, load, onClose]);

  const toggle = (id: string) => {
    if (selected === id) {
      setSelected(null);
      setDetail(null);
      return;
    }
    setSelected(id);
    setDetail({ status: 'loading' });
    api
      .journalEntry(id)
      .then((data) => setDetail({ status: 'ok', data }))
      .catch((e: unknown) => setDetail({ status: 'error', message: e instanceof Error ? e.message : 'Entrée indisponible.' }));
  };

  if (!open) return null;
  const usage = rows ? todayUsage(rows, new Date()) : null;

  return (
    <Layer docked={docked} onClose={onClose}>
      <aside className={docked ? 'journal journal--docked' : 'journal'} data-leaving={leaving || undefined} aria-labelledby="journal-title">
        <header className="journal-head">
          <h2 id="journal-title" ref={heading} tabIndex={-1}>
            Journal
          </h2>
          <span className="journal-sub">finder</span>
          <div className="journal-tools">
            {authed && (
              <button type="button" className="icon-btn" onClick={load} disabled={loading} aria-label="Actualiser le journal" title="Actualiser">
                <RefreshIcon />
              </button>
            )}
            <button type="button" className="icon-btn" onClick={onClose} aria-label="Fermer le journal" title="Fermer">
              <CloseIcon />
            </button>
          </div>
        </header>

        {!authed ? (
          <div className="journal-empty">
            <p>Connexion requise pour lire le journal.</p>
            <button type="button" className="btn pri" onClick={() => void login()}>
              Se connecter
            </button>
          </div>
        ) : (
          <>
            {usage && (
              <section className="journal-quota" aria-labelledby="quota-title">
                <span className="l" id="quota-title">
                  Appels partsapi du jour · quota {QUOTA_PER_METHOD} par méthode
                </span>
                <ul className="journal-methods m">
                  <li className="journal-methods-total">
                    <span>Total</span>
                    <span>{formatCount(usage.total)}</span>
                  </li>
                  {Object.entries(usage.byMethod)
                    .sort((a, b) => b[1] - a[1])
                    .map(([method, n]) => (
                      <li key={method} data-near={n >= QUOTA_PER_METHOD * 0.8}>
                        <span>{method}</span>
                        <span>
                          {n}/{QUOTA_PER_METHOD}
                        </span>
                      </li>
                    ))}
                </ul>
              </section>
            )}
            {error && (
              <p className="field-error journal-pad" role="alert">
                {error}
              </p>
            )}
            <ol className="journal-list" aria-busy={loading}>
              {rows === null && loading && <li className="journal-muted journal-pad">Chargement…</li>}
              {rows !== null && rows.length === 0 && <li className="journal-muted journal-pad">Aucune recherche.</li>}
              {rows?.map((row) => (
                <li key={row.id}>
                  <Row row={row} open={selected === row.id} onToggle={() => toggle(row.id)} />
                  {selected === row.id && <Detail detail={detail} />}
                </li>
              ))}
            </ol>
          </>
        )}
      </aside>
    </Layer>
  );
}

// Docked, the journal is a column beside the sheet: no scrim, the page stays usable.
function Layer({ docked, onClose, children }: { docked: boolean; onClose: () => void; children: ReactNode }) {
  if (docked) return children;
  return (
    <div className="journal-layer">
      <button type="button" className="journal-scrim" aria-label="Fermer le journal" tabIndex={-1} onClick={onClose} />
      {children}
    </div>
  );
}

function Row({ row, open, onToggle }: { row: JournalRow; open: boolean; onToggle: () => void }) {
  const outcome = outcomeOf(row);
  const rule = row.search ? (RULES[row.search.rule] ?? row.search.rule) : null;
  return (
    <button type="button" className="journal-row" aria-expanded={open} onClick={onToggle}>
      <span className="journal-row-top">
        <time className="m" dateTime={row.startedAt} title={day(row.startedAt)}>
          {time(row.startedAt)}
        </time>
        <span className="tag">{row.kind === 'agent' ? 'agent' : `recherche · ${rule}`}</span>
        <span className={`tag ${outcome.tone === 'ok' ? 'ok' : 'warn'}`} data-tone={outcome.tone}>
          {outcome.label}
        </span>
      </span>
      <span className="journal-input">{row.instruction || '—'}</span>
      <span className="journal-meta m">
        <span>{duration(row.durationMs)}</span>
        <span>partsapi {row.partsApiCalls ?? '—'}</span>
        {row.kind === 'agent' && <span>{row.totalTokens === null ? 'tokens —' : `${formatCount(row.totalTokens)} tokens`}</span>}
        {row.images.length > 0 && <span>{row.images.length === 1 ? '1 photo' : `${row.images.length} photos`}</span>}
      </span>
    </button>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt>{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}

function Detail({ detail: state }: { detail: DetailState | null }) {
  if (state === null || state.status === 'loading') return <p className="journal-detail journal-muted">Chargement du détail…</p>;
  if (state.status === 'error')
    return (
      <p className="journal-detail field-error" role="alert">
        {state.message}
      </p>
    );
  const detail = state.data;

  const frames = framesOf(detail.toolCalls);
  const cost = formatCost(detail.estimatedCostUsd);
  const s = detail.search;

  return (
    <div className="journal-detail">
      {s && (
        <dl className="journal-facts">
          <Fact label="Règle">{RULES[s.rule] ?? s.rule}</Fact>
          <Fact label="kType">{s.carId ?? '—'}</Fact>
          {s.categoryId !== null && <Fact label="Catégorie">{s.categoryId}</Fact>}
          {s.clusterCount !== null && <Fact label="Pièces distinctes">{s.clusterCount}</Fact>}
          {s.totalArticles !== null && <Fact label="Articles">{formatCount(s.totalArticles)}</Fact>}
          {s.unclassifiedCount !== null && <Fact label="Non classés">{s.unclassifiedCount}</Fact>}
          <Fact label="HTTP">{s.httpStatus ?? '—'}</Fact>
          <Fact label="Durée">{duration(detail.durationMs)}</Fact>
        </dl>
      )}
      {detail.kind === 'agent' && (
        <dl className="journal-facts">
          <Fact label="Durée">{duration(detail.durationMs)}</Fact>
          <Fact label="Entrée">{detail.promptTokens === null ? '—' : formatCount(detail.promptTokens)}</Fact>
          <Fact label="Sortie">{detail.completionTokens === null ? '—' : formatCount(detail.completionTokens)}</Fact>
          <Fact label="Cache lu">{detail.cacheReadTokens === null ? '—' : formatCount(detail.cacheReadTokens)}</Fact>
          <Fact label="Cache écrit">{detail.cacheCreationTokens === null ? '—' : formatCount(detail.cacheCreationTokens)}</Fact>
          <Fact label="Total">{detail.totalTokens === null ? '—' : formatCount(detail.totalTokens)}</Fact>
        </dl>
      )}
      {detail.kind === 'agent' && (
        <p className="journal-line">
          <span className="l">Coût estimé</span>
          {cost ?? <span className="journal-muted">non calculé (AGENT_PRICE_* absents côté API)</span>}
        </p>
      )}
      <p className="journal-line">
        <span className="l">partsapi</span>
        {detail.partsApiCalls === null ? (
          '—'
        ) : (
          <span className="m">
            {detail.partsApiCalls}
            {Object.keys(detail.partsApiCallsByMethod).length > 0 &&
              ` · ${Object.entries(detail.partsApiCallsByMethod)
                .map(([m, n]) => `${m} ${n}`)
                .join(' · ')}`}
          </span>
        )}
      </p>
      {detail.images.length > 0 && (
        <p className="journal-line">
          <span className="l">Photos (non conservées)</span>
          <span className="m">{detail.images.map((i) => `${i.mimeType.replace('image/', '')} ${kb(i.bytes)}`).join(' · ')}</span>
        </p>
      )}
      {detail.error && (
        <p className="journal-line journal-error">
          <span className="l">Erreur</span>
          <span className="m">{detail.error}</span>
        </p>
      )}

      {frames.length > 0 && (
        <section className="journal-section">
          <h3>Frames émises ({frames.length})</h3>
          <ol className="journal-frames">
            {frames.map((f, i) => (
              <li key={i}>
                <code>{f.type}</code> {f.label}
              </li>
            ))}
          </ol>
        </section>
      )}

      {detail.kind === 'agent' && (
        <section className="journal-section">
          <h3>Appels d’outils ({detail.toolCalls.length})</h3>
          {detail.toolCalls.length === 0 ? (
            <p className="journal-muted">Aucun.</p>
          ) : (
            <ol className="journal-calls">
              {detail.toolCalls.map((c) => {
                const r = excerpt(c.result);
                return (
                  <li key={c.seq}>
                    <p className="journal-tool">
                      <span className="m journal-seq">{String(c.seq + 1).padStart(2, '0')}</span>
                      <code>{c.name}</code>
                      <span className="journal-muted m">résultat {kb(r.bytes)}</span>
                    </p>
                    <pre className="journal-pre">{JSON.stringify(c.arguments, null, 1)}</pre>
                    <pre className="journal-pre journal-pre--result">
                      {r.text}
                      {r.cut && '…'}
                    </pre>
                  </li>
                );
              })}
            </ol>
          )}
        </section>
      )}

      {detail.webSearchQueries.length > 0 && (
        <section className="journal-section">
          <h3>Recherches web ({detail.webSearchCount})</h3>
          <ul className="journal-frames">
            {detail.webSearchQueries.map((q, i) => (
              <li key={i} className="m">
                {q}
              </li>
            ))}
          </ul>
        </section>
      )}

      {detail.context && (
        <details className="journal-section">
          <summary>Contexte envoyé au modèle</summary>
          <pre className="journal-pre">{detail.context}</pre>
        </details>
      )}
    </div>
  );
}
