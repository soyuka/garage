import { useEffect, useId, useMemo, useRef, useState, type ClipboardEvent, type DragEvent, type FormEvent, type ReactNode } from 'react';
import { HOME } from '../home';
import { routeText, type TextRoute } from '../route-input';
import type { Category } from '../types';
import { useMedia } from './hooks';

interface Props {
  variant: 'dock' | 'home';
  categories: readonly Category[] | null;
  loading?: boolean;
  initial: string;
  context?: string | null;
  busy?: boolean;
  autoFocus?: boolean;
  plateRequest?: number;
  focusRequest?: number;
  labelRequest?: number;
  hasVehicle?: boolean;
  token?: ReactNode;
  // Under the field, before the hint: the result's actions in the dock.
  below?: ReactNode;
  attachments?: readonly File[];
  onText: (route: TextRoute, typed: string) => void | Promise<boolean>;
  onAttach: (files: File[]) => void;
  onDetach?: (index: number) => void;
}

const PLATE_PLACEHOLDER = 'Plaque : AB-123-CD';
const ACCEPT = 'image/*,video/*,application/pdf';

export function routeHint(route: TextRoute, hasVehicle: boolean): string {
  switch (route.kind) {
    case 'empty':
      return '';
    case 'plate':
      return route.format === 'siv' ? 'Plaque SIV' : 'Plaque FNI (ancien format)';
    case 'vin':
      return 'VIN';
    case 'reference':
      return 'Référence de pièce';
    case 'text':
      return hasVehicle ? 'Recherche libre' : 'Recherche libre : précisez marque, modèle et moteur.';
  }
}

function PaperclipIcon() {
  return (
    <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <path d="M13.5 6.5 7.6 12.4a1.6 1.6 0 0 0 2.3 2.3l6.2-6.2a3.2 3.2 0 0 0-4.5-4.5L5.3 10.3a4.8 4.8 0 0 0 6.8 6.8l4.4-4.4" />
    </svg>
  );
}

function CameraIcon() {
  return (
    <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinejoin="round">
      <path d="M2.5 6.5h3l1.5-2h6l1.5 2h3v9h-15z" />
      <circle cx="10" cy="11" r="3" />
    </svg>
  );
}

function Chips({ files, disabled, onDetach }: { files: readonly File[]; disabled: boolean; onDetach?: (index: number) => void }) {
  const previews = useMemo(() => files.map((f) => (f.type.startsWith('image/') ? URL.createObjectURL(f) : null)), [files]);
  useEffect(() => () => previews.forEach((u) => u && URL.revokeObjectURL(u)), [previews]);
  const [leaving, setLeaving] = useState<File | null>(null);
  const detach = (i: number) => {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    setLeaving(files[i] ?? null);
    window.setTimeout(() => {
      setLeaving(null);
      onDetach?.(i);
    }, reduced ? 0 : 140);
  };
  return (
    <ul className="chips" aria-label="Fichiers joints">
      {files.map((f, i) => (
        <li key={`${f.name}-${i}`} className="chip-file" data-leaving={f === leaving || undefined}>
          {previews[i] ? <img src={previews[i]!} alt="" width="20" height="20" /> : <span className="chip-kind m" aria-hidden="true">{f.type === 'application/pdf' ? 'PDF' : 'VID'}</span>}
          <span className="chip-file-name">{f.name}</span>
          <button type="button" className="ctx-remove" disabled={disabled} aria-label={`Retirer ${f.name}`} title="Retirer le fichier" onClick={() => detach(i)}>
            ×
          </button>
        </li>
      ))}
    </ul>
  );
}

export function Intake({ variant, categories, loading = false, initial, context = null, busy = false, autoFocus = false, plateRequest = 0, focusRequest = 0, labelRequest = 0, hasVehicle = categories !== null, token = null, below = null, attachments = [], onText, onAttach, onDetach }: Props) {
  const [text, setText] = useState(initial);
  const [over, setOver] = useState(false);
  const [plateHint, setPlateHint] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const picker = useRef<HTMLInputElement>(null);
  const camera = useRef<HTMLInputElement>(null);
  const touch = useMedia('(pointer: coarse)');
  const id = useId();

  useEffect(() => setText(initial), [initial]);
  useEffect(() => {
    if (autoFocus) input.current?.focus();
  }, [autoFocus]);
  useEffect(() => {
    if (plateRequest === 0) return;
    setPlateHint(true);
    input.current?.focus();
  }, [plateRequest]);
  useEffect(() => {
    if (focusRequest === 0) return;
    input.current?.focus();
  }, [focusRequest]);
  // Still inside the click's user activation, so the browser lets the picker open.
  useEffect(() => {
    if (labelRequest === 0) return;
    (touch ? camera : picker).current?.click();
  }, [labelRequest]);

  const route = useMemo(() => routeText(text), [text, categories]);
  const hint = context ?? routeHint(route, hasVehicle);

  const attached = attachments.length > 0;
  const submit = async () => {
    if ((route.kind === 'empty' && !attached) || busy) return;
    const typed = text.trim();
    setText('');
    // false: the submit stopped (unreadable file, unknown plate): the typed text stays for the next press.
    if ((await onText(route, typed)) === false) setText((current) => (current === '' ? typed : current));
  };

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    void submit();
  };

  const files = (list: FileList | null | undefined) => {
    const picked = list ? Array.from(list) : [];
    if (picked.length > 0 && !busy) onAttach(picked);
  };

  const onPaste = (e: ClipboardEvent<HTMLInputElement>) => {
    if (e.clipboardData.files.length === 0) return;
    e.preventDefault();
    files(e.clipboardData.files);
  };

  const drag = (e: DragEvent, on: boolean) => {
    if (!Array.from(e.dataTransfer.types).includes('Files')) return;
    e.preventDefault();
    setOver(on);
  };

  return (
    <form
      className={`intake intake--${variant}`}
      data-over={over}
      data-busy={busy}
      onSubmit={onSubmit}
      onDragOver={(e) => drag(e, true)}
      onDragLeave={(e) => drag(e, false)}
      onDrop={(e) => {
        drag(e, false);
        if (e.dataTransfer.files.length > 0) {
          e.preventDefault();
          files(e.dataTransfer.files);
        }
      }}
      noValidate
      role="search"
    >
      <label htmlFor={`${id}-q`} className="sr-only">
        Plaque, VIN ou pièce
      </label>
      <div className="intake-field">
        {token}
        {attached && <Chips files={attachments} disabled={busy} onDetach={onDetach} />}
        <input
          ref={input}
          id={`${id}-q`}
          type="search"
          aria-describedby={`${id}-hint`}
          placeholder={plateHint ? PLATE_PLACEHOLDER : loading ? 'Chargement des catégories…' : hasVehicle ? 'filtre à huile, W 712/73…' : HOME.placeholder}
          value={text}
          autoComplete="off"
          autoCapitalize="off"
          spellCheck={false}
          enterKeyHint="search"
          onChange={(e) => setText(e.target.value)}
          onBlur={() => setPlateHint(false)}
          onPaste={onPaste}
        />
        <input ref={picker} type="file" accept={ACCEPT} multiple className="sr-only" tabIndex={-1} aria-hidden="true" onChange={(e) => (files(e.target.files), (e.target.value = ''))} />
        <input
          ref={camera}
          type="file"
          accept="image/*,video/*"
          capture="environment"
          className="sr-only"
          tabIndex={-1}
          aria-hidden="true"
          onChange={(e) => (files(e.target.files), (e.target.value = ''))}
        />
        {touch && (
          <button type="button" className="intake-icon" onClick={() => camera.current?.click()} disabled={busy} aria-label="Prendre une photo ou une vidéo">
            <CameraIcon />
          </button>
        )}
        <button type="button" className="intake-icon" onClick={() => picker.current?.click()} disabled={busy} aria-label="Joindre une photo, une vidéo ou un PDF" title="Joindre une photo, une vidéo ou un PDF">
          <PaperclipIcon />
        </button>
        <button type="submit" className="btn pri intake-go" disabled={(route.kind === 'empty' && !attached) || busy}>
          {busy ? 'Lecture…' : 'Chercher'}
        </button>
      </div>
      {below}
      <p id={`${id}-hint`} className="intake-hint" aria-live="polite">
        {over ? 'Déposez le fichier.' : hint}
      </p>
    </form>
  );
}
