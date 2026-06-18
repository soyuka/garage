import { Button } from '../design/components/Button';
import { Card } from '../design/components/Card';
import type { Cta } from '../types';

/**
 * The agent's call-to-action: a headline, an optional one-line finding, and next-step buttons. Each
 * button re-runs the agent with its `instruction` (the same path a typed message takes) — this is the
 * dashboard's only "what next" affordance, replacing the old bare suggestion chips.
 */
export function CtaCard({
  cta,
  busy,
  onSend,
  onDismiss,
}: {
  cta: Cta;
  busy: boolean;
  onSend: (instruction: string) => void;
  onDismiss: () => void;
}) {
  return (
    <Card className="cta-card">
      <div className="cta-head">
        <div>
          <div className="cta-headline">{cta.headline}</div>
          {cta.finding ? <div className="cta-finding">{cta.finding}</div> : null}
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
              onClick={() => onSend(a.instruction)}
            >
              {a.label}
            </Button>
          ))}
        </div>
      ) : null}
    </Card>
  );
}
