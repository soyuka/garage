import { useT } from '../i18n/LocaleProvider';
import { whenLabel } from '../noyau/age';
import { NOTICE_CAP, type Notice } from '../noyau/state';
import type { Tone } from '../types';
import { StateMark, type MarkState } from './StateMark';

const TONE_MARK: Partial<Record<Tone, MarkState>> = { alarm: 'over', warn: 'due' };

export function Ticker({ narration, speaking }: { narration: string | null; speaking: boolean }) {
  if (narration === null) return null;
  return (
    <p className={`w-ticker${speaking ? ' is-speaking' : ''}`} title={narration} aria-live="polite">
      {narration}
    </p>
  );
}

export function Notices({ notices, speakingId, onDismiss }: { notices: Notice[]; speakingId: string | null; onDismiss: (id: string) => void }) {
  const t = useT();
  if (notices.length === 0) return null;
  return (
    <div className="w-notices">
      {notices.slice(-NOTICE_CAP).map((n) => {
        const mark = TONE_MARK[n.tone];
        return (
          <article key={n.id} className={`w-notice${speakingId === n.id ? ' is-speaking' : ''}`} role={n.tone === 'alarm' ? 'alert' : undefined}>
            <p className="w-notice-text">{n.text}</p>
            <div className="w-notice-foot">
              {mark && <StateMark state={mark} text={t.notice.tone[n.tone]} />}
              <span className="w-data w-notice-when">{whenLabel(n.at, t)}</span>
              <button type="button" className="w-button w-notice-vu" onClick={() => onDismiss(n.id)} aria-label={t.notice.dismiss(n.text)}>
                {t.notice.seen}
              </button>
            </div>
          </article>
        );
      })}
    </div>
  );
}
