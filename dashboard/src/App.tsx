import { useCallback, useEffect, useReducer, useRef, useState } from 'react';
import { postTurn } from './agent';
import { getVehicle, getVehicleTasks, patchTask, patchVehicle } from './garage';
import { Composer } from './components/Composer';
import { CtaCard } from './components/CtaCard';
import { FactPanel } from './components/FactPanel';
import { Sidebar } from './components/Sidebar';
import { Toasts } from './components/Toasts';
import { TopBar } from './components/TopBar';
import { VehicleDetail, type VehicleEdit } from './components/VehicleDetail';
import { reduce, vehicleFromSummary } from './state';
import { useMercure, type MercureFrame } from './useMercure';
import type { DashboardState, VehicleId, VehicleSummary } from './types';

const API = import.meta.env.VITE_API_BASE ?? '';

// Empty boot state. The parc loads live from GET /garage/vehicles on mount; selectedId is filled
// by setVehicles once the real list lands.
const initialState: DashboardState = {
  vehicles: [],
  selectedId: '',
  factPanel: null,
  cta: null,
  toasts: [],
  live: [],
};

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
  // Live Mercure frames carry no checklist (it is its own subresource), so onFrame needs the
  // current selection to know whether to refetch tasks — read it from a ref to avoid resubscribing.
  const selectedIdRef = useRef(state.selectedId);
  selectedIdRef.current = state.selectedId;

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
  // them onto the row. The checklist is NOT here — it rides its own subresource (see loadTasks).
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
            usageValue: full.usageValue,
            usageUnit: full.usageUnit,
            mileage: full.mileage,
            mileageUnit: full.mileageUnit,
            plate: full.plate,
            usage: full.usage,
          },
        });
      })
      .catch((e) => {
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec du chargement du véhicule : ${(e as Error).message}` },
        });
      });
  }, []);

  // GET the vehicle's open checklist (the planned-tasks subresource) and merge it onto the row. Its
  // length is the open count. Re-run on select, after a tick-off, and on a live frame for this vehicle.
  const loadTasks = useCallback((id: VehicleId) => {
    if (!id.startsWith('/')) return;
    getVehicleTasks(id)
      .then((plannedTasks) => {
        dispatch({ kind: 'patchVehicle', id, patch: { plannedTasks, openTasks: plannedTasks.length } });
      })
      .catch((e) => {
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec du chargement des tâches : ${(e as Error).message}` },
        });
      });
  }, []);

  useEffect(() => {
    loadDetail(state.selectedId);
    loadTasks(state.selectedId);
  }, [state.selectedId, loadDetail, loadTasks]);

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

  // Tick a checklist task off: PATCH its status, then refetch the checklist so the card + counter settle.
  const completeTask = useCallback(
    async (taskIri: string) => {
      try {
        await patchTask(taskIri, 'done');
        loadTasks(state.selectedId);
      } catch (e) {
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec de la mise à jour : ${(e as Error).message}` },
        });
      }
    },
    [state.selectedId, loadTasks],
  );

  const send = useCallback(
    async (instruction: string) => {
      // Go busy on dispatch and stay busy: the turn runs in an async worker, so the POST only acks
      // 202 (queued) — the worker clears busy with a `turn`/`end` cue on the session topic (onFrame).
      setBusy(true);
      try {
        // No action list comes back: the agent streams its intents over the session topic.
        await postTurn(instruction, { sessionId, selectedId: state.selectedId });
      } catch (e) {
        // The turn never got queued, so no `end` cue is coming — clear busy here.
        setBusy(false);
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec de la requête : ${(e as Error).message}` },
        });
      }
    },
    [sessionId, state.selectedId],
  );

  const onFrame = useCallback(
    (frame: MercureFrame) => {
      if ('type' in frame) {
        // Render intent on the session topic. The `turn` lifecycle cue drives the busy state (the
        // worker runs async, so the POST ack can't); progress toasts feed the heartbeat line; errors
        // and any non-toast intent (selectVehicle/showFact/proposeActions) go to the reducer.
        if (frame.type === 'turn') {
          setBusy(frame.phase === 'start');
        } else if (frame.type === 'toast' && frame.tone !== 'alarm') {
          pushStatus(frame.message);
        } else {
          dispatch({ kind: 'action', action: frame });
        }
        return;
      }
      // Domain event on a public vehicle topic — a write fanned out (this tab or another). The
      // payload carries the vehicle scalars + facts, so merge it straight in. The checklist is its
      // own subresource and is NOT in the frame, so refetch it when the write touches the open vehicle.
      dispatch({ kind: 'live', message: `${frame.kind}/${frame.event} · ${frame.vehicle.slice(0, 8)}…` });
      pushStatus(`Mise à jour live : ${frame.kind} ${frame.event}`);
      dispatch({ kind: 'upsertVehicle', summary: frame.resource });
      if (frame.resource['@id'] === selectedIdRef.current) {
        loadTasks(selectedIdRef.current);
      }
    },
    [pushStatus, loadTasks],
  );

  // The collection topic carries every vehicle's full-resource frame (the publisher fans out to
  // both the item and collection topics with the same payload), so subscribing to it alone covers
  // all vehicles — no per-item subscription that would only double-deliver the selected one.
  useMercure([`${API}/garage/vehicles`, `${API}/agent/sessions/${sessionId}`], onFrame);

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
          {state.cta ? (
            <CtaCard
              cta={state.cta}
              busy={busy}
              onSend={send}
              onDismiss={() => dispatch({ kind: 'dismissCta' })}
            />
          ) : null}
          {state.factPanel && selected ? (
            <FactPanel
              panel={state.factPanel}
              vehicle={selected}
              onExpand={() => dispatch({ kind: 'expandFactPanel' })}
              onDismiss={() => dispatch({ kind: 'dismissFactPanel' })}
            />
          ) : null}
          <Composer busy={busy} status={status} onAdvanceStatus={advanceStatus} onSend={send} />
        </main>
      </div>
      <Toasts toasts={state.toasts} live={state.live} onDismiss={(id) => dispatch({ kind: 'dismissToast', id })} />
    </div>
  );
}
