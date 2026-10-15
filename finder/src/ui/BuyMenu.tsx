import { useEffect, useId, useRef, useState } from 'react';
import { buyLinks } from '../result';

export function BuyMenu({ reference }: { reference: string }) {
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const id = useId();

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent | KeyboardEvent) => {
      if (e instanceof KeyboardEvent ? e.key === 'Escape' : !root.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', close);
    return () => {
      document.removeEventListener('mousedown', close);
      document.removeEventListener('keydown', close);
    };
  }, [open]);

  return (
    <div className="menu" ref={root}>
      <button type="button" className="btn" aria-haspopup="menu" aria-expanded={open} aria-controls={id} onClick={() => setOpen((o) => !o)}>
        Acheter
        <svg viewBox="0 0 10 6" width="10" height="6" aria-hidden="true">
          <path d="M1 1l4 4 4-4" fill="none" stroke="currentColor" strokeWidth="1.4" />
        </svg>
      </button>
      {open && (
        <ul className="menu-list" id={id} role="menu" aria-label={`Rechercher ${reference} chez`}>
          {buyLinks(reference).map((l) => (
            <li key={l.shop} role="none">
              <a role="menuitem" href={l.url} target="_blank" rel="noopener noreferrer" onClick={() => setOpen(false)}>
                {l.shop}
                <span className="m">{reference}</span>
              </a>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
