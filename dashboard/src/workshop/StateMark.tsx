import { useT } from '../i18n/LocaleProvider';

export type MarkState = 'done' | 'planned' | 'due' | 'over' | 'unknown';

/** The shape half of a state: filled = done, hollow = planned/due, doubled + struck = overdue. */
export function StateShape({ state, size = 10 }: { state: MarkState; size?: number }) {
  return (
    <svg className={`w-shape w-shape--${state}`} width={size} height={size} viewBox="0 0 10 10" aria-hidden="true" focusable="false">
      {state === 'done' && <rect x="0.5" y="0.5" width="9" height="9" fill="currentColor" />}
      {(state === 'planned' || state === 'due') && <rect x="1" y="1" width="8" height="8" fill="none" stroke="currentColor" strokeWidth="1.5" />}
      {state === 'unknown' && <rect x="1" y="1" width="8" height="8" fill="none" stroke="currentColor" strokeWidth="1" strokeDasharray="2 1.5" />}
      {state === 'over' && (
        <>
          <rect x="0.75" y="0.75" width="8.5" height="8.5" fill="none" stroke="currentColor" strokeWidth="1.5" />
          <rect x="3" y="3" width="4" height="4" fill="none" stroke="currentColor" strokeWidth="1.25" />
          <path d="M0.75 9.25L9.25 0.75" stroke="currentColor" strokeWidth="1.25" />
        </>
      )}
    </svg>
  );
}

/** Shape + word, never one without the other. `text` overrides the default state word. */
export function StateMark({ state, text, immediate = false }: { state: MarkState; text?: string; immediate?: boolean }) {
  const t = useT();
  const word = text ?? (immediate ? t.workshop.state.now : t.workshop.state[state]);
  return (
    <span className={`w-mark w-mark--${state}`}>
      <StateShape state={state} />
      <span className="w-mark-word">{word}</span>
    </span>
  );
}
