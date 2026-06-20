import { useEffect, useRef, useState } from 'react';
import { getIdentity, logout } from '../auth';

/**
 * The top chrome. Left: a compact live indicator (the former Mercure feed, now wordless — just a
 * status dot and the last event). Right: search and the account menu grouped together, off the
 * middle of the bar.
 */
export function TopBar({ live, onSettings }: { live: string[]; onSettings?: () => void }) {
  return (
    <header className="topbar">
      <div className="mercure-feed" aria-label="Flux temps réel">
        <span className={`mercure-dot${live.length ? ' live' : ''}`} />
        {live.length > 0 ? <span className="mercure-last">{live[0]}</span> : null}
      </div>
      <div className="topbar-right">
        <div className="search">🔍 Rechercher un véhicule…</div>
        <AccountMenu onSettings={onSettings} />
      </div>
    </header>
  );
}

/** The account button + dropdown (settings / logout). Closes on outside click or Escape. */
function AccountMenu({ onSettings }: { onSettings?: () => void }) {
  const identity = getIdentity();
  const label = identity.name ?? identity.email ?? 'Compte';
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className="tb-menu" ref={ref}>
      <button
        type="button"
        className={`tb-trigger${open ? ' open' : ''}`}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label="Compte et paramètres"
        title={label}
        onClick={() => setOpen((o) => !o)}
      >
        <span className="tb-gear" aria-hidden="true">⚙</span>
      </button>
      {open ? (
        <div className="tb-menu-pop" role="menu">
          <div className="tb-menu-head">{label}</div>
          <button
            type="button"
            className="tb-menu-item"
            role="menuitem"
            onClick={() => {
              setOpen(false);
              onSettings?.();
            }}
          >
            Paramètres
          </button>
          <button
            type="button"
            className="tb-menu-item tb-menu-item--danger"
            role="menuitem"
            onClick={() => {
              setOpen(false);
              logout();
            }}
          >
            Déconnexion
          </button>
        </div>
      ) : null}
    </div>
  );
}
