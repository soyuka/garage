import { useEffect, useRef, useState } from 'react';
import { getIdentity, logout } from '../auth';
import type { Vehicle } from '../types';
import { StatusHeartbeat } from './StatusHeartbeat';

interface TopBarProps {
  live: string[];
  status: string[];
  busy: boolean;
  onAdvanceStatus: () => void;
  onSettings?: () => void;
  onJournal?: () => void;
  // The selected vehicle, passed only while its title is scrolled out of view (else undefined).
  vehicle?: Vehicle;
}

/**
 * The top chrome. Left: a compact live indicator (the former Mercure feed, now wordless — just a
 * status dot and the last event) followed by the agent's heartbeat sentence while it answers.
 * Right: search and the account menu grouped together, off the middle of the bar.
 */
export function TopBar({ live, status, busy, onAdvanceStatus, onSettings, onJournal, vehicle }: TopBarProps) {
  return (
    <header className="topbar">
      <div className="mercure-feed" aria-label="Flux temps réel">
        <span className={`mercure-dot${live.length ? ' live' : ''}`} />
        {busy || status.length > 0 ? (
          <StatusHeartbeat queue={status} busy={busy} onAdvance={onAdvanceStatus} />
        ) : live.length > 0 ? (
          <span className="mercure-last">{live[0]}</span>
        ) : null}
      </div>
      {vehicle ? <VehicleEcho vehicle={vehicle} /> : null}
      <div className="topbar-right">
        <div className="search">🔍 Rechercher un véhicule…</div>
        {onJournal ? (
          <button type="button" className="tb-journal" onClick={onJournal} title="Journal de l'agent" aria-label="Journal de l'agent">
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
              <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
              <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
              <path d="M9 7h7M9 11h7" />
            </svg>
          </button>
        ) : null}
        <AccountMenu onSettings={onSettings} />
      </div>
    </header>
  );
}

/**
 * The selected vehicle's identity, echoed in the top bar once its title scrolls off. Name in front,
 * then the same metadata badges the detail header carries (year · finition · plaque · relevé).
 */
function VehicleEcho({ vehicle }: { vehicle: Vehicle }) {
  const name = [vehicle.make, vehicle.model].filter(Boolean).join(' ') || 'Véhicule';
  const meta = [
    vehicle.year ? String(vehicle.year) : '',
    vehicle.modification ?? '',
    vehicle.plate ?? '',
    vehicle.mileage ? `${vehicle.mileage} ${vehicle.mileageUnit}` : '',
  ].filter(Boolean);
  return (
    <div className="tb-vehicle" aria-label="Véhicule sélectionné">
      <span className="tb-veh-name">{name}</span>
      {meta.length ? <span className="tb-veh-meta">{meta.join(' · ')}</span> : null}
    </div>
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
