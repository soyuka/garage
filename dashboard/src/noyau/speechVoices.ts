import { API, getToken } from '../auth';
import { currentLocale } from '../i18n/locale';
import type { SpeechVoice } from '../types';

// The picker's source list. Same posture as `fetchTts` (useVoice.ts): a garnish feature must never
// seize the session, so this is a raw `fetch`, deliberately NOT `authFetch` — a 401 here must stay
// silent, never bounce to login.

/**
 * `GET /tts/voices` for the interface's current language, first entry the backend's default.
 * Never throws: a network error, a non-2xx or an unparsable body all resolve to `[]` — the caller
 * hides the picker rather than rendering a broken one.
 */
export async function fetchVoices(): Promise<SpeechVoice[]> {
  try {
    const token = getToken();
    const res = await fetch(`${API}/tts/voices`, {
      headers: {
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        'Accept-Language': currentLocale(),
      },
    });
    if (!res.ok) return [];

    const body: unknown = await res.json();
    if (!Array.isArray(body)) return [];

    return body.filter(
      (v): v is SpeechVoice => null !== v && 'object' === typeof v && 'string' === typeof v.id && 'string' === typeof v.label,
    );
  } catch {
    return [];
  }
}
