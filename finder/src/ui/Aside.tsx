import type { ReactNode } from 'react';
import type { Question } from '../question';
import type { RecentSearch } from '../recent';
import { narrowingTrace } from '../trace';
import type { Cluster, Narrowing } from '../types';
import type { Async } from './hooks';

interface Props {
  narrowing: Async<Narrowing> | null;
  remaining: number | null;
  cluster: Cluster | null;
  question: Question | null;
  selected: string | null;
  confirmed: boolean;
  onAnswer: (clusterId: string) => void;
  recent: RecentSearch[];
  vehicleLabel: string;
  currentCategory: number | null;
  onRecent: (r: RecentSearch) => void;
  lead?: ReactNode;
}

export function Aside({ narrowing, remaining, cluster, question, selected, confirmed, onAnswer, recent, vehicleLabel, currentCategory, onRecent, lead }: Props) {
  const others = recent.filter((r) => r.categoryId !== currentCategory);
  return (
    <aside className="aside">
      {lead}
      {narrowing && <NarrowingTrace narrowing={narrowing} remaining={remaining} cluster={cluster} />}
      {question && (
        <section aria-labelledby="ask-title">
          <h2 id="ask-title">{confirmed ? 'Votre réponse' : 'Une question'}</h2>
          <div className="ask">
            <p>{question.text}</p>
            {question.hint && <p className="ask-hint">{question.hint}</p>}
            <div role="group" aria-label="Réponses">
              {question.answers.map((a) => (
                <button
                  key={a.clusterId}
                  type="button"
                  className="btn ans"
                  aria-pressed={confirmed && selected === a.clusterId}
                  onClick={() => onAnswer(a.clusterId)}
                >
                  {a.label}
                </button>
              ))}
            </div>
          </div>
        </section>
      )}
      {others.length > 0 && (
        <section className="recent" aria-labelledby="recent-title">
          <h2 id="recent-title">Recherches récentes</h2>
          <ul>
            {others.map((r) => (
              <li key={r.categoryId}>
                <button type="button" onClick={() => onRecent(r)}>
                  {r.label}
                  <span className="m">{vehicleLabel}</span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}
    </aside>
  );
}

const RESULT_LABEL = '→';

function TraceLabel({ label }: { label: string }) {
  if (label !== RESULT_LABEL) return <>{label}</>;
  return (
    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="square" aria-hidden="true">
      <path d="M1 6h10M7 2l4 4-4 4" />
    </svg>
  );
}

const SKELETON_ROWS = ['Références catalogue', 'Marques', RESULT_LABEL];

function NarrowingTrace({ narrowing, remaining, cluster }: { narrowing: Async<Narrowing>; remaining: number | null; cluster: Cluster | null }) {
  if (narrowing.status === 'loading') {
    return (
      <section className="narrow" aria-busy="true" aria-labelledby="narrow-title">
        <h2 id="narrow-title">Affinage</h2>
        <dl className="trace">
          {SKELETON_ROWS.map((label) => (
            <div key={label} data-result={label === RESULT_LABEL || undefined}>
              <dt>
                <TraceLabel label={label} />
              </dt>
              <dd>
                <span className="sk" style={{ width: 36 }} />
              </dd>
            </div>
          ))}
        </dl>
        <p className="trace-status" role="status">Analyse des références du catalogue…</p>
      </section>
    );
  }
  if (narrowing.status !== 'ok') return null;
  const n = narrowing.data;
  const rows = narrowingTrace(n, cluster, remaining ?? n.clusters.length);
  return (
    <section className="narrow" aria-labelledby="narrow-title">
      <h2 id="narrow-title">Affinage</h2>
      <dl className="trace">
        {rows.map((r) => (
          <div key={r.label} data-result={r.result}>
            <dt>
              <TraceLabel label={r.label} />
            </dt>
            <dd className="m">{r.value}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}
