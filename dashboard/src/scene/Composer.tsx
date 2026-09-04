import type { ReactNode } from 'react';
import { useT } from '../i18n/LocaleProvider';

// The single composer input, extracted out of SceneView (CHOREOGRAPHY § "The agent is on the home
// screen too" — "the composer moves, it is not copied"). Both the hero (via SceneView) and the parc
// landing (Atelier.tsx directly) mount THIS, so there is exactly one input to keep in sync and one
// place the busy/lock rule (`onSubmit` no-ops while busy — see Atelier.tsx `send`) is enforced.

export interface ComposerProps {
  /** Composer focus toggles Le Noyau's listening cue (a local UI event, not a wire frame). */
  onComposerFocus?: (active: boolean) => void;
  /** Submit an instruction (Enter). Live → posts an agent turn; absent → input is inert (pre-agent). */
  onSubmit?: (instruction: string) => void;
  /** Driver-injected controls rendered before the input (the playground's ▶/⚠ sims, the hero's ✕ cancel). */
  composerExtras?: ReactNode;
}

export function Composer({ onComposerFocus, onSubmit, composerExtras }: ComposerProps) {
  const t = useT();

  const handleSubmit = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key !== 'Enter' || !onSubmit) return;
    const value = e.currentTarget.value.trim();
    if (!value) return;
    onSubmit(value);
    e.currentTarget.value = '';
  };

  return (
    <div className="at-scene-composer">
      {composerExtras}
      <input
        className="at-scene-input"
        name="composer"
        aria-label={t.scene.speak}
        placeholder={t.scene.speakPlaceholder}
        onFocus={() => onComposerFocus?.(true)}
        onBlur={() => onComposerFocus?.(false)}
        onKeyDown={handleSubmit}
      />
    </div>
  );
}
