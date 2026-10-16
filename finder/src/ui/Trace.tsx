import type { Question } from '../question';
import { narrowingTrace } from '../trace';
import type { Cluster, Narrowing } from '../types';
import type { Async } from './hooks';

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

export function NarrowingTrace({ narrowing, remaining, cluster }: { narrowing: Async<Narrowing>; remaining: number | null; cluster: Cluster | null }) {
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
        <p className="trace-status" role="status">Lecture des références…</p>
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

// The dimension question built from differsOn: shown above the composer, answered by one click.
// `answered`: the cluster this question settled; a part picked in the variant table leaves it unanswered.
export function QuestionCard({ question, answered, onAnswer }: { question: Question; answered: string | null; onAnswer: (clusterId: string) => void }) {
  return (
    <section className="ask" aria-labelledby="ask-title">
      <h2 id="ask-title" className="sr-only">
        {answered ? 'Réponse' : 'Question'}
      </h2>
      <p className="ask-q">{question.text}</p>
      {question.hint && <p className="ask-hint">{question.hint}</p>}
      <div className="ask-answers" role="group" aria-label="Réponses">
        {question.answers.map((a) => (
          <button key={a.clusterId} type="button" className="btn ans" aria-pressed={answered === a.clusterId} onClick={() => onAnswer(a.clusterId)}>
            {a.label}
          </button>
        ))}
      </div>
    </section>
  );
}
