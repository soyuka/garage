import { useLocale, useT } from './i18n/LocaleProvider';
import type { Locale } from './i18n/locale';
import type { SpeechVoice } from './types';

// The operator's STANDING preferences: which language the Atelier speaks, and which voice reads it
// (both audition and cues are always on — no manual mute, 2026-09-11). They live in the top-right
// chrome.

// Each title is written in the language it switches TO — the one string in the app that must not
// follow the current locale, because it is the way out of a language you cannot read.
const LOCALE_TITLE: Record<Locale, string> = {
  fr: 'Afficher l’Atelier en français',
  en: 'Show the Atelier in English',
};

/**
 * The four-ish ElevenLabs voices, chosen from the UI (CHOREOGRAPHY § "the voice-select control",
 * 2026-09-06). A native `<select>`, not a menu of chips — the list is server-driven and its length is
 * not this component's business. Rendered only while audition is on and only when the fetched list is
 * non-empty; both calls are the caller's (`Identity`, Atelier.tsx), since only it knows both things.
 */
export function VoiceSelect({ voices, value, onChange }: { voices: SpeechVoice[]; value: string | null; onChange: (id: string | null) => void }) {
  const t = useT();

  return (
    <select
      className="at-identity-chip"
      aria-label={t.pref.voice}
      title={t.pref.voice}
      value={value ?? ''}
      onChange={(e) => onChange('' === e.target.value ? null : e.target.value)}
    >
      {/* The empty value IS null — the server default, never a voice id of its own. */}
      <option value="">{t.pref.voiceDefault}</option>
      {voices.map((v) => (
        <option key={v.id} value={v.id}>
          {v.label}
        </option>
      ))}
    </select>
  );
}

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
