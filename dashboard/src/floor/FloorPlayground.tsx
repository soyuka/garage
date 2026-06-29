import { useState, type CSSProperties } from 'react';
import '../tokens/atelier.css';
import { FIXTURES, SCENARIOS, type Scenario } from '../fixtures/vehicles';
import { FlatPlanList } from './FlatPlanList';
import { TruthPanel } from './TruthPanel';

// Stage B.1 dev route (#/atelier) — the HONEST FLOOR on fixtures, before any spectacle. A scenario
// switcher proves every value (identity, mileage, health, per-system échéances, the precise overdue
// amount) is legible in the glass truth-panel AND the flat plan list — three stages before any glow.
export function FloorPlayground() {
  const [scenario, setScenario] = useState<Scenario>('overdue');
  const bundle = FIXTURES[scenario];

  return (
    <div style={page}>
      <header style={header}>
        <a href="#/dev" style={back}>
          ← dev index
        </a>
        <h1 style={h1}>Le sol honnête · Stage B.1 — vérité avant spectacle</h1>
        <p style={sub}>Truth-panel + plan plat, sur fixtures. Cacher la 3D : aucune valeur perdue.</p>
      </header>

      <div style={controls}>
        {SCENARIOS.map(({ id, label }) => (
          <button key={id} style={scenario === id ? btnOn : btn} onClick={() => setScenario(id)}>
            {label}
          </button>
        ))}
      </div>

      {bundle ? (
        <div style={grid}>
          <TruthPanel vehicle={bundle.vehicle} forecast={bundle.forecast} />
          <div style={planCard} className="glass">
            <FlatPlanList forecast={bundle.forecast} />
          </div>
        </div>
      ) : (
        <div style={empty}>
          <p style={{ fontSize: 15 }}>Parc vide.</p>
          <p style={{ color: '#8b8a85', fontSize: 13 }}>Ajoutez un véhicule pour commencer.</p>
        </div>
      )}
    </div>
  );
}

const page: CSSProperties = {
  minHeight: '100vh',
  background: 'radial-gradient(120% 90% at 50% 0%, #1a1813 0%, #131210 55%, #0d0c0a 100%)',
  color: '#e7e3da',
  fontFamily: "'Inter', system-ui, sans-serif",
  padding: '1.5rem 2rem 3rem',
  boxSizing: 'border-box',
};
const header: CSSProperties = { marginBottom: '1.2rem' };
const back: CSSProperties = { color: '#7a8088', textDecoration: 'none', fontSize: 12, fontFamily: 'monospace' };
const h1: CSSProperties = { fontSize: '1rem', letterSpacing: '0.02em', margin: '0.6rem 0 0.2rem' };
const sub: CSSProperties = { fontSize: 12, opacity: 0.6, margin: 0, fontFamily: 'monospace' };
const controls: CSSProperties = { display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: '1.4rem' };
const btn: CSSProperties = {
  background: '#1c1e22',
  border: '1px solid rgba(180,196,210,0.2)',
  borderRadius: 5,
  color: '#c9c5bc',
  font: 'inherit',
  fontSize: 12,
  padding: '7px 12px',
  cursor: 'pointer',
};
const btnOn: CSSProperties = { ...btn, border: '1px solid #67e8f9', color: '#e7e3da' };
const grid: CSSProperties = { display: 'flex', gap: 20, flexWrap: 'wrap', alignItems: 'flex-start' };
const planCard: CSSProperties = { padding: 'var(--at-space-5)', minWidth: 320, flex: '1 1 320px', maxWidth: 520 };
const empty: CSSProperties = {
  border: '1px dashed rgba(180,196,210,0.2)',
  borderRadius: 18,
  padding: '3rem',
  textAlign: 'center',
};
