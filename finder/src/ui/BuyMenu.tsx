import { useEffect, useId, useRef, useState } from 'react';
import { buyLinks, type BuyReference } from '../buy';

export function BuyMenu({ reference }: { reference: BuyReference }) {
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

  const label = [reference.brand, reference.number].filter(Boolean).join(' ');

  return (
    <div className="menu" ref={root}>
      <button type="button" className="btn pri" aria-haspopup="menu" aria-expanded={open} aria-controls={id} onClick={() => setOpen((o) => !o)}>
        Acheter
        <svg viewBox="0 0 10 6" width="10" height="6" aria-hidden="true">
          <path d="M1 1l4 4 4-4" fill="none" stroke="currentColor" strokeWidth="1.4" />
        </svg>
      </button>
      {open && (
        <div className="menu-pop" id={id}>
          <p className="menu-head">
            <span className="l">Rechercher</span>
            <span className="m">{label}</span>
          </p>
          <ul className="menu-list" role="menu" aria-label={`Rechercher ${label} chez`}>
            {buyLinks(reference).map((l) => (
              <li key={l.shop} role="none">
                <a role="menuitem" href={l.url} target="_blank" rel="noopener noreferrer" onClick={() => setOpen(false)}>
                  {l.shop}
                </a>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
