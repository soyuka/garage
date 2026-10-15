import { useEffect, useId, useMemo, useRef, useState, type ClipboardEvent, type DragEvent, type FormEvent } from 'react';
import { HOME } from '../home';
import { routeText, type TextRoute } from '../route-input';
import { searchCategories } from '../search';
import type { Category } from '../types';
import { useMedia } from './hooks';

interface Props {
  variant: 'dock';
  categories: readonly Category[] | null;
  loading?: boolean;
  initial: string;
  context?: string | null;
  busy?: boolean;
  autoFocus?: boolean;
  hasVehicle?: boolean;
  onText: (route: TextRoute, typed: string) => void;
  onFiles: (files: File[]) => void;
}

const ACCEPT = 'image/*,video/*,application/pdf';

export function routeHint(route: TextRoute, hasVehicle: boolean): string {
  switch (route.kind) {
    case 'empty':
      return 'Photo ou PDF : collez ou glissez le fichier ici.';
    case 'plate':
      return route.format === 'siv' ? 'Plaque SIV' : 'Plaque FNI (ancien format)';
    case 'vin':
      return 'VIN';
    case 'reference':
      return 'Référence de pièce';
    case 'category':
      return `Catégorie : ${route.category.label}`;
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

export function Intake({ variant, categories, loading = false, initial, context = null, busy = false, autoFocus = false, hasVehicle = categories !== null, onText, onFiles }: Props) {
  const [text, setText] = useState(initial);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const [navigated, setNavigated] = useState(false);
  const [over, setOver] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const picker = useRef<HTMLInputElement>(null);
  const camera = useRef<HTMLInputElement>(null);
  const touch = useMedia('(pointer: coarse)');
  const id = useId();

  useEffect(() => setText(initial), [initial]);
  useEffect(() => {
    if (autoFocus) input.current?.focus();
  }, [autoFocus]);

  const route = useMemo(() => routeText(text, categories), [text, categories]);
  const textual = route.kind === 'text' || route.kind === 'category';
  const hits = useMemo(() => (categories && textual ? searchCategories(categories, text) : []), [categories, text, textual]);
  const showList = open && textual && text.trim() !== '' && text !== initial;
  const askIndex = hits.length;
  const optionCount = hits.length + 1;
  const hint = context ?? routeHint(route, hasVehicle);

  const submit = (picked?: TextRoute) => {
    const r = picked ?? route;
    if (r.kind === 'empty' || busy) return;
    setOpen(false);
    setText('');
    onText(r, text.trim());
  };

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (showList && navigated) {
      if (active === askIndex) return submit({ kind: 'text', text: text.trim() });
      const c = hits[active];
      if (c) return submit({ kind: 'category', category: c });
    }
    submit();
  };

  const files = (list: FileList | null | undefined) => {
    const picked = list ? Array.from(list) : [];
    if (picked.length > 0 && !busy) onFiles(picked);
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
        <input
          ref={input}
          id={`${id}-q`}
          type="search"
          role="combobox"
          aria-expanded={showList}
          aria-controls={`${id}-list`}
          aria-autocomplete="list"
          aria-describedby={`${id}-hint`}
          aria-activedescendant={showList && navigated && active < optionCount ? `${id}-o${active}` : undefined}
          placeholder={loading ? 'Chargement des catégories…' : hasVehicle ? 'filtre à huile, W 712/73…' : HOME.placeholder}
          value={text}
          autoComplete="off"
          autoCapitalize="off"
          spellCheck={false}
          enterKeyHint="search"
          onChange={(e) => {
            setText(e.target.value);
            setActive(0);
            setNavigated(false);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          onBlur={() => setOpen(false)}
          onPaste={onPaste}
          onKeyDown={(e) => {
            if (!showList) return;
            if (e.key === 'ArrowDown') {
              e.preventDefault();
              setNavigated(true);
              setActive((i) => (navigated ? Math.min(i + 1, optionCount - 1) : 0));
            } else if (e.key === 'ArrowUp') {
              e.preventDefault();
              setNavigated(true);
              setActive((i) => Math.max(i - 1, 0));
            } else if (e.key === 'Escape') {
              setOpen(false);
            }
          }}
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
        <button type="submit" className="btn pri intake-go" disabled={route.kind === 'empty' || busy}>
          {busy ? 'Lecture…' : 'Chercher'}
        </button>
      </div>
      <p id={`${id}-hint`} className="intake-hint" aria-live="polite">
        {over ? 'Déposez le fichier.' : hint}
      </p>
      {showList && (
        <ul className="q-list" id={`${id}-list`} role="listbox" aria-label="Catégories de pièces">
          {hits.map((c, i) => (
            <li
              key={c.id}
              id={`${id}-o${i}`}
              role="option"
              aria-selected={navigated && i === active}
              onMouseDown={(e) => {
                e.preventDefault();
                submit({ kind: 'category', category: c });
              }}
              onMouseEnter={() => (setNavigated(true), setActive(i))}
            >
              <span className="q-label">{c.label}</span>
              <span className="q-path">{c.segments.slice(0, -1).join(' › ')}</span>
            </li>
          ))}
          <li
            id={`${id}-o${askIndex}`}
            role="option"
            className="q-ask"
            aria-selected={navigated && active === askIndex}
            onMouseDown={(e) => {
              e.preventDefault();
              submit({ kind: 'text', text: text.trim() });
            }}
            onMouseEnter={() => (setNavigated(true), setActive(askIndex))}
          >
            <span className="q-label">Recherche libre : « {text.trim()} »</span>
            {hits.length === 0 && <span className="q-path">Aucune catégorie de ce nom</span>}
          </li>
        </ul>
      )}
    </form>
  );
}
