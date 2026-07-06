import { useRef, useState, type CSSProperties } from 'react';
import { Noyau } from './Noyau';
import { NoyauVoice } from './NoyauVoice';
import { useNoyau } from './useNoyau';
import { runMockTurn, type Scenario } from './mockStream';

// Stage-A dev playground (#/noyau): a button panel fires scripted mock turns and the orb visibly
// lives — breathes at rest, lifts + pulses per narrated step under load, settles green/amber/red.
// No backend. The phase/pulse readout makes the heartbeat countable; the composer drives listening.

const SCENARIOS: { sc: Scenario; label: string }[] = [
  { sc: 'healthy', label: 'Tour sain (vert)' },
  { sc: 'due', label: 'Service dû (ambre)' },
  { sc: 'error', label: 'Erreur (rouge)' },
  { sc: 'parts', label: 'Recherche de pièce' },
];

export function NoyauPlayground() {
  const { state, dispatch } = useNoyau();
  const [reduced, setReduced] = useState(false);
  const cancel = useRef<null | (() => void)>(null);

  const fire = (sc: Scenario) => {
    cancel.current?.();
    cancel.current = runMockTurn(sc, dispatch);
  };

  return (
    <div style={page}>
      <header style={header}>
        <a href="#/dev" style={back}>
          ← dev index
        </a>
        <h1 style={h1}>Le Noyau · Stage A — la soul, en isolation</h1>
        <p style={sub}>
          Flux d'événements simulé (formes réelles : <code>turn</code> / <code>status</code> /{' '}
          <code>toast</code>). Aucun backend.
        </p>
      </header>

      <div style={controls}>
        {SCENARIOS.map(({ sc, label }) => (
          <button key={sc} style={btn} onClick={() => fire(sc)}>
            {label}
          </button>
        ))}
        <label style={toggle}>
          <input type="checkbox" checked={reduced} onChange={(e) => setReduced(e.target.checked)} />
          prefers-reduced-motion
        </label>
      </div>

      <div style={readout}>
        <span>
          phase <b style={{ color: '#67e8f9' }}>{state.phase}</b>
        </span>
        <span>
          pulses <b style={{ color: '#67e8f9' }}>{state.pulseCount}</b>
        </span>
        <span>
          ember <b style={{ color: state.ember ? '#fca5a5' : '#67e8f9' }}>{state.ember}</b>
        </span>
        <span>
          result <b>{state.result ?? '—'}</b>
        </span>
      </div>

      <div style={dock}>
        <Noyau state={state} reduced={reduced} onClearEmber={() => dispatch({ type: 'clearEmber' })} />
        <NoyauVoice state={state} reduced={reduced} />
        <div style={composer}>
          <input
            style={input}
            placeholder="Parlez à l'atelier…  (le focus = à l'écoute)"
            onFocus={() => dispatch({ type: 'typing', active: true })}
            onBlur={() => dispatch({ type: 'typing', active: false })}
          />
        </div>
      </div>
    </div>
  );
}

const page: CSSProperties = {
  minHeight: '100vh',
  background: 'radial-gradient(120% 90% at 50% 0%, #1a1813 0%, #131210 55%, #0d0c0a 100%)',
  color: '#c9c5bc',
  fontFamily: 'ui-monospace, monospace',
  display: 'flex',
  flexDirection: 'column',
};
const header: CSSProperties = { padding: '1.5rem 2rem 0' };
const back: CSSProperties = { color: '#7a8088', textDecoration: 'none', fontSize: 12 };
const h1: CSSProperties = { fontSize: '1rem', letterSpacing: '0.04em', margin: '0.6rem 0 0.2rem' };
const sub: CSSProperties = { fontSize: 12, opacity: 0.6, margin: 0 };
const controls: CSSProperties = { display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center', padding: '1.2rem 2rem 0' };
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
const toggle: CSSProperties = { display: 'flex', gap: 6, alignItems: 'center', fontSize: 12, opacity: 0.8, marginLeft: 'auto' };
const readout: CSSProperties = { display: 'flex', gap: 22, padding: '1rem 2rem 0', fontSize: 12, opacity: 0.85 };
const dock: CSSProperties = {
  marginTop: 'auto',
  display: 'flex',
  flexDirection: 'column',
  alignItems: 'center',
  paddingBottom: '2rem',
};
const composer: CSSProperties = { marginTop: 14, width: 'min(560px, 80vw)' };
const input: CSSProperties = {
  width: '100%',
  background: 'rgba(20,21,24,0.9)',
  border: '1px solid rgba(180,196,210,0.18)',
  borderRadius: 12,
  color: '#c9c5bc',
  font: 'inherit',
  fontSize: 13,
  padding: '12px 16px',
  outline: 'none',
};
