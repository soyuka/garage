import { getIdentity, logout } from '../auth';

export function TopBar() {
  const identity = getIdentity();
  const label = identity.name ?? identity.email ?? 'Compte';
  const initials = label.slice(0, 3).toUpperCase();

  return (
    <header className="topbar">
      <div className="brand">
        <div className="mark">M</div>
        <div>
          <div className="name">mon-parc-auto</div>
        </div>
        <span className="garage">mon-garage</span>
      </div>
      <div className="search">🔍 Rechercher un véhicule…</div>
      <button type="button" className="avatar" title={`${label} — Déconnexion`} onClick={() => logout()}>
        {initials}
      </button>
    </header>
  );
}
