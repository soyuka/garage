import { PILOT_QUIET_MS } from '../tokens/motion';
import type { BodyRegion } from '../scene/anchors';
import type { NoyauEvent } from './state';

// Scripted mock turns for the Stage-A playground — NO backend. Frames use the EXACT real session-frame
// shapes (turn / status / toast), and status frames fire SPARSELY (per narrated step, not per
// tool-call) to match the live step-narrator. Stage C swaps this generator for Mercure; the machine
// it drives is unchanged.

export type Scenario = 'healthy' | 'due' | 'error' | 'parts';

const SCRIPTS: Record<Scenario, { steps: { at: number; ev: NoyauEvent }[] }> = {
  healthy: {
    steps: [
      { at: 0, ev: { type: 'turn', phase: 'start' } },
      { at: 350, ev: { type: 'status', message: 'Lecture de la plaque AB-123-CD…' } },
      { at: 1700, ev: { type: 'status', message: 'Recherche des pièces · TecDoc…' } },
      { at: 2900, ev: { type: 'status', message: 'Comparaison des références…' } },
      { at: 4100, ev: { type: 'status', message: "Viscosité d'huile…" } },
      { at: 5200, ev: { type: 'toast', tone: 'ok', message: 'Parc à jour · rien en attente' } },
      { at: 5600, ev: { type: 'turn', phase: 'end' } },
    ],
  },
  due: {
    steps: [
      { at: 0, ev: { type: 'turn', phase: 'start' } },
      { at: 350, ev: { type: 'status', message: 'Lecture de la plaque…' } },
      { at: 1700, ev: { type: 'status', message: 'Calcul des échéances…' } },
      { at: 3000, ev: { type: 'toast', tone: 'warn', message: 'Vidange à prévoir · dans environ 2 mois' } },
      { at: 3600, ev: { type: 'turn', phase: 'end' } },
    ],
  },
  error: {
    steps: [
      { at: 0, ev: { type: 'turn', phase: 'start' } },
      { at: 350, ev: { type: 'status', message: 'Recherche · TecDoc…' } },
      { at: 1800, ev: { type: 'toast', tone: 'alarm', message: 'Vidange en retard · DÉPASSÉ de 3 200 km' } },
      { at: 2400, ev: { type: 'turn', phase: 'end' } },
    ],
  },
  parts: {
    steps: [
      { at: 0, ev: { type: 'turn', phase: 'start' } },
      { at: 350, ev: { type: 'status', message: 'Recherche · TecDoc…' } },
      { at: 1700, ev: { type: 'status', message: 'Comparaison des références…' } },
      { at: 2900, ev: { type: 'toast', tone: 'info', message: 'Filtre à huile MANN W 712/75 trouvé' } },
      { at: 3300, ev: { type: 'turn', phase: 'end' } },
    ],
  },
};

/** Fire a scripted turn through `dispatch`; returns a canceller that clears any pending frames. */
export function runMockTurn(scenario: Scenario, dispatch: (ev: NoyauEvent) => void): () => void {
  const timers = SCRIPTS[scenario].steps.map(({ at, ev }) => window.setTimeout(() => dispatch(ev), at));
  return () => timers.forEach((t) => window.clearTimeout(t));
}

// ── A PILOT run (docs/pilot.md) ───────────────────────────────────────────────
// Shaped like the thing itself, not like a turn: no `turn:start` (the first status IS the start), a
// burst of intents inside a few hundred ms, then dead air. It deliberately never settles — the point
// of the script is the state a turn cannot reach, veille pilotée, and the caller closes it by hand
// the way `settle_dashboard` would. The regions go through the caller's coalescer, so what you watch
// is one dolly to the LAST of them, not three.

const PILOT_SCRIPT: { at: number; ev?: NoyauEvent; region?: BodyRegion }[] = [
  { at: 0, ev: { type: 'status', message: 'Je relis le carnet…', driver: 'pilot' } },
  { at: 260, ev: { type: 'status', message: 'Je cherche la référence du filtre…', driver: 'pilot' } },
  // the burst: three intents naming three regions in 180ms — one gesture
  { at: 900, region: 'hood' },
  { at: 960, region: 'bay' },
  { at: 1080, region: 'frontAxle' },
  { at: 1120, ev: { type: 'toast', tone: 'ok', message: 'Filtre à huile MANN W 712/75', driver: 'pilot' } },
  // …and then the host goes off to read code. PILOT_QUIET_MS later the console stops claiming to work.
  { at: 1120 + PILOT_QUIET_MS, ev: { type: 'quiet' } },
];

/**
 * Replay a pilot burst offline. `flare` receives each region target — pass the coalescer
 * (`useRegionBurst`) so the playground shows the same single dolly the live orchestrator does.
 */
export function runMockPilot(dispatch: (ev: NoyauEvent) => void, flare: (region: BodyRegion) => void): () => void {
  const timers = PILOT_SCRIPT.map(({ at, ev, region }) =>
    window.setTimeout(() => {
      if (ev) dispatch(ev);
      if (region) flare(region);
    }, at),
  );
  return () => timers.forEach((t) => window.clearTimeout(t));
}
