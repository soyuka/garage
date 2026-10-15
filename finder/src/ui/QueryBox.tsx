import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { searchCategories } from '../search';
import type { Category } from '../types';

interface Props {
  categories: readonly Category[] | null;
  loading: boolean;
  initial: string;
  onPick: (category: Category) => void;
  autoFocus?: boolean;
}

export function QueryBox({ categories, loading, initial, onPick, autoFocus = false }: Props) {
  const [text, setText] = useState(initial);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const input = useRef<HTMLInputElement>(null);
  const id = useId();

  useEffect(() => setText(initial), [initial]);
  useEffect(() => {
    if (autoFocus) input.current?.focus();
  }, [autoFocus]);

  const hits = useMemo(() => (categories ? searchCategories(categories, text) : []), [categories, text]);
  const showList = open && text.trim() !== '' && text !== initial;

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
        aria-activedescendant={showList && hits[active] ? `${id}-o${active}` : undefined}
        placeholder={loading ? 'Chargement du catalogue…' : 'Quelle pièce ?'}
        disabled={loading && !categories}
        value={text}
        autoComplete="off"
        spellCheck={false}
        onChange={(e) => {
          setText(e.target.value);
          setActive(0);
          setOpen(true);
        }}
        onFocus={(e) => {
          e.currentTarget.select();
          setOpen(true);
        }}
        onBlur={() => setOpen(false)}
        onKeyDown={(e) => {
          if (!showList) return;
          if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => Math.min(i + 1, hits.length - 1));
          } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => Math.max(i - 1, 0));
          } else if (e.key === 'Enter' && hits[active]) {
            e.preventDefault();
            pick(hits[active]);
          } else if (e.key === 'Escape') {
            setOpen(false);
          }
        }}
      />
      {showList && (
        <ul className="q-list" id={`${id}-list`} role="listbox" aria-label="Catégories de pièces">
          {hits.length === 0 ? (
            <li className="q-empty" role="option" aria-selected={false} aria-disabled>
              Aucune catégorie ne correspond à « {text.trim()} ». Essayez un mot plus court : « filtre », « disque »…
            </li>
          ) : (
            hits.map((c, i) => (
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
            ))
          )}
        </ul>
      )}
    </div>
  );
}
