import type { ReactNode } from 'react';
import { formatCount } from '../format';
import type { Question } from '../question';
import type { RecentSearch } from '../recent';
import type { Narrowing } from '../types';
import type { Async } from './hooks';

interface Props {
  narrowing: Async<Narrowing> | null;
  remaining: number | null;
  question: Question | null;
  selected: string | null;
  confirmed: boolean;
  onAnswer: (clusterId: string) => void;
  recent: RecentSearch[];
  plate: string;
  currentCategory: number | null;
  onRecent: (r: RecentSearch) => void;
  lead?: ReactNode;
}

export function Aside({ narrowing, remaining, question, selected, confirmed, onAnswer, recent, plate, currentCategory, onRecent, lead }: Props) {
  const others = recent.filter((r) => r.categoryId !== currentCategory);
  return (
    <aside className="aside">
      {lead}
      {narrowing && <NarrowingLine narrowing={narrowing} remaining={remaining} />}
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
                  <span className="m">{plate}</span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}
    </aside>
  );
}

function NarrowingLine({ narrowing, remaining }: { narrowing: Async<Narrowing>; remaining: number | null }) {
  if (narrowing.status === 'loading') {
    return (
      <section className="narrow" aria-busy="true" aria-labelledby="narrow-title">
        <h2 id="narrow-title">Affinage</h2>
        <div className="big m skeleton-text" aria-hidden="true">
          000 → 0
        </div>
        <p role="status">Analyse des références du catalogue…</p>
      </section>
    );
  }
  if (narrowing.status !== 'ok') return null;
  const n = narrowing.data;
  const parts = remaining ?? n.clusters.length;
  return (
    <section className="narrow" aria-labelledby="narrow-title">
      <h2 id="narrow-title">Affinage</h2>
      <div className="big m">
        {formatCount(n.totalArticles)} → {parts === 0 ? '?' : parts}
      </div>
      <p>
        {formatCount(n.totalArticles)} références catalogue · {formatCount(n.totalBrands)} marques
        {parts > 0 ? ` → ${parts} pièce${parts > 1 ? 's' : ''}${parts > 1 ? ' distinctes' : ''}` : ''}
      </p>
    </section>
  );
}
