import { useCallback, useEffect, useReducer, useState } from 'react';
import { postTurn } from './agent';
import { Composer } from './components/Composer';
import { Sidebar } from './components/Sidebar';
import { Toasts } from './components/Toasts';
import { TopBar } from './components/TopBar';
import { VehicleDetail } from './components/VehicleDetail';
import { initialState } from './mock';
import { reduce } from './state';
import { useMercure, type MercureFrame } from './useMercure';
import type { VehicleId, VehicleSummary } from './types';

const API = import.meta.env.VITE_API_BASE ?? '';

export function App() {
  const [state, dispatch] = useReducer(reduce, initialState);
  const [busy, setBusy] = useState(false);
  // One session per tab — scopes the agent's render-intent topic to this dashboard.
  const [sessionId] = useState(() => crypto.randomUUID());

  const selected = state.vehicles.find((v) => v.id === state.selectedId) ?? state.vehicles[0];

  // GET /garage/vehicles → replace the sidebar parc list. Called once on mount and again whenever a
  // vehicle domain event fans out (create/merge), so a write in this tab or another grows the list live.
  const loadVehicles = useCallback(() => {
    fetch(`${API}/garage/vehicles`, { headers: { Accept: 'application/ld+json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((data: { member?: VehicleSummary[]; 'hydra:member'?: VehicleSummary[] }) => {
        const vehicles = data.member ?? data['hydra:member'] ?? [];
        dispatch({ kind: 'action', action: { type: 'setVehicles', vehicles } });
      })
      .catch(() => {
        /* keep current data if the API is unreachable */
      });
  }, []);

  useEffect(loadVehicles, [loadVehicles]);

  const send = useCallback(
    async (instruction: string) => {
      setBusy(true);
      try {
        // No action list comes back: the agent streams its intents over the session topic.
        await postTurn(instruction, { sessionId, selectedId: state.selectedId });
      } catch (e) {
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec de la requête : ${(e as Error).message}` },
        });
      } finally {
        setBusy(false);
      }
    },
    [sessionId, state.selectedId],
  );

  const onFrame = useCallback(
    (frame: MercureFrame) => {
      if ('type' in frame) {
        // Render intent on the session topic — apply it to the dashboard.
        dispatch({ kind: 'action', action: frame });
        return;
      }
      // Domain event on a public vehicle topic — a write fanned out (this tab or another).
      dispatch({ kind: 'live', message: `${frame.kind}/${frame.event} · ${frame.vehicle.slice(0, 8)}…` });
      dispatch({
        kind: 'action',
        action: { type: 'toast', tone: 'info', message: `Mise à jour live : ${frame.kind} ${frame.event}` },
      });
      // A vehicle write changes the parc list itself — refetch so the sidebar grows/dedups live.
      // maintenance/fact events touch only a vehicle's panels, not the list, so they need no refetch.
      if (frame.kind === 'vehicle') {
        loadVehicles();
      }
    },
    [loadVehicles],
  );

  // selectedId is a vehicle IRI path (e.g. /garage/vehicles/01J…) once the real parc loads.
  const itemTopic = state.selectedId.startsWith('/') ? `${API}${state.selectedId}` : undefined;
  useMercure(
    [`${API}/garage/vehicles`, ...(itemTopic ? [itemTopic] : []), `${API}/agent/sessions/${sessionId}`],
    onFrame,
  );

  const onSelect = (id: VehicleId) => dispatch({ kind: 'action', action: { type: 'selectVehicle', id } });

  return (
    <div className="app">
      <TopBar />
      <div className="body">
        <Sidebar vehicles={state.vehicles} selectedId={state.selectedId} onSelect={onSelect} />
        <main className="canvas">
          <div className="scroll">
            {selected ? (
              <VehicleDetail vehicle={selected} />
            ) : (
              <div className="empty-parc">
                <div className="breadcrumb">Atelier / Véhicules</div>
                <h1>Aucun véhicule</h1>
                <p>Ajoutez un véhicule au parc pour commencer.</p>
              </div>
            )}
          </div>
          <Composer suggestions={state.suggestions} busy={busy} onSend={send} />
        </main>
      </div>
      <Toasts toasts={state.toasts} live={state.live} onDismiss={(id) => dispatch({ kind: 'dismissToast', id })} />
    </div>
  );
}
