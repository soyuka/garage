import type { Question } from '../question';

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
