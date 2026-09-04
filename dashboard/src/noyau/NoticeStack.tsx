import { useT } from '../i18n/LocaleProvider';
import { whenLabel } from './age';
import type { Notice } from './state';
import type { Signal, Tone } from '../types';
import './notices.css';

// The retained toast cards (CHOREOGRAPHY § "The notice stack — an answer that stays"). They rise above
// the console, newest closest to the orb, and stay until the operator says "vu" — no timer, no fade.
//
// NOT a live region: a non-alarm toast is already announced once on Le Noyau's narration line and an
// alarm is already a row in the à-décider inbox, so announcing the card too would be the second
// announcement the tool-step-chip rule forbids. A screen reader still reaches the cards by browsing.

// Severity reads off the SAME data-signal resolution every other surface uses, plus a mono label —
// never colour alone. `info` has no severity, so it takes the neutral 'unknown' ramp rather than
// borrowing green: "I have nothing to report" and "this is healthy" are different claims.
const TONE_SIGNAL: Record<Tone, Signal | 'unknown'> = { info: 'unknown', ok: 'green', warn: 'amber', alarm: 'red' };

export function NoticeStack({ notices, reduced, onDismiss }: { notices: Notice[]; reduced?: boolean; onDismiss: (id: string) => void }) {
  const t = useT();
  if (notices.length === 0) return null;
  return (
    <div className="at-notices" data-reduced={reduced ? 'true' : undefined}>
      {notices.map((n) => (
        <article key={n.id} className="at-notice" data-signal={TONE_SIGNAL[n.tone]}>
          <span className="at-notice-spine" aria-hidden="true" />
          <div className="at-notice-main">
            <span className="at-notice-tone">{t.notice.tone[n.tone]}</span>
            <p className="at-notice-text">{n.text}</p>
            {/* The Data panel is authoritative; this card is what was said at `n.at`. The stamp is what
                lets the two be on screen together and disagree LEGIBLY (CHOREOGRAPHY § Audition). */}
            <span className="at-notice-stamp">{t.notice.stamp(whenLabel(n.at, t))}</span>
          </div>
          <button type="button" className="at-notice-vu" onClick={() => onDismiss(n.id)} aria-label={t.notice.dismiss(n.text)}>
            {t.notice.seen}
          </button>
        </article>
      ))}
    </div>
  );
}
