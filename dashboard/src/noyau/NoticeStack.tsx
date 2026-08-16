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
const TONE_LABEL: Record<Tone, string> = { info: 'note', ok: 'fait', warn: 'attention', alarm: 'alerte' };

export function NoticeStack({ notices, reduced, onDismiss }: { notices: Notice[]; reduced?: boolean; onDismiss: (id: string) => void }) {
  if (notices.length === 0) return null;
  return (
    <div className="at-notices" data-reduced={reduced ? 'true' : undefined}>
      {notices.map((n) => (
        <article key={n.id} className="at-notice" data-signal={TONE_SIGNAL[n.tone]}>
          <span className="at-notice-spine" aria-hidden="true" />
          <div className="at-notice-main">
            <span className="at-notice-tone">{TONE_LABEL[n.tone]}</span>
            <p className="at-notice-text">{n.text}</p>
          </div>
          <button type="button" className="at-notice-vu" onClick={() => onDismiss(n.id)} aria-label={`Fermer : ${n.text}`}>
            vu
          </button>
        </article>
      ))}
    </div>
  );
}
