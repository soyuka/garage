import { useT } from '../i18n/LocaleProvider';
import type { CtaAction, Iri, PlannedPart } from '../types';

// The CTA layer : a `proposeActions` frame raises THIS card from Le Noyau's cradle —
// it rises from BEHIND the composer, never over it; declining sinks it back. Each action is dual-shape
// (exactly one payload): `instruction` re-runs the agent (a fresh turn) · `patchTask` is a deterministic
// PATCH merging parts onto a task with NO agent turn (wiring this path is mandatory — skipping it is
// the known dead-button bug class). Never a transcript — the card IS the agent's surfaced proposal.

export interface Cta {
  headline: string;
  finding?: string;
  actions: CtaAction[];
}

interface CtaCardProps {
  cta: Cta | null;
  /** `{instruction}` action → run a new agent turn with that NL follow-up. */
  onInstruction: (instruction: string) => void;
  /** `{patchTask}` action → deterministic parts merge, no turn. */
  onPatch: (patch: { id: Iri; parts: PlannedPart[] }) => void;
  onDismiss: () => void;
  reduced?: boolean;
}

export function CtaCard({ cta, onInstruction, onPatch, onDismiss, reduced }: CtaCardProps) {
  const t = useT();
  if (!cta) return null;
  return (
    <div className="at-cta glass" role="group" aria-label={cta.headline} data-reduced={reduced ? 'true' : undefined}>
      <div className="at-cta-body">
        <p className="at-cta-headline">{cta.headline}</p>
        {cta.finding && <p className="at-cta-finding">{cta.finding}</p>}
      </div>
      <div className="at-cta-actions">
        {cta.actions.map((a, i) => (
          <button
            key={i}
            type="button"
            className={`at-cta-btn${a.patchTask ? ' at-cta-btn--do' : ''}`}
            onClick={() => {
              // exactly one payload is present per the contract; the deterministic patch wins if both.
              if (a.patchTask) onPatch(a.patchTask);
              else if (a.instruction) onInstruction(a.instruction);
            }}
          >
            {a.label}
          </button>
        ))}
        <button type="button" className="at-cta-btn at-cta-btn--decline" onClick={onDismiss} aria-label={t.cta.dismiss}>
          {t.cta.later}
        </button>
      </div>
    </div>
  );
}
