import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { enterIntent, searchCategories } from '../search';
import type { Category } from '../types';

interface Props {
  categories: readonly Category[] | null;
  loading: boolean;
  initial: string;
  onPick: (category: Category) => void;
  onAsk: ((text: string) => void) | null;
  autoFocus?: boolean;
}

export function QueryBox({ categories, loading, initial, onPick, onAsk, autoFocus = false }: Props) {
  const [text, setText] = useState(initial);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const [navigated, setNavigated] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const id = useId();

  useEffect(() => setText(initial), [initial]);
  useEffect(() => {
    if (autoFocus) input.current?.focus();
  }, [autoFocus]);

  const hits = useMemo(() => (categories ? searchCategories(categories, text) : []), [categories, text]);
  const showList = open && text.trim() !== '' && text !== initial;
  const askIndex = onAsk ? hits.length : -1;
  const optionCount = hits.length + (onAsk ? 1 : 0);

  const ask = () => {
    if (!onAsk || text.trim() === '') return;
    setOpen(false);
    input.current?.blur();
    onAsk(text.trim());
  };

  const pick = (c: Category) => {
    setText(c.label);
    setOpen(false);
    onPick(c);
  };

  return (
    <div className="q-box">
      <label htmlFor={`${id}-q`} className="sr-only">
        Quelle pièce ?
      </label>
      <input
        ref={input}
        id={`${id}-q`}
        type="search"
        role="combobox"
        aria-expanded={showList}
        aria-controls={`${id}-list`}
        aria-autocomplete="list"
        aria-activedescendant={showList && active < optionCount ? `${id}-o${active}` : undefined}
        placeholder={loading ? 'Chargement du catalogue…' : onAsk ? 'Quelle pièce, ou quel symptôme ?' : 'Quelle pièce ?'}
        disabled={loading && !categories}
        value={text}
        autoComplete="off"
        spellCheck={false}
        onChange={(e) => {
          setText(e.target.value);
          setActive(0);
          setNavigated(false);
          setOpen(true);
        }}
        onFocus={(e) => {
          e.currentTarget.select();
          setOpen(true);
        }}
        onBlur={() => setOpen(false)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            if (showList && navigated && active === askIndex) return ask();
            const intent = enterIntent(text, hits, active, showList && navigated);
            if (intent.kind === 'pick') pick(intent.category);
            else if (intent.kind === 'ask') {
              if (onAsk) ask();
              else if (hits[active]) pick(hits[active]);
            }
            return;
          }
          if (!showList) return;
          if (e.key === 'ArrowDown') {
            e.preventDefault();
            setNavigated(true);
            setActive((i) => Math.min(i + 1, optionCount - 1));
          } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setNavigated(true);
            setActive((i) => Math.max(i - 1, 0));
          } else if (e.key === 'Escape') {
            setOpen(false);
          }
        }}
      />
      {showList && (
        <ul className="q-list" id={`${id}-list`} role="listbox" aria-label="Catégories de pièces">
          {hits.length === 0 && !onAsk && (
            <li className="q-empty" role="option" aria-selected={false} aria-disabled>
              Aucune catégorie ne correspond à « {text.trim()} ». Essayez un mot plus court : « filtre », « disque »…
            </li>
          )}
          {hits.map((c, i) => (
            <li
              key={c.id}
              id={`${id}-o${i}`}
              role="option"
              aria-selected={i === active}
              onMouseDown={(e) => {
                e.preventDefault();
                pick(c);
              }}
              onMouseEnter={() => setActive(i)}
            >
              <span className="q-label">{c.label}</span>
              <span className="q-path">{c.segments.slice(0, -1).join(' › ')}</span>
            </li>
          ))}
          {onAsk && (
            <li
              id={`${id}-o${askIndex}`}
              role="option"
              className="q-ask"
              aria-selected={active === askIndex}
              onMouseDown={(e) => {
                e.preventDefault();
                ask();
              }}
              onMouseEnter={() => setActive(askIndex)}
            >
              <span className="q-label">Demander : « {text.trim()} »</span>
              <span className="q-path">{hits.length === 0 ? 'Aucune catégorie ne correspond · l’agent cherche la pièce' : 'L’agent trouve la ou les pièces'}</span>
            </li>
          )}
        </ul>
      )}
    </div>
  );
}
