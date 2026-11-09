import { useMemo, useRef, useState } from 'react';
import { useLocale } from '../i18n/LocaleProvider';
import '../tokens/atelier.css';
import './scene.css';
import { FIXTURES, SCENARIOS, type Scenario } from '../fixtures/vehicles';
import { useNoyau } from '../noyau/useNoyau';
import { runMockPilot, runMockTurn } from '../noyau/mockStream';
import { SceneView, type Rung } from './SceneView';
import { crossOverdue, firstCrossable } from './crossing';
import { useRegionBurst } from './useRegionBurst';
import { useReducedMotion } from './useReducedMotion';
import { serviceLabel } from '../labels';
import { formatReading } from '../format';
import { PULSE_DURATION } from './holo';
import type { CameraFocus } from './cameraRig';
import { focusForReveal, revealFor, revealSurvives, type RevealState } from './reveal';
import type { BodyRegion } from './anchors';
import type { PulseRegion } from './VehicleMesh';
import type { MaintenanceRecord } from '../types';

// Fixture visits for the "Historique" quick action offline (/scene has no backend) — enough shape to
// exercise HistoryLog's ledger (multi-item visit, parts, cost, notes) without wiring a real bundle.
const HISTORY_FIXTURE: MaintenanceRecord[] = [
  {
    id: 'fixture:record:1',
    performedAt: '2026-04-12',
    usageValue: 68200,
    usageUnit: 'km',
    performedBy: 'Garage Dupont',
    costCents: 18900,
    currency: 'EUR',
    source: 'user',
    items: [
      { type: 'oil_change', parts: [{ ref: '5W30', brand: 'Motul', label: 'Huile moteur', qty: 5 }] },
      { type: 'oil_filter', parts: [{ ref: 'OC90', brand: 'Mann' }] },
    ],
    notes: 'Vidange + filtre à huile, contrôle niveaux.',
  },
  {
    id: 'fixture:record:2',
    performedAt: '2025-11-03',
    usageValue: 61000,
    usageUnit: 'km',
    performedBy: 'Garage Dupont',
    costCents: 42000,
    currency: 'EUR',
    source: 'user',
    items: [{ type: 'brake_pads_front', parts: [{ ref: 'BP-4402', brand: 'Bosch', qty: 1 }] }],
  },
  {
    id: 'fixture:record:3',
    performedAt: '2025-05-20',
    usageValue: 52000,
    usageUnit: 'km',
    source: 'catalog',
    items: [{ type: 'tires', parts: [] }],
  },
];

// Stage-B fixture PLAYGROUND (#/scene) — the dev harness around the pure SceneView. It owns the
// scenario/rung/reduced switchers, the ▶ mock-turn sim (offline Noyau states) and the ⚠ §9-crossing
// sim. Stage C's live orchestrator (Atelier.tsx) renders the SAME SceneView with real data + Mercure
// frames instead of these sims. The mock stream stays a dev-only affordance (the live app never imports it).
const RUNGS: { id: Rung; label: string }[] = [
  { id: 'a', label: 'a · .glb' },
  { id: 'b', label: 'b · proxy 3D' },
  { id: 'c', label: 'c · silhouette' },
  { id: 'd', label: 'd · liste' },
];

export function AtelierScene() {
  const [locale] = useLocale();
  const [scenario, setScenario] = useState<Scenario>('healthy');
  const [rung, setRung] = useState<Rung>('b');
  const [reducedToggle, setReducedToggle] = useState(false);
  const [crossed, setCrossed] = useState(false);
  const [factsDrawerOpen, setFactsDrawerOpen] = useState(false);
  const [historyOpen, setHistoryOpen] = useState(false);
  const [showDismissed, setShowDismissed] = useState(false);
  const [taskOverrides, setTaskOverrides] = useState<Map<string, string>>(new Map());
  const { state, dispatch } = useNoyau();
  const cancel = useRef<null | (() => void)>(null);
  const crossTimer = useRef<number | null>(null);

  // The pilot sim (⇄): the same flare + coalescer the live orchestrator runs, so the playground shows
  // ONE dolly for the script's three-region burst — the coalescing is visible, not just tested.
  const [pulseRegion, setPulseRegion] = useState<PulseRegion | null>(null);
  const [cameraFocus, setCameraFocus] = useState<CameraFocus | null>(null);
  const [reveal, setReveal] = useState<RevealState | null>(null);
  const flareKey = useRef(0);
  const pulseTimer = useRef<number | null>(null);
  const requestFlare = useRegionBurst((region) => {
    flareKey.current += 1;
    setPulseRegion({ region, key: flareKey.current });
    if (pulseTimer.current != null) window.clearTimeout(pulseTimer.current);
    pulseTimer.current = window.setTimeout(() => setPulseRegion(null), (PULSE_DURATION + 0.3) * 1000);
    setCameraFocus({ region, key: flareKey.current });
  });
  // The reveal, offline: the same intent a `reveal_assembly` frame produces. Derived-from-the-hold
  // exactly like the live orchestrator, so the playground cannot drift from it.
  const liveReveal = revealSurvives(reveal, cameraFocus) ? reveal : null;
  const toggleReveal = (region: BodyRegion, keys: string[]) => {
    if (liveReveal) {
      setCameraFocus(null);
      return;
    }
    flareKey.current += 1;
    const next = revealFor(region, keys, flareKey.current);
    if (!next) return;
    setReveal(next);
    setCameraFocus(focusForReveal(next));
  };

  const piloted = state.driver === 'pilot';
  const runPilot = () => {
    cancel.current?.();
    // A second press is the settle_dashboard the script deliberately never sends.
    if (piloted) {
      dispatch({ type: 'toast', tone: 'ok', message: 'Pilote débranché', driver: 'pilot' });
      dispatch({ type: 'turn', phase: 'end', driver: 'pilot' });
      setCameraFocus(null);
      return;
    }
    cancel.current = runMockPilot(dispatch, requestFlare);
  };

  // Effective reduced-motion = the manual dev toggle OR the OS prefers-reduced-motion flag (the OS
  // signal must reach the WebGL useFrame loops, which no CSS @media can touch).
  const osReduced = useReducedMotion();
  const reduced = reducedToggle || osReduced;

  const bundle = FIXTURES[scenario];
  // §9 crossing: when armed, the displayed bundle is the tipped-overdue one so every leaf reacts red.
  const shown = crossed && bundle ? crossOverdue(bundle) : bundle;
  // one scan of the fixture's due[] feeds both the "arm" affordance and the trigger handler below.
  const crossableIdx = useMemo(() => (bundle ? firstCrossable(bundle.forecast.due) : -1), [bundle]);
  const crossable = crossableIdx !== -1;

  // Fixture edit/checklist seams so the playground exercises the FULL cockpit row (editable make/model/
  // plate/engine/km), échéances + task dock exactly as the live orchestrator does — writes are no-ops
  // here (no backend), the affordances and layout are real. Live wiring lives in Atelier.tsx.
  const noop = () => {};
  const demoEdit = { onPatch: noop, onDelete: noop, facts: { onAdd: noop, onRemove: noop, onEditValue: noop } };
  const demoChecklist = shown
    ? {
        tasks: taskOverrides.size > 0 ? (shown.vehicle.plannedTasks ?? []).map((t) => ({ ...t, status: taskOverrides.get(t.id) ?? t.status })) : (shown.vehicle.plannedTasks ?? []),
        showDismissed,
        onToggleDismissed: () => setShowDismissed((v) => !v),
        // The one write the playground really performs. Everything else here is a no-op, but STATUS has
        // to be live: ticking a row is the act that removes it from every horizon, and a harness where
        // that does nothing cannot show whether the row is recoverable — which is exactly the bug the
        // closed drawer fixes (rail.ts closedTasks).
        onSetStatus: (taskIri: string, status: 'open' | 'done' | 'dismissed') =>
          setTaskOverrides((prev) => {
            const next = new Map(prev);
            next.set(taskIri, status);
            return next;
          }),
        onEditTask: noop,
        onAddTask: noop,
        onAddPart: noop,
        onEditPart: noop,
        onRemovePart: noop,
        onArchiveDone: noop,
        defaultUnit: shown.vehicle.usageUnit ?? 'km',
      }
    : undefined;

  // The crossing is a Mercure DOMAIN push (no agent turn): it murmurs one
  // concern line, then settles back to veille. The scene's red state (glow/rim/panel) carries the rest.
  const triggerCrossing = () => {
    if (!bundle) return;
    const idx = crossableIdx;
    if (idx === -1) return;
    const u = bundle.vehicle;
    const reading = u.usageValue != null ? ` · ${formatReading(u.usageValue, u.usageUnit, locale)}` : '';
    const msg = `${serviceLabel(bundle.forecast.due[idx].type, locale)} en retard${reading}`;
    setCrossed(true);
    dispatch({ type: 'concern', message: msg });
    if (crossTimer.current != null) window.clearTimeout(crossTimer.current);
    crossTimer.current = window.setTimeout(() => dispatch({ type: 'settle' }), 1800);
  };

  const resetCrossing = () => {
    if (crossTimer.current != null) window.clearTimeout(crossTimer.current);
    setCrossed(false);
    dispatch({ type: 'settle' });
  };

  const selectScenario = (id: Scenario) => {
    setScenario(id);
    setTaskOverrides(new Map()); // a different car — the previous one's ticked rows are not its own
    resetCrossing();
  };

  return (
    <div className="at-scene">
      <div className="at-scene-top">
        <a href="#/dev" className="at-scene-back">← dev index</a>
        <div className="at-scene-switch">
          {SCENARIOS.map(({ id, label }) => (
            <button key={id} className={scenario === id ? 'at-sbtn at-sbtn--on' : 'at-sbtn'} onClick={() => selectScenario(id)}>
              {label}
            </button>
          ))}
        </div>
        <div className="at-scene-switch">
          <span className="at-scene-rungs-label">RUNG</span>
          {RUNGS.map(({ id, label }) => (
            <button key={id} className={rung === id ? 'at-sbtn at-sbtn--on' : 'at-sbtn'} onClick={() => setRung(id)} disabled={!bundle}>
              {label}
            </button>
          ))}
          <label className="at-scene-toggle">
            <input
              type="checkbox"
              name="reduced-motion"
              checked={reduced}
              disabled={osReduced}
              onChange={(e) => setReducedToggle(e.target.checked)}
            />{' '}
            reduced-motion{osReduced ? ' (OS)' : ''}
          </label>
        </div>
      </div>

      <SceneView
        bundle={shown}
        rung={rung}
        reduced={reduced}
        noyau={state}
        pulseRegion={pulseRegion}
        cameraFocus={cameraFocus}
        reveal={liveReveal}
        onCameraRelease={() => setCameraFocus(null)}
        onHoldRegion={(region) => {
          // the operator's own dolly — no cyan flare, the playground mirrors the live driver exactly.
          flareKey.current += 1;
          setCameraFocus({ region, key: flareKey.current });
        }}
        edit={demoEdit}
        checklist={demoChecklist}
        factsDrawerOpen={factsDrawerOpen}
        onOpenFacts={() => { setHistoryOpen(false); setFactsDrawerOpen(true); }}
        onCloseFacts={() => setFactsDrawerOpen(false)}
        records={HISTORY_FIXTURE}
        historyOpen={historyOpen}
        onOpenHistory={() => { setFactsDrawerOpen(false); setHistoryOpen(true); }}
        onCloseHistory={() => setHistoryOpen(false)}
        quickActions={[
          { label: 'Données', onClick: () => { setHistoryOpen(false); setFactsDrawerOpen(true); } },
          { label: 'Historique', zone: 'centre', onClick: () => { setFactsDrawerOpen(false); setHistoryOpen(true); } },
        ]}
        onDismissNotice={(id) => dispatch({ type: 'dismissNotice', id })}
        onComposerFocus={(active) => dispatch({ type: 'typing', active })}
        composerExtras={
          <>
            <button
              className="at-scene-sim"
              title="Simuler un tour de l'agent"
              aria-label="Simuler un tour de l'agent"
              onClick={() => {
                cancel.current?.();
                cancel.current = runMockTurn(scenario === 'overdue' ? 'error' : scenario === 'ambre' ? 'due' : 'healthy', dispatch);
              }}
            >
              ▶
            </button>
            <button
              className={`at-scene-sim at-scene-cross${crossed ? ' at-scene-cross--on' : ''}`}
              title={crossed ? 'Rétablir l’état' : 'Simuler un passage en retard (événement Mercure)'}
              aria-label={crossed ? 'Rétablir' : 'Simuler un passage en retard'}
              disabled={!crossed && !crossable}
              onClick={() => (crossed ? resetCrossing() : triggerCrossing())}
            >
              {crossed ? '↺' : '⚠'}
            </button>
            <button
              className={`at-scene-sim${liveReveal ? ' at-scene-cross--on' : ''}`}
              title={liveReveal ? 'Refermer la carrosserie' : 'Simuler reveal_assembly (bay · filtre à carburant)'}
              aria-label={liveReveal ? 'Refermer la carrosserie' : 'Simuler une vue éclatée'}
              onClick={() => toggleReveal('bay', ['fuel_filter'])}
            >
              ◉
            </button>
            <button
              className={`at-scene-sim${liveReveal ? ' at-scene-cross--on' : ''}`}
              title={liveReveal ? 'Refermer la carrosserie' : 'Simuler reveal_assembly (hood · filtre à huile + bougies)'}
              aria-label="Simuler une vue éclatée (capot)"
              onClick={() => toggleReveal('hood', ['oil_filter', 'spark_plug'])}
            >
              ◉
            </button>
            <button
              className={`at-scene-sim${piloted ? ' at-scene-cross--on' : ''}`}
              title={piloted ? 'Poser le tableau de bord (settle_dashboard)' : 'Simuler une rafale pilote (hôte MCP externe)'}
              aria-label={piloted ? 'Poser le tableau de bord' : 'Simuler une rafale pilote'}
              onClick={runPilot}
            >
              ⇄
            </button>
          </>
        }
      />
    </div>
  );
}
