import type { SpeechVoice } from '../types';

// The operator's ElevenLabs voice choice — pure core, mirroring `useAudition`'s storage pattern
// (useVoice.ts) so the two persisted preferences read the same way. `null` is not "no preference
// recorded", it is a positive choice: the server default.

/** Reserved beside `mpa.audition`, `mpa.recency` and `mpa.locale` — one namespace for the operator's kit. */
export const VOICE_KEY = 'mpa.voice';

/** Tolerant of a storage that throws (private mode / storage denied) — never a crash, just no memory. */
export function readVoicePreference(storage: Storage): string | null {
  try {
    return storage.getItem(VOICE_KEY);
  } catch {
    return null;
  }
}

/** `null` clears the preference (back to the server default); tolerant of a throwing storage. */
export function writeVoicePreference(storage: Storage, id: string | null): void {
  try {
    if (null === id) storage.removeItem(VOICE_KEY);
    else storage.setItem(VOICE_KEY, id);
  } catch {
    /* the preference simply does not persist */
  }
}

/**
 * The preference against the LIVE list: a voice id the server no longer offers (retired, or a
 * different backend since the id was saved) must fall back to the server default rather than 400,
 * which is why `resolveVoice` server-side is equally tolerant (`SpeechController.php`) — but the
 * client should not even ask for a voice it knows is gone.
 */
export function applyVoice(voices: SpeechVoice[], preferred: string | null): string | null {
  if (null === preferred) return null;
  return voices.some((v) => v.id === preferred) ? preferred : null;
}
