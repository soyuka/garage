import { forwardRef, useCallback, useEffect, useRef, useState, type ClipboardEvent, type DragEvent, type FormEvent } from 'react';
import { TurnRefusedError } from '../agent';
import { formatBytes } from '../format';
import type { Locale } from '../i18n/locale';
import { useLocale, useT } from '../i18n/LocaleProvider';
import type { Messages } from '../i18n/messages';
import { merge, submission } from '../noyau/dictation';
import type { NoyauPhase } from '../noyau/state';
import { useDictation } from '../noyau/useDictation';
import { TURN_ACCEPTED_TYPES, TURN_MAX_FILE_BYTES, TURN_MAX_FILES } from '../types';
import { admitFiles, waitLabel, type DockScope, type FileProblem } from './dock';
import { PHONE, useMedia } from './hooks';
import { Close, FileDoc, Mic, Paperclip, Send, Stop } from './Icons';
import { Orb } from './Orb';
import { orbState } from './orb';
import type { CommandStatus } from './runningAction';

interface DockProps {
  status: CommandStatus;
  scope: DockScope;
  phase: NoyauPhase;
  reduced: boolean;
  /** Resolves once the turn is accepted (202); rejects on a refusal, and the files stay attached. */
  onSubmit: (text: string, files: File[]) => Promise<void> | void;
  onCancel: () => void;
  onListening?: (active: boolean) => void;
  /** `capabilities.agent`: false = no Anthropic key, the input says so and cannot send. */
  agentAvailable?: boolean;
  /** `capabilities.dictation`: false = no STT key, the mic is not rendered. */
  dictationAvailable?: boolean;
}

interface Attachment {
  id: number;
  file: File;
  /** Object URL of an image's thumbnail; revoked whenever the chip goes. */
  thumb: string | null;
}

/** The agent dock: orb, cancel while running and the one input, fixed at the bottom of every screen. */
export const Dock = forwardRef<HTMLInputElement, DockProps>(function Dock({ status, scope, phase, reduced, onSubmit, onCancel, onListening, agentAvailable = true, dictationAvailable = true }, ref) {
  const t = useT();
  const [locale] = useLocale();
  const [text, setText] = useState('');
  const textRef = useRef(text);
  textRef.current = text;
  const [attachments, setAttachments] = useState<Attachment[]>([]);
  const attachmentsRef = useRef(attachments);
  attachmentsRef.current = attachments;
  const [problems, setProblems] = useState<string[]>([]);
  const [dropping, setDropping] = useState(false);
  const picker = useRef<HTMLInputElement>(null);
  const seq = useRef(0);

  useEffect(
    () => () => {
      for (const a of attachmentsRef.current) if (a.thumb) URL.revokeObjectURL(a.thumb);
    },
    [],
  );

  const attach = (incoming: File[]) => {
    if (!agentAvailable || incoming.length === 0) return;
    const { admitted, problems: found } = admitFiles(attachmentsRef.current.length, incoming);
    setProblems(found.map((p) => describeProblem(p, t, locale)));
    if (admitted.length === 0) return;
    const added = admitted.map((file) => ({ id: ++seq.current, file, thumb: file.type.startsWith('image/') ? URL.createObjectURL(file) : null }));
    setAttachments((held) => [...held, ...added]);
  };

  const detach = (gone: readonly File[]) => {
    for (const a of attachmentsRef.current) if (gone.includes(a.file) && a.thumb) URL.revokeObjectURL(a.thumb);
    setAttachments((held) => held.filter((a) => !gone.includes(a.file)));
  };

  const dispatchTurn = (line: string) => {
    const files = attachmentsRef.current.map((a) => a.file);
    setProblems([]);
    Promise.resolve(onSubmit(line, files)).then(
      () => detach(files),
      (err: unknown) => {
        if (err instanceof TurnRefusedError) setProblems([describeRefusal(err, t, locale)]);
      },
    );
  };
  const dispatchRef = useRef(dispatchTurn);
  dispatchRef.current = dispatchTurn;

  const fill = useCallback(
    (spoken: string) => {
      const line = submission(textRef.current, spoken, status.running);
      if (line !== null) {
        setText('');
        dispatchRef.current(line);
        return;
      }
      setText((current) => merge(current, spoken));
    },
    [status.running],
  );
  const dictation = useDictation(fill, status.running);
  const mic = dictation.state.phase;
  const open = mic === 'arming' || mic === 'recording';
  useEffect(() => {
    onListening?.(open);
  }, [open, onListening]);

  const sendable = (text.trim() !== '' || attachments.length > 0) && !status.running && agentAvailable;
  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (!sendable) return;
    dispatchTurn(text.trim());
    setText('');
  };

  const onPaste = (e: ClipboardEvent<HTMLInputElement>) => {
    const files = pastedFiles(e.clipboardData);
    if (files.length === 0) return;
    e.preventDefault();
    attach(files);
  };
  const carriesFiles = (e: DragEvent) => agentAvailable && Array.from(e.dataTransfer?.types ?? []).includes('Files');
  const onDragOver = (e: DragEvent) => {
    if (!carriesFiles(e)) return;
    e.preventDefault();
    setDropping(true);
  };
  const onDrop = (e: DragEvent) => {
    setDropping(false);
    if (!carriesFiles(e)) return;
    e.preventDefault();
    attach(Array.from(e.dataTransfer.files));
  };

  const phone = useMedia(PHONE);
  const orb = orbState(phase, mic);
  return (
    <div className={`w-dock-stack${dropping ? ' is-dropping' : ''}`} onDragOver={onDragOver} onDragLeave={() => setDropping(false)} onDrop={onDrop}>
      {(attachments.length > 0 || problems.length > 0) && (
        <div className="w-dock-files">
          {attachments.length > 0 && (
            <ul className="w-dock-chips" aria-label={t.workshop.command.attachments}>
              {attachments.map((a) => (
                <li key={a.id} className="w-chip w-dock-chip">
                  {a.thumb ? <img className="w-dock-thumb" src={a.thumb} alt="" /> : <FileDoc />}
                  <span className="w-dock-chip-name">{a.file.name}</span>
                  <span className="w-data">{formatBytes(a.file.size, locale)}</span>
                  <button type="button" className="w-icon-button w-dock-chip-remove" aria-label={t.workshop.command.removeAttachment(a.file.name)} title={t.workshop.command.removeAttachment(a.file.name)} onClick={() => detach([a.file])}>
                    <Close />
                  </button>
                </li>
              ))}
            </ul>
          )}
          {problems.map((p, i) => (
            <span key={i} className="w-edit-bill-error" role="alert">
              {p}
            </span>
          ))}
        </div>
      )}
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
            onPaste={onPaste}
            placeholder={agentAvailable ? (phone ? t.workshop.command.scopesShort : t.workshop.command.scopes)[scope] : t.workshop.command.unavailable}
            title={agentAvailable ? undefined : t.workshop.command.unavailable}
            disabled={!agentAvailable}
            autoComplete="off"
            spellCheck={false}
          />
          <kbd className="w-kbd w-data" aria-hidden="true">
            /
          </kbd>
        </label>
        <button type="button" className="w-icon-button w-dock-attach" aria-label={t.workshop.command.attach} title={t.workshop.command.attach} disabled={!agentAvailable} onClick={() => picker.current?.click()}>
          <Paperclip />
        </button>
        <input
          ref={picker}
          type="file"
          accept={TURN_ACCEPTED_TYPES.join(',')}
          multiple
          hidden
          onChange={(e) => {
            attach(Array.from(e.target.files ?? []));
            e.target.value = '';
          }}
        />
        {dictationAvailable && agentAvailable && !dictation.state.unavailable && (
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
        <button type="submit" className="w-icon-button w-dock-send" aria-label={t.workshop.command.send} title={t.workshop.command.send} disabled={!sendable}>
          <Send />
        </button>
      </form>
    </div>
  );
});

/** Files on the clipboard (a screenshot, a copied photo); a text paste yields none and stays native. */
function pastedFiles(data: DataTransfer | null): File[] {
  if (!data) return [];
  const files = Array.from(data.files ?? []);
  if (files.length > 0) return files;
  return Array.from(data.items ?? [])
    .filter((item) => item.kind === 'file')
    .map((item) => item.getAsFile())
    .filter((f): f is File => f !== null);
}

function describeProblem(problem: FileProblem, t: Messages, locale: Locale): string {
  const c = t.workshop.command;
  switch (problem.kind) {
    case 'type':
      return c.attachType(problem.name);
    case 'size':
      return c.attachSize(problem.name, formatBytes(TURN_MAX_FILE_BYTES, locale));
    case 'count':
      return c.attachCount(TURN_MAX_FILES);
  }
}

function describeRefusal(err: TurnRefusedError, t: Messages, locale: Locale): string {
  const r = t.workshop.command.refused;
  switch (err.kind) {
    case 'tooLarge':
      return r.tooLarge(formatBytes(TURN_MAX_FILE_BYTES, locale));
    case 'unsupported':
      return r.unsupported;
    case 'invalid':
      return r.invalid;
    case 'rateLimited':
      return r.rateLimited(waitLabel(err.retryAfter));
  }
}
