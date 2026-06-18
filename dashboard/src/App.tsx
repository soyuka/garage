import { useCallback, useEffect, useReducer, useState } from 'react';
import { postTurn } from './agent';
import { getVehicle, patchTask, patchVehicle } from './garage';
import { Composer } from './components/Composer';
import { Sidebar } from './components/Sidebar';
import { Toasts } from './components/Toasts';
import { TopBar } from './components/TopBar';
import { VehicleDetail, type VehicleEdit } from './components/VehicleDetail';
import { initialState } from './mock';
import { reduce, vehicleFromSummary } from './state';
import { useMercure, type MercureFrame } from './useMercure';
import type { VehicleId, VehicleSummary } from './types';

const API = import.meta.env.VITE_API_BASE ?? '';

export function App() {
  const [state, dispatch] = useReducer(reduce, initialState);
  const [busy, setBusy] = useState(false);
  // Loading sentences, shown one-at-a-time as a heartbeat line instead of stacking a toast each.
  const [status, setStatus] = useState<string[]>([]);
  const pushStatus = useCallback((message: string) => setStatus((q) => [...q, message]), []);
  const advanceStatus = useCallback(() => setStatus((q) => q.slice(1)), []);
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

  // GET the selected vehicle item to pull in its facts + usage (the listing omits facts) and merge
  // them onto the row. Re-run on select and after any vehicle/fact event refreshes the list.
  const loadDetail = useCallback((id: VehicleId) => {
    if (!id.startsWith('/')) return;
    getVehicle(id)
      .then((s) => {
        const full = vehicleFromSummary(s);
        dispatch({
          kind: 'patchVehicle',
          id,
          patch: {
            facts: full.facts,
            plannedTasks: full.plannedTasks,
            openTasks: full.openTasks,
            usageValue: full.usageValue,
            usageUnit: full.usageUnit,
            mileage: full.mileage,
            mileageUnit: full.mileageUnit,
            plate: full.plate,
            usage: full.usage,
          },
        });
      })
      .catch(() => {
        /* keep current data if the item is unreachable */
      });
  }, []);

  useEffect(() => loadDetail(state.selectedId), [state.selectedId, loadDetail]);

  // Editable-card write: PATCH the vehicle's own scalars, then refetch the item to reconcile.
  const editVehicle = useCallback(
    async (edit: VehicleEdit) => {
      const id = state.selectedId;
      if (!id.startsWith('/')) return;
      try {
        await patchVehicle(id, edit);
        loadDetail(id);
      } catch (e) {
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec de la mise à jour : ${(e as Error).message}` },
        });
      }
    },
    [state.selectedId, loadDetail],
  );

  // Tick a checklist task off: PATCH its status, then refetch the item so the card + counter settle.
  const completeTask = useCallback(
    async (taskIri: string) => {
      try {
        await patchTask(taskIri, 'done');
        loadDetail(state.selectedId);
      } catch (e) {
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec de la mise à jour : ${(e as Error).message}` },
        });
      }
    },
    [state.selectedId, loadDetail],
  );

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
        // Render intent on the session topic. Progress toasts feed the heartbeat line; errors and
        // any non-toast intent (select/suggest/patch) go to the reducer to drive the dashboard.
        if (frame.type === 'toast' && frame.tone !== 'alarm') {
          pushStatus(frame.message);
        } else {
          dispatch({ kind: 'action', action: frame });
        }
        return;
      }
      // Domain event on a public vehicle topic — a write fanned out (this tab or another).
      dispatch({ kind: 'live', message: `${frame.kind}/${frame.event} · ${frame.vehicle.slice(0, 8)}…` });
      pushStatus(`Mise à jour live : ${frame.kind} ${frame.event}`);
      // A vehicle write changes the parc list itself — refetch so the sidebar grows/dedups live.
      // maintenance/fact events touch only a vehicle's panels, not the list, so they need no refetch.
      if (frame.kind === 'vehicle') {
        loadVehicles();
      }
      // A fact/maintenance/usage write on the open vehicle changes its panels — pull the fresh item.
      if (frame.iri === state.selectedId) {
        loadDetail(state.selectedId);
      }
    },
    [loadVehicles, loadDetail, pushStatus, state.selectedId],
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
              <VehicleDetail vehicle={selected} onEdit={editVehicle} onCompleteTask={completeTask} />
            ) : (
              <div className="empty-parc">
                <div className="breadcrumb">Atelier / Véhicules</div>
                <h1>Aucun véhicule</h1>
                <p>Ajoutez un véhicule au parc pour commencer.</p>
              </div>
            )}
          </div>
          <Composer
            suggestions={state.suggestions}
            busy={busy}
            status={status}
            onAdvanceStatus={advanceStatus}
            onSend={send}
          />
        </main>
      </div>
      <Toasts toasts={state.toasts} live={state.live} onDismiss={(id) => dispatch({ kind: 'dismissToast', id })} />
    </div>
  );
}
