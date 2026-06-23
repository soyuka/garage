import { useCallback, useEffect, useReducer, useRef, useState } from 'react';
import { cancelTurn, postTurn } from './agent';
import { authFetch } from './auth';
import {
  archiveTasks,
  createMaintenanceRecord,
  createTask,
  createVehicle,
  deleteMaintenanceAttachment,
  deleteMaintenanceRecord,
  deleteServiceRule,
  deleteVehicle,
  deleteVehicleFact,
  fetchForecast,
  fetchServiceSchedule,
  getMaintenanceRecords,
  getVehicle,
  getVehicleTasks,
  mergeTaskParts,
  patchTask,
  patchVehicle,
  postVehicleFact,
  saveServiceRule,
  updateMaintenanceRecord,
  updateTask,
  updateTaskPart,
  addTaskPart,
  removeTaskPart,
  uploadMaintenanceAttachment,
  type MaintenanceRecordInput,
  type TaskPartInput,
  type PlannedTaskInput,
  type ServiceRuleInput,
  type TaskEdit,
} from './garage';
import { CarRail } from './components/CarRail';
import { Composer } from './components/Composer';
import { CtaCard } from './components/CtaCard';
import { JournalPanel } from './components/JournalPanel';
import { VehicleDetailSkeleton } from './components/Skeletons';
import { Toasts } from './components/Toasts';
import { TopBar } from './components/TopBar';
import { VehicleDetail, type VehicleEdit } from './components/VehicleDetail';
import { reduce, vehicleFromSummary } from './state';
import { loadRecency, saveRecency, vehicleSegment } from './recency';
import { parseVehicleHash, writeVehicleHash } from './url';
import { useMercure, type MercureFrame } from './useMercure';
import type { CtaAction, DashboardState, VehicleId, VehicleSummary } from './types';

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
  // localStorage read at init is fine — the reducer itself stays pure.
  recency: loadRecency(),
};

export function App() {
  const [state, dispatch] = useReducer(reduce, initialState);
  const [busy, setBusy] = useState(false);
  // True until the first GET /garage/vehicles settles — drives the boot skeletons so the empty
  // initial state shows shimmer frames instead of flashing "Aucun véhicule".
  const [booting, setBooting] = useState(true);
  // Loading sentences, shown one-at-a-time as a heartbeat line instead of stacking a toast each.
  const [status, setStatus] = useState<string[]>([]);
  const pushStatus = useCallback((message: string) => setStatus((q) => [...q, message]), []);
  const advanceStatus = useCallback(() => setStatus((q) => q.slice(1)), []);
  // One session per tab — scopes the agent's render-intent topic to this dashboard.
  const [sessionId] = useState(() => crypto.randomUUID());
  // "See all" toggle for the checklist — when on, dismissed (soft-removed) tasks ride along. Mirrored
  // into a ref so the live-frame / post-write refetches in loadTasks honour it without resubscribing.
  const [showAllTasks, setShowAllTasks] = useState(false);
  const showAllTasksRef = useRef(false);
  // Per-section loading flags so the checklist and history show their own shimmer on select/refetch,
  // independent of the boot skeleton.
  const [tasksLoading, setTasksLoading] = useState(false);
  const [recordsLoading, setRecordsLoading] = useState(false);
  const [forecastLoading, setForecastLoading] = useState(false);
  const [scheduleLoading, setScheduleLoading] = useState(false);
  // The agent debug journal drawer — opened from the TopBar, reads GET /agent/turns on demand.
  const [journalOpen, setJournalOpen] = useState(false);
  // True while the vehicle title is scrolled out of view — drives the identity echo in the top bar.
  const [titleHidden, setTitleHidden] = useState(false);
  const onTitleVisibility = useCallback((visible: boolean) => setTitleHidden(!visible), []);

  const selected = state.vehicles.find((v) => v.id === state.selectedId) ?? state.vehicles[0];
  // Live Mercure frames carry no checklist (it is its own subresource), so onFrame needs the
  // current selection to know whether to refetch tasks — read it from a ref to avoid resubscribing.
  const selectedIdRef = useRef(state.selectedId);
  selectedIdRef.current = state.selectedId;
  // The live feed sentence self-clears a few seconds after the last frame — each new frame resets
  // the timer, so a burst keeps it lit and the feed falls back to the calm idle dot once quiet.
  const liveTimerRef = useRef<ReturnType<typeof setTimeout>>(undefined);

  // GET /garage/vehicles → replace the sidebar parc list. Called once on mount and again whenever a
  // vehicle domain event fans out (create/merge), so a write in this tab or another grows the list live.
  const loadVehicles = useCallback(() => {
    authFetch(`${API}/garage/vehicles`, { headers: { Accept: 'application/ld+json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((data: { member?: VehicleSummary[]; 'hydra:member'?: VehicleSummary[] }) => {
        const vehicles = data.member ?? data['hydra:member'] ?? [];
        // Resolve the URL hash (#/v/<seg>) against the fresh list so a reload restores the same car.
        const hashSeg = parseVehicleHash(window.location.hash);
        const preferredId = hashSeg
          ? vehicles.find((s) => vehicleSegment(s['@id']) === hashSeg)?.['@id']
          : undefined;
        dispatch({ kind: 'action', action: { type: 'setVehicles', vehicles, preferredId } });
      })
      .catch(() => {
        /* keep current data if the API is unreachable */
      })
      .finally(() => setBooting(false));
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
            maintenanceHealth: full.maintenanceHealth,
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
    setTasksLoading(true);
    getVehicleTasks(id, showAllTasksRef.current)
      .then((plannedTasks) => {
        // The checklist now carries done tasks too (rendered greyed); only open ones count as pending.
        const openTasks = plannedTasks.filter((t) => t.status === 'open').length;
        dispatch({ kind: 'patchVehicle', id, patch: { plannedTasks, openTasks } });
      })
      .catch((e) => {
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec du chargement des tâches : ${(e as Error).message}` },
        });
      })
      .finally(() => setTasksLoading(false));
  }, []);

  // GET the vehicle's past maintenance (the records subresource) and merge it onto the row.
  const loadRecords = useCallback((id: VehicleId) => {
    if (!id.startsWith('/')) return;
    setRecordsLoading(true);
    getMaintenanceRecords(id)
      .then((maintenanceRecords) => dispatch({ kind: 'patchVehicle', id, patch: { maintenanceRecords } }))
      .catch((e) => {
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec du chargement des entretiens : ${(e as Error).message}` },
        });
      })
      .finally(() => setRecordsLoading(false));
  }, []);

  // GET the vehicle's maintenance forecast (the forecast subresource) and merge it onto the row.
  // Re-run on select and on a live frame for this vehicle (a logged service shifts the next-due).
  const loadForecast = useCallback((id: VehicleId) => {
    if (!id.startsWith('/')) return;
    setForecastLoading(true);
    fetchForecast(id)
      .then((forecast) => dispatch({ kind: 'patchVehicle', id, patch: { forecast } }))
      .catch((e) => {
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec du chargement des prévisions : ${(e as Error).message}` },
        });
      })
      .finally(() => setForecastLoading(false));
  }, []);

  // GET the vehicle's recurring maintenance schedule (the service-schedule subresource) and merge it
  // onto the row. These rules are the forecast's input — re-run on select and after a rule write.
  const loadSchedule = useCallback((id: VehicleId) => {
    if (!id.startsWith('/')) return;
    setScheduleLoading(true);
    fetchServiceSchedule(id)
      .then((serviceSchedule) => dispatch({ kind: 'patchVehicle', id, patch: { serviceSchedule } }))
      .catch((e) => {
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec du chargement du programme : ${(e as Error).message}` },
        });
      })
      .finally(() => setScheduleLoading(false));
  }, []);

  useEffect(() => {
    loadDetail(state.selectedId);
    loadTasks(state.selectedId);
    loadRecords(state.selectedId);
    loadForecast(state.selectedId);
    loadSchedule(state.selectedId);
  }, [state.selectedId, loadDetail, loadTasks, loadRecords, loadForecast, loadSchedule]);

  // Reflect the selected vehicle in the URL (hash, not path — auth owns the pathname) so a reload
  // restores it; persist the recency order so the most-recent car stays first across reloads.
  useEffect(() => {
    if (state.selectedId.startsWith('/')) writeVehicleHash(state.selectedId);
  }, [state.selectedId]);

  useEffect(() => {
    saveRecency(state.recency);
  }, [state.recency]);

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

  // Set a checklist task's status: PATCH it, then refetch so the card + counter settle. "done" stays
  // greyed (reopen by clicking again), "dismissed" hides it off the list for good.
  const setTaskStatus = useCallback(
    async (taskIri: string, status: 'open' | 'done' | 'dismissed') => {
      try {
        await patchTask(taskIri, status);
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

  // "Archiver les entretiens terminés": transform every done task into a past-maintenance record
  // server-side (one call), then refetch both the checklist (archived tasks drop off) and the
  // records rail (the new entretiens land). The done→history bridge — work is filed, never lost.
  const archiveDoneTasks = useCallback(async () => {
    const id = state.selectedId;
    if (!id.startsWith('/')) return;
    try {
      const { archived } = await archiveTasks(id);
      loadTasks(id);
      loadRecords(id);
      dispatch({
        kind: 'action',
        action: {
          type: 'toast',
          tone: archived > 0 ? 'ok' : 'info',
          message: archived > 0 ? `${archived} entretien${archived > 1 ? 's' : ''} archivé${archived > 1 ? 's' : ''}.` : 'Aucun entretien terminé à archiver.',
        },
      });
    } catch (e) {
      dispatch({
        kind: 'action',
        action: { type: 'toast', tone: 'alarm', message: `Échec de l'archivage : ${(e as Error).message}` },
      });
    }
  }, [state.selectedId, loadTasks, loadRecords]);

  // Flip the "see all" toggle and refetch — on shows the dismissed (soft-removed) tasks, off hides
  // them again. The ref keeps later background refetches (live frames, tick-offs) on the same view.
  const toggleShowAllTasks = useCallback(() => {
    const next = !showAllTasksRef.current;
    showAllTasksRef.current = next;
    setShowAllTasks(next);
    loadTasks(state.selectedId);
  }, [state.selectedId, loadTasks]);

  // Fill a missing spec straight from the fact panel: POST it as a user fact, then refetch the item
  // so the panel's "non renseignée" row flips to the entered value.
  const fillFact = useCallback(
    async (key: string, value: string, unit?: string | null) => {
      const id = state.selectedId;
      if (!id.startsWith('/') || value.trim() === '') return;
      try {
        await postVehicleFact(id, { key, value: value.trim(), unit });
        loadDetail(id);
      } catch (e) {
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec de l'enregistrement : ${(e as Error).message}` },
        });
      }
    },
    [state.selectedId, loadDetail],
  );

  // The deterministic CTA path: a button click that carries a structured write merges its parts onto
  // the task with a plain PATCH — no agent turn. The checklist's shopping-list block then re-renders
  // from the refetched task (and the live frame for other tabs).
  const addTaskParts = useCallback(
    async ({ id, parts }: NonNullable<CtaAction['patchTask']>) => {
      try {
        await mergeTaskParts(id, parts);
        loadTasks(state.selectedId);
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'ok', message: 'Référence ajoutée à la liste.' } });
      } catch (e) {
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec de l'ajout : ${(e as Error).message}` },
        });
      }
    },
    [state.selectedId, loadTasks],
  );

  // Edit a task's definition (type/label/target/dueDate) from the checklist row's inline form, then
  // refetch so the row + milestone bucketing settle.
  const editTask = useCallback(
    async (taskIri: string, fields: TaskEdit) => {
      try {
        await updateTask(taskIri, fields);
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

  // Add / edit / remove a single shopping-list line through the parts subresource, then refetch the
  // checklist. partId is the line's ULID (PlannedPart.id).
  const editTaskPart = useCallback(
    async (taskIri: string, partId: string, fields: TaskPartInput) => {
      try {
        await updateTaskPart(taskIri, partId, fields);
        loadTasks(state.selectedId);
      } catch (e) {
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de la mise à jour : ${(e as Error).message}` } });
      }
    },
    [state.selectedId, loadTasks],
  );

  const addPart = useCallback(
    async (taskIri: string, part: TaskPartInput) => {
      try {
        await addTaskPart(taskIri, part);
        loadTasks(state.selectedId);
      } catch (e) {
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de l'ajout : ${(e as Error).message}` } });
      }
    },
    [state.selectedId, loadTasks],
  );

  const removePart = useCallback(
    async (taskIri: string, partId: string) => {
      try {
        await removeTaskPart(taskIri, partId);
        loadTasks(state.selectedId);
      } catch (e) {
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec du retrait : ${(e as Error).message}` } });
      }
    },
    [state.selectedId, loadTasks],
  );

  // "+ Ajouter" in the parc: create a blank vehicle, then focus it so the editable card opens ready
  // to fill in. The Mercure 'created' frame also lands it for other tabs.
  const addCar = useCallback(async () => {
    try {
      const summary = await createVehicle();
      dispatch({ kind: 'upsertVehicle', summary });
      dispatch({ kind: 'action', action: { type: 'selectVehicle', id: summary['@id'] } });
    } catch (e) {
      dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de la création : ${(e as Error).message}` } });
    }
  }, []);

  // Soft-delete a vehicle: drop it locally at once (optimistic), the DELETE + Mercure 'deleted' frame
  // reconcile other tabs.
  const removeCar = useCallback(async (id: VehicleId) => {
    dispatch({ kind: 'removeVehicle', id });
    try {
      await deleteVehicle(id);
    } catch (e) {
      dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de la suppression : ${(e as Error).message}` } });
      loadVehicles();
    }
  }, [loadVehicles]);

  const addTask = useCallback(
    async (input: PlannedTaskInput) => {
      const id = state.selectedId;
      if (!id.startsWith('/')) return;
      try {
        await createTask(id, input);
        loadTasks(id);
      } catch (e) {
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de l'ajout de la tâche : ${(e as Error).message}` } });
      }
    },
    [state.selectedId, loadTasks],
  );

  const deleteFact = useCallback(
    async (key: string) => {
      const id = state.selectedId;
      if (!id.startsWith('/')) return;
      try {
        await deleteVehicleFact(id, key);
        loadDetail(id);
      } catch (e) {
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de la suppression : ${(e as Error).message}` } });
      }
    },
    [state.selectedId, loadDetail],
  );

  const addRecord = useCallback(
    async (input: MaintenanceRecordInput) => {
      const id = state.selectedId;
      if (!id.startsWith('/')) return;
      try {
        await createMaintenanceRecord(id, input);
        loadRecords(id);
        // Logging a past service may auto-close a matching open task — refresh the checklist too.
        loadTasks(id);
        // A new baseline shifts the next-due (and may fill a coverage gap) — refresh the forecast.
        loadForecast(id);
        // …and recomputes the health rollup (a filled gap lifts the score) — refresh the item too.
        loadDetail(id);
      } catch (e) {
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de l'enregistrement : ${(e as Error).message}` } });
      }
    },
    [state.selectedId, loadRecords, loadTasks, loadForecast, loadDetail],
  );

  const editRecord = useCallback(
    async (recordIri: string, input: MaintenanceRecordInput) => {
      const id = state.selectedId;
      try {
        await updateMaintenanceRecord(recordIri, input);
        loadRecords(id);
        loadTasks(id);
        loadForecast(id);
        loadDetail(id);
      } catch (e) {
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de la modification : ${(e as Error).message}` } });
      }
    },
    [state.selectedId, loadRecords, loadTasks, loadForecast, loadDetail],
  );

  const removeRecord = useCallback(
    async (recordIri: string) => {
      const id = state.selectedId;
      try {
        await deleteMaintenanceRecord(recordIri);
        loadRecords(id);
        loadForecast(id);
        loadDetail(id);
      } catch (e) {
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de la suppression : ${(e as Error).message}` } });
      }
    },
    [state.selectedId, loadRecords, loadForecast, loadDetail],
  );

  const uploadAttachment = useCallback(
    async (recordIri: string, file: File) => {
      const id = state.selectedId;
      try {
        await uploadMaintenanceAttachment(recordIri, file);
        loadRecords(id);
      } catch (e) {
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de l'ajout de la facture : ${(e as Error).message}` } });
      }
    },
    [state.selectedId, loadRecords],
  );

  const removeAttachment = useCallback(
    async (attachmentUrl: string) => {
      const id = state.selectedId;
      try {
        await deleteMaintenanceAttachment(attachmentUrl);
        loadRecords(id);
      } catch (e) {
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de la suppression de la facture : ${(e as Error).message}` } });
      }
    },
    [state.selectedId, loadRecords],
  );

  // Add or correct a recurring rule: POST it, then refetch the schedule (the row settles) AND the
  // forecast — a cadence change shifts the next-due. A 422 (no cadence) surfaces as its own toast.
  const saveRule = useCallback(
    async (input: ServiceRuleInput) => {
      const id = state.selectedId;
      if (!id.startsWith('/')) return;
      try {
        await saveServiceRule(id, input);
        loadSchedule(id);
        loadForecast(id);
      } catch (e) {
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de l'enregistrement de la règle : ${(e as Error).message}` } });
      }
    },
    [state.selectedId, loadSchedule, loadForecast],
  );

  const deleteRule = useCallback(
    async (ruleId: string) => {
      const id = state.selectedId;
      if (!id.startsWith('/')) return;
      try {
        await deleteServiceRule(id, ruleId);
        loadSchedule(id);
        loadForecast(id);
      } catch (e) {
        dispatch({ kind: 'action', action: { type: 'toast', tone: 'alarm', message: `Échec de la suppression de la règle : ${(e as Error).message}` } });
      }
    },
    [state.selectedId, loadSchedule, loadForecast],
  );

  const send = useCallback(
    async (instruction: string) => {
      // Go busy on dispatch and stay busy: the turn runs in an async worker, so the POST only acks
      // 202 (queued) — the worker clears busy with a `turn`/`end` cue on the session topic (onFrame).
      setBusy(true);
      try {
        // Carry the on-screen CTA (headline + finding + option labels) so a terse reply to it lands
        // in context on the next, otherwise-stateless turn. One bounded line, not a transcript.
        const cta = state.cta;
        const pendingContext = cta
          ? [cta.headline, cta.finding, cta.actions.length ? `Options : ${cta.actions.map((a) => a.label).join(', ')}` : '']
              .filter(Boolean)
              .join(' — ')
          : undefined;
        // A new turn supersedes the prior answer's surfaces (its context is already captured in
        // pendingContext above) — clear them so a stale CTA/panel doesn't linger under the new reply.
        dispatch({ kind: 'dismissCta' });
        dispatch({ kind: 'dismissFactPanel' });
        // No action list comes back: the agent streams its intents over the session topic.
        await postTurn(instruction, { sessionId, selectedId: state.selectedId, pendingContext });
      } catch (e) {
        // The turn never got queued, so no `end` cue is coming — clear busy here.
        setBusy(false);
        dispatch({
          kind: 'action',
          action: { type: 'toast', tone: 'alarm', message: `Échec de la requête : ${(e as Error).message}` },
        });
      }
    },
    [sessionId, state.selectedId, state.cta],
  );

  // Stop a running turn. Cooperative: the worker ends it at the next tool boundary and streams the
  // `turn`/`end` cue that clears busy — so we don't flip busy here, only ask.
  const cancel = useCallback(async () => {
    try {
      await cancelTurn(sessionId);
    } catch {
      // Best-effort: a failed cancel leaves the turn running; the hard cap is still the backstop.
    }
  }, [sessionId]);

  const onFrame = useCallback(
    (frame: MercureFrame) => {
      if ('type' in frame) {
        // Render intent on the session topic. The `turn` lifecycle cue drives the busy state (the
        // worker runs async, so the POST ack can't); step narration + progress toasts feed the
        // heartbeat line; errors and any non-toast intent (selectVehicle/showFact/proposeActions) go
        // to the reducer.
        if (frame.type === 'turn') {
          setBusy(frame.phase === 'start');
        } else if (frame.type === 'status') {
          // Running narration of the agent's current step — heartbeat only, no reducer state.
          pushStatus(frame.message);
        } else if (frame.type === 'toast' && frame.tone !== 'alarm') {
          pushStatus(frame.message);
        } else {
          dispatch({ kind: 'action', action: frame });
        }
        return;
      }
      // Domain event on a public vehicle topic — a write fanned out (this tab or another). The
      // payload carries the vehicle scalars + facts, so merge it straight in. The checklist + history
      // are their own subresources and NOT in the frame, so refetch them when the open vehicle changes.
      dispatch({ kind: 'live', message: `${frame.kind}/${frame.event} · ${frame.vehicle.slice(0, 8)}…` });
      clearTimeout(liveTimerRef.current);
      liveTimerRef.current = setTimeout(() => dispatch({ kind: 'clearLive' }), 8000);
      pushStatus(`Mise à jour live : ${frame.kind} ${frame.event}`);
      if (frame.event === 'deleted') {
        dispatch({ kind: 'removeVehicle', id: frame.resource['@id'] });
        return;
      }
      dispatch({ kind: 'upsertVehicle', summary: frame.resource });
      if (frame.resource['@id'] === selectedIdRef.current) {
        loadTasks(selectedIdRef.current);
        loadRecords(selectedIdRef.current);
        loadForecast(selectedIdRef.current);
        loadSchedule(selectedIdRef.current);
      }
    },
    [pushStatus, loadTasks, loadRecords, loadForecast, loadSchedule],
  );

  // The collection topic carries every vehicle's full-resource frame (the publisher fans out to
  // both the item and collection topics with the same payload), so subscribing to it alone covers
  // all vehicles — no per-item subscription that would only double-deliver the selected one.
  useMercure([`${API}/garage/vehicles`, `${API}/agent/sessions/${sessionId}`], onFrame);

  // Manual navigation: drop the prior answer's transient surfaces (the reducer no longer does — see
  // its comment), then focus the car.
  const onSelect = (id: VehicleId) => {
    dispatch({ kind: 'dismissCta' });
    dispatch({ kind: 'dismissFactPanel' });
    dispatch({ kind: 'action', action: { type: 'selectVehicle', id } });
  };

  return (
    <div className="app">
      <TopBar
        live={state.live}
        status={status}
        busy={busy}
        onAdvanceStatus={advanceStatus}
        onJournal={() => setJournalOpen(true)}
        vehicle={titleHidden ? selected : undefined}
      />
      <div className="body">
        <CarRail
          vehicles={state.vehicles}
          selectedId={state.selectedId}
          onSelect={onSelect}
          onAddCar={addCar}
          onRemoveCar={removeCar}
          loading={booting && state.vehicles.length === 0}
        />
        <main className="canvas">
          <div className="scroll">
            {booting && !selected ? (
              <VehicleDetailSkeleton />
            ) : selected ? (
              <VehicleDetail
                vehicle={selected}
                onEdit={editVehicle}
                onSetTaskStatus={setTaskStatus}
                onEditTask={editTask}
                onAddTaskPart={addPart}
                onEditTaskPart={editTaskPart}
                onRemoveTaskPart={removePart}
                onArchiveDone={archiveDoneTasks}
                onAddTask={addTask}
                tasksLoading={tasksLoading}
                showAllTasks={showAllTasks}
                onToggleShowAllTasks={toggleShowAllTasks}
                factPanel={state.factPanel}
                onExpandFacts={() => dispatch({ kind: 'expandFactPanel' })}
                onDismissFacts={() => dispatch({ kind: 'dismissFactPanel' })}
                onFillFact={fillFact}
                onDeleteFact={deleteFact}
                recordsLoading={recordsLoading}
                onAddRecord={addRecord}
                onEditRecord={editRecord}
                onDeleteRecord={removeRecord}
                onUploadAttachment={uploadAttachment}
                onDeleteAttachment={removeAttachment}
                forecastLoading={forecastLoading}
                scheduleLoading={scheduleLoading}
                onSaveRule={saveRule}
                onDeleteRule={deleteRule}
                onTitleVisibility={onTitleVisibility}
              />
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
              onAddParts={addTaskParts}
              onDismiss={() => dispatch({ kind: 'dismissCta' })}
            />
          ) : null}
          <Composer busy={busy} onSend={send} onCancel={cancel} />
        </main>
      </div>
      <Toasts toasts={state.toasts} onDismiss={(id) => dispatch({ kind: 'dismissToast', id })} />
      <JournalPanel open={journalOpen} onClose={() => setJournalOpen(false)} />
    </div>
  );
}
