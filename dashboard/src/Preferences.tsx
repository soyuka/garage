import { useLocale, useT } from './i18n/LocaleProvider';
import type { Locale } from './i18n/locale';

// The operator's two STANDING preferences: which language the Atelier speaks, and whether it speaks
// out loud. Both persist under `mpa.*`, both belong to the person rather than to the moment.
//
// They live in the top-right chrome, not in the à-décider inbox where the audition toggle started.
// The inbox is gated on `queue.length > 0` (Noyau.tsx) and force-closes when the queue drains
// (SceneView.tsx), so a control inside it does not exist at rest — the mute could only be reached by
// having a pending decision, which is exactly backwards for a preference, and left the audition with
// no manual reset at all (MIL-STD-1472F §7.2.9.11, cited in useVoice.ts as non-optional).

/** Speaker on/off, mirroring the glyph the inbox used so the affordance still reads the same. */
function AuditionGlyph({ on }: { on: boolean }) {
  return (
    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M3 6.2h2.4L8.6 3.4v9.2L5.4 9.8H3z" />
      {on ? <path d="M10.8 6a2.8 2.8 0 0 1 0 4M12.6 4.4a5.2 5.2 0 0 1 0 7.2" /> : <path d="M10.8 6.4l3.2 3.2M14 6.4l-3.2 3.2" />}
    </svg>
  );
}

/**
 * Read the answers aloud. Ships muted and stays a deliberate act: `aria-pressed` carries the state so
 * a screen reader hears it as the two-state control it is, not as a button that did something.
 */
export function AuditionToggle({ on, onToggle }: { on: boolean; onToggle: () => void }) {
  const t = useT();

  return (
    <button
      type="button"
      className="at-identity-chip"
      onClick={onToggle}
      aria-pressed={on ? 'true' : 'false'}
      title={on ? t.inbox.muteVoice : t.inbox.unmuteVoice}
      aria-label={on ? t.inbox.muteVoice : t.inbox.unmuteVoice}
    >
      <AuditionGlyph on={on} />
      <span className="at-pref-label">{on ? t.inbox.voiceOn : t.inbox.voiceOff}</span>
    </button>
  );
}

// Each title is written in the language it switches TO — the one string in the app that must not
// follow the current locale, because it is the way out of a language you cannot read.
const LOCALE_TITLE: Record<Locale, string> = {
  fr: 'Afficher l’Atelier en français',
  en: 'Show the Atelier in English',
};

/** Two languages, so a toggle rather than a menu. The label names the language you would GET. */
export function LocaleToggle() {
  const [locale, setLocale] = useLocale();
  const other: Locale = 'fr' === locale ? 'en' : 'fr';

  return (
    <button
      type="button"
      className="at-identity-chip"
      onClick={() => setLocale(other)}
      title={LOCALE_TITLE[other]}
      aria-label={LOCALE_TITLE[other]}
    >
      <span className="at-pref-label">{other.toUpperCase()}</span>
    </button>
  );
}
