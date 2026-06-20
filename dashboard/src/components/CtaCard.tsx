import { Button } from '../design/components/Button';
import { Card } from '../design/components/Card';
import { Scramble } from './Scramble';
import type { Cta, CtaAction } from '../types';

/**
 * The agent's call-to-action: a headline, an optional one-line finding, and next-step buttons. A
 * button does ONE of two things: a structured write (`patchTask`) merges parts onto a task with a
 * direct PATCH — no agent turn — while a plain `instruction` re-runs the agent (the same path a typed
 * message takes). Prefer the write path for "add this reference" choices: the click takes effect
 * immediately and the checklist's shopping list re-renders, instead of bouncing through a fresh turn.
 */
export function CtaCard({
  cta,
  busy,
  onSend,
  onAddParts,
  onDismiss,
}: {
  cta: Cta;
  busy: boolean;
  onSend: (instruction: string) => void;
  onAddParts: (patchTask: NonNullable<CtaAction['patchTask']>) => void;
  onDismiss: () => void;
}) {
  return (
    <Card className="cta-card">
      <div className="cta-head">
        <div>
          <div className="cta-headline">
            <Scramble text={cta.headline} />
          </div>
          {cta.finding ? (
            <div className="cta-finding">
              <Scramble text={cta.finding} delay={120} />
            </div>
          ) : null}
        </div>
        <button type="button" className="fact-panel-close" aria-label="Fermer" onClick={onDismiss}>
          ×
        </button>
      </div>
      {cta.actions.length > 0 ? (
        <div className="cta-actions">
          {cta.actions.map((a, i) => (
            <Button
              key={`${a.label}-${i}`}
              variant={0 === i ? 'primary' : 'secondary'}
              size="sm"
              disabled={busy}
              onClick={() => (a.patchTask ? onAddParts(a.patchTask) : a.instruction ? onSend(a.instruction) : undefined)}
            >
              {a.label}
            </Button>
          ))}
        </div>
      ) : null}
    </Card>
  );
}
