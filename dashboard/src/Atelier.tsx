import { useCallback, useEffect, useRef, useState } from 'react';
import './tokens/atelier.css';
import './floor/floor.css';
import './scene/scene.css';
import './scene/parcpage.css';
import './auth.css'; // the error screen reuses the at-auth-* classes — own the dependency explicitly
import { API, getIdentity, logout } from './auth';
import { cancelTurn, postTurn } from './agent';
import {
  addTaskPart,
  archiveTasks,
  createTask,
  createVehicle,
  deleteVehicle,
  deleteVehicleFact,
  getVehicle,
  getVehicleBundle,
  getVehicles,
  getVehicleTasks,
  mergeTaskParts,
  patchTaskStatus,
  patchVehicle,
  postVehicleFact,
  removeTaskPart,
  updateTask,
  updateTaskPart,
  type PlannedTaskInput,
  type TaskEdit,
  type TaskPartInput,
  type VehiclePatch,
} from './garage';
import { defaultUsageUnit } from './edit';
import { hashForVehicle, segForIri, useHashPath, vehicleSegFromHash } from './router';
import { isDomainEvent, removeVehicle, upsertVehicle, useMercure } from './useMercure';
import { ParcLot } from './scene/ParcLot';
import { SceneView } from './scene/SceneView';
import type { TruthPanelEdit } from './floor/TruthPanel';
import { JournalPanel } from './JournalPanel';
import { DossierPanel } from './DossierPanel';
import { maintUnitFor } from './records';
import type { Cta } from './scene/CtaCard';
import { firstNewlyOverdue } from './scene/crossing';
import { useNoyau } from './noyau/useNoyau';
import { useReducedMotion } from './scene/useReducedMotion';
import { serviceLabel } from './labels';
import type { Iri, MercureFrame, PlannedPart, PlannedTask, VehicleBundle, VehicleSummary } from './types';

// The LIVE Atelier orchestrator (Stage C). Parc-first: the fleet grid at #/, selecting a car drives
// into the hero at #/v/<seg> (reload restores it). It owns the garage reads and Le Noyau's state, and
// renders the SAME pure SceneView the fixture playground does — only the data source differs. Mercure
// (slice 4) and the agent turn (slice 5) feed this orchestrator next; today the composer only listens.

export function Atelier() {
  const path = useHashPath();
  const seg = vehicleSegFromHash(path);
  const [vehicles, setVehicles] = useState<VehicleSummary[]>([]);
  const [bundle, setBundle] = useState<VehicleBundle | null>(null);
  const [loadingParc, setLoadingParc] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const { state, dispatch } = useNoyau();
  const reduced = useReducedMotion();
  // One session per tab — scopes the agent's render-intent topic (slice 5 posts turns onto it).
  const [sessionId] = useState(() => crypto.randomUUID());
  // Turn-scoped agent surfaces: a `proposeActions` card + a `showFact` row focus. Cleared on a new turn
  // (its context is captured in pendingContext first) and on manual navigation.
  const [cta, setCta] = useState<Cta | null>(null);
  const [factFocus, setFactFocus] = useState<{ keys: string[]; title?: string } | null>(null);
  const [journalOpen, setJournalOpen] = useState(false); // the agent debug journal drawer
  const [dossierOpen, setDossierOpen] = useState(false); // #11 records + schedule drawer (per-vehicle)
  // #10 checklist: "voir les tâches retirées" pulls a includeDismissed list (the bundle carries open+done).
  const [showDismissed, setShowDismissed] = useState(false);
  const [dismissedTasks, setDismissedTasks] = useState<PlannedTask[]>([]);

  // The parc collection — loaded once on mount; Mercure (slice 4) keeps it fresh thereafter.
  useEffect(() => {
    let live = true;
    getVehicles()
      .then((vs) => live && setVehicles(vs))
      .catch((e) => live && setError((e as Error).message))
      .finally(() => live && setLoadingParc(false));
    return () => {
      live = false;
    };
  }, []);

  // Resolve the selected seg to a full IRI against the loaded collection, then load its bundle. A
  // direct load of #/v/<seg> waits for the collection to arrive before it can resolve the IRI.
  const selectedIri = seg ? (vehicles.find((v) => segForIri(v['@id']) === seg)?.['@id'] ?? null) : null;
  useEffect(() => {
    setError(null); // a fresh selection recovers from a prior vehicle's load failure
    setCta(null); // the prior vehicle's transient agent surfaces don't linger across navigation
    setFactFocus(null);
    setShowDismissed(false); // each vehicle's checklist starts collapsed (open+done only)
    setDismissedTasks([]);
    setDossierOpen(false); // the dossier is per-vehicle — close it on navigation
    if (!selectedIri) {
      setBundle(null);
      return;
    }
    let live = true;
    getVehicleBundle(selectedIri)
      .then((b) => live && setBundle(b))
      .catch((e) => live && setError((e as Error).message));
    return () => {
      live = false;
    };
  }, [selectedIri]);

  // Only render the bundle when it belongs to the CURRENT selection: a car→car nav (browser back/forward
  // between two #/v/ hashes, or a future Mercure/CTA selectVehicle) changes selectedIri while the old
  // bundle is still in state — gating on the @id match shows "Chargement" instead of the previous car.
  const heroBundle = bundle && selectedIri && bundle.vehicle['@id'] === selectedIri ? bundle : null;

  // The live cascade. The publisher fans every garage write to the collection topic with the FULL
  // read-model in `resource`, so we reconcile state directly — no follow-up fetch (identical whether
  // the write came from this tab, another tab, or the agent: the room is shared). Session-topic frames
  // drive Le Noyau (the agent posts onto this topic in slice 5). The forecast-bound deep visuals
  // (rim/hotspot live-refresh) + the §9 crossing are wired in the tail.
  const selectedIriRef = useRef<string | null>(null);
  selectedIriRef.current = selectedIri;
  // Refs for the async cascade: the current bundle (to diff old→new forecast for the §9 crossing) and
  // the current Noyau phase (so a between-turn crossing murmur auto-settles without breaking a live turn).
  const bundleRef = useRef<VehicleBundle | null>(null);
  bundleRef.current = bundle;
  const phaseRef = useRef(state.phase);
  phaseRef.current = state.phase;
  const crossTimer = useRef<number | null>(null);

  // Liveness watchdog: the turn runs async on the worker, so an optimistic turn/start could otherwise
  // hang Le Noyau in 'working' forever if the worker dies / a frame is dropped after the 202 (the
  // cooperative cancel can't rescue a dead worker either). Any session frame re-arms it (a live worker
  // never trips it); only true silence settles the orb with a warn.
  const watchdog = useRef<number | null>(null);
  const clearWatchdog = useCallback(() => {
    if (watchdog.current != null) {
      window.clearTimeout(watchdog.current);
      watchdog.current = null;
    }
  }, []);
  const armWatchdog = useCallback(() => {
    clearWatchdog();
    watchdog.current = window.setTimeout(() => {
      // toast BEFORE turn/end so the done filament settles amber (worstTone), not a false green.
      dispatch({ type: 'toast', tone: 'warn', message: "Le tour ne répond plus — réessayez." });
      dispatch({ type: 'turn', phase: 'end' });
    }, 90_000);
  }, [clearWatchdog, dispatch]);
  useEffect(() => clearWatchdog, [clearWatchdog]); // clear on unmount
  useEffect(() => () => { if (crossTimer.current != null) window.clearTimeout(crossTimer.current); }, []);

  const onFrame = useCallback(
    (frame: MercureFrame) => {
      if (isDomainEvent(frame)) {
        if (frame.event === 'deleted') {
          setVehicles((prev) => removeVehicle(prev, frame.iri));
          if (frame.iri === selectedIriRef.current) {
            setBundle(null);
            window.location.hash = '#/'; // the open car is gone — fall back to the parc
          }
          return;
        }
        setVehicles((prev) => upsertVehicle(prev, frame.resource));
        if (frame.resource['@id'] === selectedIriRef.current) {
          // INSTANT cascade: merge the fresh scalars/health/facts (health bars + cardinals animate now).
          setBundle((prev) => (prev ? { ...prev, vehicle: { ...prev.vehicle, ...frame.resource } } : prev));
          // DEEP refresh (open vehicle only — the parc stays fetch-free): the frame omits forecast/
          // records/plannedTasks, so re-read them so the rim/hotspots/établi/échéances reflect the write.
          const iri = frame.resource['@id'];
          const prevDue = bundleRef.current?.forecast.due ?? [];
          getVehicleBundle(iri)
            .then((b) => {
              if (selectedIriRef.current !== iri) return; // navigated away — drop the stale refetch
              // §9 hands-off wow: a service that just crossed into overdue docks an ember + murmurs on
              // Le Noyau (NOT an agent turn). The red bodywork (rim/hotspot/panel) rides the new bundle.
              const crossed = firstNewlyOverdue(prevDue, b.forecast.due);
              setBundle(b);
              if (crossed) {
                dispatch({ type: 'concern', message: `${serviceLabel(crossed)} en retard` });
                if (crossTimer.current != null) window.clearTimeout(crossTimer.current);
                crossTimer.current = window.setTimeout(() => {
                  if (phaseRef.current !== 'working') dispatch({ type: 'settle' }); // don't cut a live turn
                }, 2000);
              }
            })
            .catch(() => undefined);
        }
        return;
      }
      // Session frame (a UIAction). Any frame proves the worker is alive → re-arm the watchdog (turn/end
      // settles + clears it). turn/status/toast drive Le Noyau; the reducer-state members drive the
      // choreography per CHOREOGRAPHY.md (selectVehicle → drive-forward, showFact → truth-panel etch,
      // proposeActions → CTA rises from the cradle). Hidden chat — never a transcript.
      if (frame.type === 'turn') {
        dispatch({ type: 'turn', phase: frame.phase });
        if (frame.phase === 'end') clearWatchdog();
        else armWatchdog();
      } else if (frame.type === 'status') {
        dispatch({ type: 'status', message: frame.message });
        armWatchdog();
      } else if (frame.type === 'toast') {
        dispatch({ type: 'toast', tone: frame.tone, message: frame.message });
        armWatchdog();
      } else if (frame.type === 'selectVehicle') {
        window.location.hash = hashForVehicle(frame.id); // drive-forward into the named car
      } else if (frame.type === 'showFact') {
        setFactFocus({ keys: frame.keys, title: frame.title });
      } else if (frame.type === 'proposeActions') {
        setCta({ headline: frame.headline, finding: frame.finding, actions: frame.actions });
      } else if (frame.type === 'setVehicles') {
        setVehicles(frame.vehicles);
        if (frame.preferredId) window.location.hash = hashForVehicle(frame.preferredId);
      }
    },
    [dispatch, armWatchdog, clearWatchdog],
  );
  useMercure([`${API}/garage/vehicles`, `${API}/agent/sessions/${sessionId}`], onFrame);

  // Post an agent turn. The worker is async (POST acks 202), so the Noyau goes to work optimistically;
  // the real turn/start frame re-confirms and turn/end settles it. A transport failure (never queued)
  // clears the optimistic working state and surfaces an alarm. `summary` is a one-line on-screen state
  // (NOT a transcript); pendingContext (the CTA being replied to) is wired with the CTA layer in slice 6.
  const send = (instruction: string) => {
    if (state.phase === 'working') return; // one turn per session — the ✕ cancels first (no concurrent turns)
    // Carry the CTA being replied to as one bounded line (NOT a transcript), so a terse reply lands in
    // context against the question the agent just asked. Capture BEFORE clearing the surfaces.
    const pendingContext = cta
      ? [cta.headline, cta.finding, cta.actions.length ? `Options : ${cta.actions.map((a) => a.label).join(', ')}` : '']
          .filter(Boolean)
          .join(' — ')
      : undefined;
    setCta(null);
    setFactFocus(null);
    dispatch({ type: 'turn', phase: 'start' });
    armWatchdog();
    const v = heroBundle?.vehicle;
    const summary = v
      ? `${v.make} ${v.model}${v.plate ? ` · ${v.plate}` : ''}${v.health ? ` · ${v.health.status}` : ''}`
      : undefined;
    postTurn(instruction, { sessionId, selectedId: selectedIri ?? undefined, summary, pendingContext }).catch((e) => {
      clearWatchdog();
      // toast BEFORE turn/end so the done filament settles red (worstTone), not a false green.
      dispatch({ type: 'toast', tone: 'alarm', message: `Échec de la requête : ${(e as Error).message}` });
      dispatch({ type: 'turn', phase: 'end' });
    });
  };

  // CTA action paths. `instruction` re-runs the agent (a fresh turn, supersedes the card). `patchTask`
  // is a DETERMINISTIC parts merge — no agent turn — then re-reads the open bundle so the établi/
  // échéances reflect the merge (the domain frame omits plannedTasks; see PROVENANCE).
  const onCtaInstruction = (instruction: string) => send(instruction);
  const onCtaPatch = (patch: { id: Iri; parts: PlannedPart[] }) => {
    setCta(null);
    const iri = selectedIri;
    if (!iri) return;
    mergeTaskParts(patch.id, patch.parts)
      .then(() => getVehicleBundle(iri))
      // Guard POST-await so a re-read landing after navigation can't overwrite the now-open vehicle.
      .then((b) => setBundle((prev) => (prev && prev.vehicle['@id'] === iri ? b : prev)))
      .catch((e) => dispatch({ type: 'toast', tone: 'alarm', message: `Échec de l’ajout : ${(e as Error).message}` }));
  };

  // ── #9 Vehicle CRUD + facts (the manual surfaces; the agent does the same via NL). Every write also
  // fans a Mercure domain frame (other tabs cascade too); here we update optimistically + re-read the
  // bundle for the subresources/forecast a frame omits. Errors surface as an alarm on Le Noyau, never
  // a silent failure. selectedIriRef guards an async result that lands after navigation.
  const alarm = (message: string) => dispatch({ type: 'toast', tone: 'alarm', message });

  const onAddVehicle = () => {
    createVehicle()
      .then((v) => {
        setVehicles((prev) => upsertVehicle(prev, v)); // Mercure 'created' also fans it; optimistic so the nav resolves now
        window.location.hash = hashForVehicle(v['@id']); // drive into the blank car to fill it in
      })
      .catch((e) => alarm(`Échec de la création : ${(e as Error).message}`));
  };

  const onPatchVehicle = (patch: VehiclePatch) => {
    const iri = selectedIri;
    if (!iri) return;
    setBundle((prev) => (prev && prev.vehicle['@id'] === iri ? { ...prev, vehicle: { ...prev.vehicle, ...patch } } : prev)); // optimistic
    patchVehicle(iri, patch)
      .then(() => getVehicleBundle(iri)) // re-read: a usage change shifts the forecast
      // Guard POST-await (the updater pattern, like the facts re-reads): a bundle that resolves after a
      // nav must NOT overwrite the now-open vehicle (defeats the heroBundle @id gate → stuck loading).
      .then((b) => setBundle((prev) => (prev && prev.vehicle['@id'] === iri ? b : prev)))
      .catch((e) => {
        alarm(`Échec de la mise à jour : ${(e as Error).message}`);
        getVehicleBundle(iri)
          .then((b) => setBundle((prev) => (prev && prev.vehicle['@id'] === iri ? b : prev))) // reconcile the optimistic merge
          .catch(() => undefined);
      });
  };

  const onDeleteVehicle = () => {
    const iri = selectedIri;
    if (!iri) return;
    deleteVehicle(iri)
      .then(() => {
        setVehicles((prev) => removeVehicle(prev, iri));
        setBundle(null);
        window.location.hash = '#/'; // the car is gone — back to the parc
      })
      .catch((e) => alarm(`Échec de la suppression : ${(e as Error).message}`));
  };

  const onAddFact = (fact: { key: string; value: string; unit?: string | null }) => {
    const iri = selectedIri;
    if (!iri) return;
    postVehicleFact(iri, fact)
      .then((fresh) =>
        setBundle((prev) => (prev && prev.vehicle['@id'] === iri ? { ...prev, vehicle: { ...prev.vehicle, facts: fresh.facts, factCount: fresh.factCount } } : prev)),
      )
      .catch((e) => alarm(`Échec de l’ajout : ${(e as Error).message}`));
  };

  const onRemoveFact = (key: string) => {
    const iri = selectedIri;
    if (!iri) return;
    setBundle((prev) =>
      prev && prev.vehicle['@id'] === iri ? { ...prev, vehicle: { ...prev.vehicle, facts: (prev.vehicle.facts ?? []).filter((f) => f.key !== key) } } : prev,
    ); // optimistic
    deleteVehicleFact(iri, key)
      // Re-read: a deleted user fact may unmask a projected (part-derived/catalog) value for the same key.
      .then(() =>
        selectedIriRef.current === iri
          ? getVehicle(iri).then((v) => setBundle((prev) => (prev && prev.vehicle['@id'] === iri ? { ...prev, vehicle: { ...prev.vehicle, facts: v.facts, factCount: v.factCount } } : prev)))
          : undefined,
      )
      .catch((e) => {
        alarm(`Échec de la suppression : ${(e as Error).message}`);
        if (selectedIriRef.current === iri)
          getVehicle(iri).then((v) => setBundle((prev) => (prev && prev.vehicle['@id'] === iri ? { ...prev, vehicle: { ...prev.vehicle, facts: v.facts } } : prev))).catch(() => undefined);
      });
  };

  // Editing a fact value re-records the key as a source "user" fact (postVehicleFact upserts by key).
  const onEditFact = (key: string, value: string, unit?: string | null) => onAddFact({ key, value, unit });

  const edit: TruthPanelEdit = {
    onPatch: onPatchVehicle,
    onDelete: onDeleteVehicle,
    facts: { onAdd: onAddFact, onRemove: onRemoveFact, onEditValue: onEditFact },
  };

  // ── #10 Task checklist. Task writes hit the /planned-tasks subresource; the domain frame OMITS
  // plannedTasks (see PROVENANCE), so each write re-reads the bundle explicitly (guarded post-await),
  // plus the includeDismissed list when the "voir les retirées" view is open.
  const showDismissedRef = useRef(showDismissed);
  showDismissedRef.current = showDismissed;

  const afterTaskWrite = (iri: string) => {
    getVehicleBundle(iri)
      .then((b) => setBundle((prev) => (prev && prev.vehicle['@id'] === iri ? b : prev)))
      .catch(() => undefined);
    if (showDismissedRef.current)
      getVehicleTasks(iri, true)
        .then((ts) => selectedIriRef.current === iri && setDismissedTasks(ts))
        .catch(() => undefined);
  };

  // Run a task write against the open vehicle, then refresh; guards a null selection BEFORE the op fires.
  const taskWrite = (op: (vehicleIri: string) => Promise<unknown>, failMsg: string) => {
    const iri = selectedIri;
    if (!iri) return;
    op(iri)
      .then(() => afterTaskWrite(iri))
      .catch((e) => alarm(`${failMsg} : ${(e as Error).message}`));
  };

  const onToggleDismissed = () => {
    const iri = selectedIri;
    const next = !showDismissed;
    setShowDismissed(next);
    if (next && iri)
      getVehicleTasks(iri, true)
        .then((ts) => selectedIriRef.current === iri && setDismissedTasks(ts))
        .catch((e) => alarm(`Échec du chargement : ${(e as Error).message}`));
  };

  const checklist = {
    showDismissed,
    onToggleDismissed,
    onSetStatus: (taskIri: string, status: 'open' | 'done' | 'dismissed') => taskWrite(() => patchTaskStatus(taskIri, status), 'Échec de la mise à jour'),
    onEditTask: (taskIri: string, fields: TaskEdit) => taskWrite(() => updateTask(taskIri, fields), 'Échec de la mise à jour'),
    onAddTask: (input: PlannedTaskInput) => taskWrite((iri) => createTask(iri, input), 'Échec de l’ajout'),
    onAddPart: (taskIri: string, part: TaskPartInput) => taskWrite(() => addTaskPart(taskIri, part), 'Échec de l’ajout'),
    onEditPart: (taskIri: string, partId: string, fields: TaskPartInput) => taskWrite(() => updateTaskPart(taskIri, partId, fields), 'Échec de la mise à jour'),
    onRemovePart: (taskIri: string, partId: string) => taskWrite(() => removeTaskPart(taskIri, partId), 'Échec de la suppression'),
    onArchiveDone: () => taskWrite((iri) => archiveTasks(iri), 'Échec de l’archivage'),
  };

  if (error) {
    return (
      <div className="at-auth-screen">
        <div className="at-auth-card">
          <h1>Erreur de chargement</h1>
          <p>{error}</p>
          <div style={{ display: 'flex', gap: 'var(--at-space-3)' }}>
            <button type="button" className="at-auth-button" onClick={() => location.reload()}>
              Réessayer
            </button>
            <button
              type="button"
              className="at-auth-button"
              onClick={() => {
                setError(null);
                window.location.hash = '#/';
              }}
            >
              ← Le Parc
            </button>
          </div>
        </div>
      </div>
    );
  }

  // ── Hero route (#/v/<seg>) ──────────────────────────────────────────────────
  if (seg) {
    const vehicleMissing = !selectedIri && !loadingParc && vehicles.length > 0;
    return (
      <div className="at-scene">
        <div className="at-scene-top">
          <a href="#/" className="at-scene-back">← Le Parc</a>
          {heroBundle && (
            <span className="at-scene-back" style={{ color: 'var(--at-text)' }}>
              {heroBundle.vehicle.make} {heroBundle.vehicle.model}
              {heroBundle.vehicle.plate ? ` · ${heroBundle.vehicle.plate}` : ''}
            </span>
          )}
          <span style={{ flex: 1 }} />
          <Identity onJournal={() => setJournalOpen(true)} onDossier={heroBundle ? () => setDossierOpen(true) : undefined} />
        </div>

        <JournalPanel open={journalOpen} onClose={() => setJournalOpen(false)} />
        {heroBundle && (
          <DossierPanel
            open={dossierOpen}
            onClose={() => setDossierOpen(false)}
            vehicleIri={heroBundle.vehicle['@id']}
            records={heroBundle.records}
            defaultUnit={maintUnitFor(heroBundle.vehicle.usageUnit)}
            onChanged={() => {
              const iri = selectedIriRef.current;
              if (iri) getVehicleBundle(iri).then((b) => setBundle((prev) => (prev && prev.vehicle['@id'] === iri ? b : prev))).catch(() => undefined);
            }}
            onError={alarm}
          />
        )}

        {vehicleMissing ? (
          <div className="at-scene-main">
            <div className="at-scene-empty">Véhicule introuvable — <a href="#/" className="at-scene-back">retour au parc</a></div>
          </div>
        ) : !heroBundle ? (
          <div className="at-scene-main">
            <div className="at-scene-empty">Chargement du véhicule…</div>
          </div>
        ) : (
          <SceneView
            bundle={heroBundle}
            rung="b"
            reduced={reduced}
            noyau={state}
            onClearEmber={() => dispatch({ type: 'clearEmber' })}
            onComposerFocus={(active) => dispatch({ type: 'typing', active })}
            onSubmit={send}
            cta={cta}
            onCtaInstruction={onCtaInstruction}
            onCtaPatch={onCtaPatch}
            onCtaDismiss={() => setCta(null)}
            focusKeys={factFocus?.keys}
            edit={edit}
            checklist={{
              ...checklist,
              tasks: showDismissed ? dismissedTasks : heroBundle.vehicle.plannedTasks ?? [],
              defaultUnit: heroBundle.vehicle.usageUnit ?? defaultUsageUnit(heroBundle.vehicle.vehicleType),
            }}
            composerExtras={
              state.phase === 'working' ? (
                <button
                  type="button"
                  className="at-scene-sim"
                  title="Interrompre le tour"
                  aria-label="Interrompre le tour"
                  onClick={() => void cancelTurn(sessionId).catch(() => undefined)}
                >
                  ✕
                </button>
              ) : undefined
            }
          />
        )}
      </div>
    );
  }

  // ── Parc landing (#/) ───────────────────────────────────────────────────────
  return (
    <div className="at-parc-page">
      <div className="at-parc-page-top">
        <h1 className="at-parc-page-title" style={{ margin: 0 }}>Le Parc</h1>
        <span style={{ flex: 1 }} />
        <Identity onJournal={() => setJournalOpen(true)} />
      </div>
      <JournalPanel open={journalOpen} onClose={() => setJournalOpen(false)} />
      {loadingParc ? (
        <p className="at-parc-empty">Chargement du parc…</p>
      ) : (
        <ParcLot vehicles={vehicles} reduced={reduced} onSelect={(iri) => (window.location.hash = hashForVehicle(iri))} onAddVehicle={onAddVehicle} />
      )}
    </div>
  );
}

/** The signed-in identity + the dossier (📁, hero only) + the journal (🪵) + sign-out (top-right chrome). */
function Identity({ onJournal, onDossier }: { onJournal: () => void; onDossier?: () => void }) {
  const id = getIdentity();
  const chip: React.CSSProperties = {
    appearance: 'none',
    background: 'transparent',
    border: '1px solid var(--at-hairline)',
    color: 'var(--at-text-dim)',
    borderRadius: 5,
    padding: '2px 8px',
    cursor: 'pointer',
    font: 'inherit',
  };
  return (
    <span className="at-scene-back" style={{ display: 'inline-flex', gap: 10, alignItems: 'center' }}>
      <span>{id.name ?? id.email ?? 'connecté'}</span>
      {onDossier && (
        <button type="button" onClick={onDossier} style={chip} title="Dossier du véhicule" aria-label="Ouvrir le dossier du véhicule">
          📁 dossier
        </button>
      )}
      <button type="button" onClick={onJournal} style={chip} title="Journal de l'agent" aria-label="Ouvrir le journal de l'agent">
        🪵 journal
      </button>
      <button type="button" onClick={() => logout()} style={chip}>
        Déconnexion
      </button>
    </span>
  );
}
