import { forwardRef, useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { useT } from '../i18n/LocaleProvider';
import { merge, submission } from '../noyau/dictation';
import type { NoyauPhase } from '../noyau/state';
import { useDictation } from '../noyau/useDictation';
import type { DockScope } from './dock';
import { PHONE, useMedia } from './hooks';
import { Mic, Send, Stop } from './Icons';
import { Orb } from './Orb';
import { orbState } from './orb';
import type { CommandStatus } from './runningAction';

interface DockProps {
  status: CommandStatus;
  scope: DockScope;
  phase: NoyauPhase;
  reduced: boolean;
  onSubmit: (text: string) => void;
  onCancel: () => void;
  onListening?: (active: boolean) => void;
}

/** The agent dock: orb, cancel while running and the one input, fixed at the bottom of every screen. */
export const Dock = forwardRef<HTMLInputElement, DockProps>(function Dock({ status, scope, phase, reduced, onSubmit, onCancel, onListening }, ref) {
  const t = useT();
  const [text, setText] = useState('');
  const textRef = useRef(text);
  textRef.current = text;

  const fill = useCallback(
    (spoken: string) => {
      const line = submission(textRef.current, spoken, status.running);
      if (line !== null) {
        setText('');
        onSubmit(line);
        return;
      }
      setText((current) => merge(current, spoken));
    },
    [onSubmit, status.running],
  );
  const dictation = useDictation(fill, status.running);
  const mic = dictation.state.phase;
  const open = mic === 'arming' || mic === 'recording';
  useEffect(() => {
    onListening?.(open);
  }, [open, onListening]);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const value = text.trim();
    if (!value || status.running) return;
    onSubmit(value);
    setText('');
  };

  const phone = useMedia(PHONE);
  const orb = orbState(phase, mic);
  return (
    <form className={`w-dock${status.running ? ' is-running' : ''}`} onSubmit={submit}>
      <Orb state={orb} reduced={reduced} />
      {status.running && status.canCancel && (
        <button type="button" className="w-button w-cmd-cancel" onClick={onCancel}>
          <Stop />
          {t.workshop.command.cancel}
        </button>
      )}
      <label className="w-dock-field">
        <span className="w-visually-hidden">{t.workshop.command.label}</span>
        <input
          ref={ref}
          className="w-dock-input"
          type="text"
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder={(phone ? t.workshop.command.scopesShort : t.workshop.command.scopes)[scope]}
          autoComplete="off"
          spellCheck={false}
        />
        <kbd className="w-kbd w-data" aria-hidden="true">
          /
        </kbd>
      </label>
      {!dictation.state.unavailable && (
        <button
          type="button"
          className={`w-icon-button w-dock-mic${open ? ' is-open' : ''}`}
          data-phase={mic}
          aria-label={open ? t.workshop.command.dictating : t.workshop.command.dictate}
          title={open ? t.workshop.command.dictating : t.workshop.command.dictate}
          aria-pressed={open}
          disabled={status.running || mic === 'transcribing'}
          onClick={dictation.toggle}
        >
          {open ? <Stop /> : <Mic />}
        </button>
      )}
      <button type="submit" className="w-icon-button w-dock-send" aria-label={t.workshop.command.send} title={t.workshop.command.send} disabled={!text.trim() || status.running}>
        <Send />
      </button>
    </form>
  );
});
