import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { useT } from '../i18n/LocaleProvider';
import { getIdentity } from '../auth';
import type { OwnerTopics } from '../agent';
import { ALL_CAPABILITIES, cancelTurn, dashboardSubscriptions, fetchAgentLink, NO_OWNER_TOPICS, postTurn, tabSessionId } from '../agent';
import { createTask, getVehicleBundle, getVehicles, mergeTaskParts } from '../garage';

const PREFETCH_CONCURRENCY = 3;
import { pickAcknowledgement } from '../noyau/acknowledge';
import { drawGreeting, greetingForSelection, nothingDrawn, type Drawn } from '../noyau/greeting';
import { busy } from '../noyau/state';
import { agentAnswered, type TurnHeard } from '../noyau/cues';
import { WORKSHOP_PALETTE } from '../noyau/palettes';
import { useCues } from '../noyau/useCues';
import { useNoticeAutoClose } from '../noyau/useNoticeAutoClose';
import { useNoyau } from '../noyau/useNoyau';
import { useVoice, useVoicePreference } from '../noyau/useVoice';
import { ABANDON_MS, STAGE_INIT, stageReduce, stageTargetLeft, type Driver, type StageState } from '../pilot';
import { hashForVehicle, segForIri, useHashPath, vehicleSegFromHash } from '../router';
import { useReducedMotion } from '../scene/useReducedMotion';
import { DWELL, PILOT_QUIET_MS } from '../tokens/motion';
import type { Capabilities, CtaAction, Iri, MercureFrame, UIAction, VehicleBundle, VehicleSummary } from '../types';
import { isDomainEvent, reconcileVehicleEvent, removeVehicle, upsertVehicle, useMercure } from '../useMercure';
import { createBundleSeq, nextPrefetch } from './bundleLoads';
import { scanIntents } from './docScan';
import { liveEdits } from './edits';
import { useCommandStatus, useFlag, useTheme } from './hooks';
import { SOUND_KEY, VOICE_ON_KEY } from './prefs';
import type { JobItem } from './modules';
import { initialView, reduceView } from './view';
import { WorkshopView } from './WorkshopView';

// The live Workshop: the same data and transport the legacy Atelier drives (garage reads, Mercure,
// the agent turn, the pilot staging gate), rendered through the Workshop view.
export function Workshop() {
  const t = useT();
  const path = useHashPath();
  const seg = vehicleSegFromHash(path);
  const reduced = useReducedMotion();
  const [theme, flipTheme] = useTheme();
  const [vehicles, setVehicles] = useState<VehicleSummary[]>([]);
  const [bundles, setBundles] = useState<Record<Iri, VehicleBundle>>({});
  const [loadingParc, setLoadingParc] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [view, dispatchView] = useReducer(reduceView, initialView);
  const { state, dispatch } = useNoyau();
  const { status } = useCommandStatus(state);
  const [sessionId] = useState(() => tabSessionId(window.sessionStorage, () => crypto.randomUUID()));
  const [ownerTopics, setOwnerTopics] = useState<OwnerTopics>(NO_OWNER_TOPICS);
  const [capabilities, setCapabilities] = useState<Capabilities>(ALL_CAPABILITIES);
  const [now, setNow] = useState(() => new Date());
  const [voice, setVoice] = useVoicePreference();
  const listening = state.phase === 'listening';
  const card = view.proposal ? { headline: view.proposal.headline, finding: view.proposal.finding } : null;
  const [soundOn, setSoundOn] = useFlag(SOUND_KEY, true);
  const [voiceOn, setVoiceOn] = useFlag(VOICE_ON_KEY, true);
  const audio = useMemo(
    () => ({ sound: soundOn, onSound: setSoundOn, voiceOn, onVoiceOn: setVoiceOn, voiceAvailable: capabilities.voice }),
    [soundOn, setSoundOn, voiceOn, setVoiceOn, capabilities.voice],
  );
  const speakingId = useVoice(state.notices, state.narration, state.narrationOnce, card, !listening && voiceOn && capabilities.voice, voice);
  const working = busy(state);
  const heard: TurnHeard = { narration: state.narration, notices: state.notices.length, proposal: view.proposal !== null, scans: view.scanSeq };
  const turnHeard = useRef<TurnHeard | null>(null);
  if (!working) turnHeard.current = null;
  else turnHeard.current ??= heard;
  const answered = turnHeard.current !== null && agentAnswered(turnHeard.current, heard);
  const cues = useCues(soundOn, { busy: working, speaking: null !== speakingId, listening, answered }, WORKSHOP_PALETTE);
  useNoticeAutoClose(state.notices, speakingId, DWELL.noticeRead, (id) => dispatch({ type: 'dismissNotice', id }));

  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), 60_000);
    return () => window.clearInterval(id);
  }, []);

  useEffect(() => {
    let live = true;
    fetchAgentLink(sessionId).then((link) => {
      if (!live) return;
      setOwnerTopics({ topic: link.topic, fleetTopic: link.fleetTopic, sessionTopic: link.sessionTopic });
      setCapabilities(link.capabilities);
    });
    return () => {
      live = false;
    };
  }, [sessionId]);

  useEffect(() => {
    let live = true;
    getVehicles()
      .then((vs) => live && setVehicles(vs))
      .catch((e) => live && setLoadError((e as Error).message))
      .finally(() => live && setLoadingParc(false));
    return () => {
      live = false;
    };
  }, []);

  const selectedIri = seg ? (vehicles.find((v) => segForIri(v['@id']) === seg)?.['@id'] ?? null) : null;
  const selectedRef = useRef<Iri | null>(null);
  selectedRef.current = selectedIri;
  const bundlesRef = useRef(bundles);
  bundlesRef.current = bundles;
  const vehiclesRef = useRef(vehicles);
  vehiclesRef.current = vehicles;

  const busyRef = useRef(false);
  busyRef.current = busy(state);
  const greetedRef = useRef<string | null | undefined>(undefined);
  const drawnRef = useRef<Drawn>(nothingDrawn);
  const heroBundle = selectedIri ? bundles[selectedIri] : undefined;
  useEffect(() => {
    const next: string | null | undefined = seg === null ? null : heroBundle ? heroBundle.vehicle['@id'] : undefined;
    if (next === undefined) return;
    const name = heroBundle ? [heroBundle.vehicle.make, heroBundle.vehicle.model].filter(Boolean).join(' ') : undefined;
    const firstSettle = greetedRef.current === undefined;
    const greeting = greetingForSelection(greetedRef.current, next, name, busyRef.current);
    greetedRef.current = next;
    if (!greeting) return;
    const drawing = drawGreeting(greeting, t.greeting, { drawn: drawnRef.current, operator: getIdentity().name, rand: Math.random });
    drawnRef.current = drawing.drawn;
    dispatch({ type: 'greet', message: drawing.text });
    if (firstSettle) cues.opening();
  }, [seg, heroBundle, dispatch, t, cues.opening]);

  const putBundle = useCallback((iri: Iri, b: VehicleBundle) => setBundles((prev) => ({ ...prev, [iri]: b })), []);
  // Every bundle read goes through here: a slower, older read of the same car never lands over a newer one.
  const bundleSeq = useRef(createBundleSeq());
  const loadBundle = useCallback(
    (iri: Iri): Promise<void> => {
      const ticket = bundleSeq.current.begin(iri);
      return getVehicleBundle(iri).then((b) => {
        if (bundleSeq.current.accept(iri, ticket)) putBundle(iri, b);
      });
    },
    [putBundle],
  );

  // A paper slide on every vehicle or section change, whoever made it; the first paint is not a change.
  const shownSeg = useRef(seg);
  const shownSection = useRef(view.section);
  useEffect(() => {
    if (shownSeg.current === seg && shownSection.current === view.section) return;
    shownSeg.current = seg;
    shownSection.current = view.section;
    cues.play('select');
  }, [seg, view.section, cues.play]);
  const onPoint = useCallback(() => cues.play('point'), [cues.play]);

  // The car page always re-reads its bundle; the parc home reads every car once so its modules can
  // show the last visit and the inspection date, which the listing does not carry.
  useEffect(() => {
    dispatchView({ type: 'reset' });
    setLoadError(null);
    if (!selectedIri) return;
    let live = true;
    loadBundle(selectedIri).catch((e) => live && setLoadError((e as Error).message));
    return () => {
      live = false;
    };
  }, [selectedIri, loadBundle]);

  // The parc prefetch: each car read once, a few at a time. Driven by refs so a fleet update (every
  // domain event) tops the queue up instead of restarting every read.
  const prefetching = useRef(new Set<Iri>());
  const prefetched = useRef(new Set<Iri>());
  const onParc = useRef(seg === null);
  onParc.current = seg === null;
  const pumpPrefetch = useCallback(() => {
    if (!onParc.current) return;
    const candidates = vehiclesRef.current.map((v) => v['@id']);
    const skip = (iri: Iri) => prefetched.current.has(iri) || bundlesRef.current[iri] !== undefined;
    for (const iri of nextPrefetch(candidates, skip, prefetching.current, PREFETCH_CONCURRENCY)) {
      prefetching.current.add(iri);
      prefetched.current.add(iri);
      loadBundle(iri)
        .catch(() => undefined)
        .finally(() => {
          prefetching.current.delete(iri);
          pumpPrefetch();
        });
    }
  }, [loadBundle]);
  useEffect(pumpPrefetch, [seg, vehicles, pumpPrefetch]);

  // ── Liveness: the in-app turn's watchdog and the pilot's quiet timer (same policy as Atelier). ──
  const watchdog = useRef<number | null>(null);
  const quietTimer = useRef<number | null>(null);
  const clearTimer = (ref: { current: number | null }) => {
    if (ref.current != null) window.clearTimeout(ref.current);
    ref.current = null;
  };
  const armWatchdog = useCallback(() => {
    clearTimer(watchdog);
    watchdog.current = window.setTimeout(() => {
      dispatch({ type: 'toast', tone: 'warn', message: t.atelier.turnStalled });
      dispatch({ type: 'turn', phase: 'end', failed: true });
      cues.play('blocked');
    }, DWELL.watchdog);
  }, [dispatch, t, cues.play]);
  const openRun = useCallback(
    (driver: Driver) => {
      if (driver === 'pilot') {
        clearTimer(watchdog);
        clearTimer(quietTimer);
        quietTimer.current = window.setTimeout(() => dispatch({ type: 'quiet' }), PILOT_QUIET_MS);
      } else {
        clearTimer(quietTimer);
        armWatchdog();
      }
    },
    [armWatchdog, dispatch],
  );
  const closeRun = useCallback(() => {
    clearTimer(watchdog);
    clearTimer(quietTimer);
  }, []);
  useEffect(() => closeRun, [closeRun]);

  // ── Render intents: the staging gate holds per-vehicle surfaces until their car is on screen. ──
  const applyRender = useCallback(
    (frame: UIAction) => {
      if (frame.type === 'selectVehicle') {
        if (frame.id !== selectedRef.current) {
          cues.cancelOpening();
          window.location.hash = hashForVehicle(frame.id);
        }
        return;
      }
      if (frame.type === 'proposeActions') cues.play('card');
      else if (
        frame.type === 'showParts' ||
        frame.type === 'showDocument' ||
        frame.type === 'focusView' ||
        frame.type === 'showFact' ||
        frame.type === 'showHistory' ||
        frame.type === 'revealAssembly'
      )
        cues.play('point');
      dispatchView(frame);
    },
    [cues.cancelOpening, cues.play],
  );

  const stage = useRef<StageState>(STAGE_INIT);
  const expireTimer = useRef<number | null>(null);
  const runStage = useCallback(
    (ev: Parameters<typeof stageReduce>[1]) => {
      const out = stageReduce(stage.current, ev);
      stage.current = out.state;
      out.emit.forEach(applyRender);
      if (out.dropped) dispatch({ type: 'toast', tone: 'warn', message: t.atelier.answerDropped(String(out.dropped)) });
      clearTimer(expireTimer);
      if (stage.current.awaiting !== null) expireTimer.current = window.setTimeout(() => runStage({ type: 'expire', at: performance.now() }), ABANDON_MS);
    },
    [applyRender, dispatch, t],
  );
  useEffect(() => () => clearTimer(expireTimer), []);

  const heroIri = selectedIri && bundles[selectedIri] ? selectedIri : null;
  useEffect(() => {
    if (stageTargetLeft(stage.current.awaiting, seg)) runStage({ type: 'reset' });
  }, [seg, runStage]);
  useEffect(() => {
    if (heroIri && stage.current.awaiting === heroIri) runStage({ type: 'arrived', iri: heroIri, at: performance.now() });
  }, [heroIri, runStage]);

  const onFrame = useCallback(
    (frame: MercureFrame, driver: Driver) => {
      if (isDomainEvent(frame)) {
        if (frame.event === 'deleted') {
          setVehicles((prev) => removeVehicle(prev, frame.iri));
          if (frame.iri === selectedRef.current) window.location.hash = '#/';
          return;
        }
        if (reconcileVehicleEvent(vehiclesRef.current, frame.resource).refetch) {
          getVehicles()
            .then(setVehicles)
            .catch(() => undefined);
          return;
        }
        setVehicles((prev) => reconcileVehicleEvent(prev, frame.resource).list);
        const iri = frame.resource['@id'];
        if (bundlesRef.current[iri]) {
          setBundles((prev) => (prev[iri] ? { ...prev, [iri]: { ...prev[iri], vehicle: { ...prev[iri].vehicle, ...frame.resource } } } : prev));
          if (iri === selectedRef.current) loadBundle(iri).catch(() => undefined);
        }
        return;
      }
      if (frame.type === 'turn') {
        dispatch({ type: 'turn', phase: frame.phase, driver });
        if (frame.phase === 'end') {
          closeRun();
          cues.play(frame.outcome === 'failed' ? 'blocked' : 'complete');
        } else openRun(driver);
      } else if (frame.type === 'status') {
        dispatch({ type: 'status', message: frame.message, tool: frame.tool, driver });
        dispatchView(frame);
        openRun(driver);
      } else if (frame.type === 'toast') {
        dispatch({ type: 'toast', tone: frame.tone, message: frame.message, driver });
        openRun(driver);
      } else if (frame.type === 'documentScan') {
        for (const action of scanIntents(frame, selectedRef.current)) runStage({ type: 'frame', action, shownIri: selectedRef.current, at: performance.now() });
      } else {
        runStage({ type: 'frame', action: frame, shownIri: selectedRef.current, at: performance.now() });
      }
    },
    [closeRun, cues.play, dispatch, openRun, loadBundle, runStage],
  );
  useMercure(dashboardSubscriptions(ownerTopics), onFrame);

  // ── The command bar ──
  const lastAck = useRef(-1);
  const send = useCallback(
    (instruction: string) => {
      if (busy(state)) return;
      const p = view.proposal;
      const pendingContext = p ? [p.headline, p.finding, p.actions.length ? t.atelier.ctaOptions(p.actions.map((a) => a.label).join(', ')) : ''].filter(Boolean).join(' — ') : undefined;
      dispatchView({ type: 'clearProposal' });
      dispatch({ type: 'turn', phase: 'start' });
      const { index, text } = pickAcknowledgement(t.atelier.acknowledgedPool, lastAck.current, Math.random);
      lastAck.current = index;
      dispatch({ type: 'status', message: text, tool: 'acknowledge' });
      armWatchdog();
      const v = selectedIri ? bundles[selectedIri]?.vehicle : undefined;
      const summary = v ? `${v.make} ${v.model}${v.plate ? ` · ${v.plate}` : ''}${v.health ? ` · ${v.health.status}` : ''}` : undefined;
      postTurn(instruction, { sessionId, selectedId: selectedIri ?? undefined, summary, pendingContext }).catch((e) => {
        clearTimer(watchdog);
        dispatch({ type: 'toast', tone: 'alarm', message: t.atelier.requestFailed((e as Error).message) });
        dispatch({ type: 'turn', phase: 'end', failed: true });
        cues.play('blocked');
      });
    },
    [armWatchdog, bundles, cues.play, dispatch, selectedIri, sessionId, state, t, view.proposal],
  );

  const onListening = useCallback((active: boolean) => dispatch({ type: 'listening', active }), [dispatch]);

  const cancel = useCallback(() => {
    cancelTurn(sessionId).catch(() => undefined);
  }, [sessionId]);

  const onAction = useCallback(
    (action: CtaAction) => {
      if (action.patchTask) {
        const patch = action.patchTask;
        const iri = selectedRef.current;
        dispatchView({ type: 'clearProposal' });
        mergeTaskParts(patch.id, patch.parts)
          .then(() => (iri ? loadBundle(iri) : undefined))
          .catch((e) => {
            dispatch({ type: 'toast', tone: 'alarm', message: t.atelier.addFailed((e as Error).message) });
            cues.play('blocked');
          });
        return;
      }
      if (action.instruction) send(action.instruction);
    },
    [cues.play, dispatch, loadBundle, send, t],
  );

  const onPlan = useCallback(
    (job: JobItem) => {
      const iri = selectedRef.current;
      if (!iri) return;
      createTask(iri, { type: job.type })
        .then(() => loadBundle(iri))
        .catch((e) => {
          dispatch({ type: 'toast', tone: 'alarm', message: t.workshop.planFailed((e as Error).message) });
          cues.play('blocked');
        });
    },
    [cues.play, dispatch, loadBundle, t],
  );

  const edits = useMemo(
    () =>
      liveEdits({
        refresh: (iri) => {
          loadBundle(iri).catch(() => undefined);
        },
        created: (v) => {
          setVehicles((prev) => upsertVehicle(prev, v));
          window.location.hash = hashForVehicle(v['@id']);
        },
        deleted: (iri) => {
          setVehicles((prev) => removeVehicle(prev, iri));
          setBundles((prev) => {
            const { [iri]: _gone, ...rest } = prev;
            return rest;
          });
          window.location.hash = '#/';
        },
        updated: (vehicle) => {
          setVehicles((prev) => upsertVehicle(prev, vehicle));
          setBundles((prev) =>
            prev[vehicle['@id']] ? { ...prev, [vehicle['@id']]: { ...prev[vehicle['@id']], vehicle: { ...prev[vehicle['@id']].vehicle, ...vehicle, archivedAt: vehicle.archivedAt ?? null } } } : prev,
          );
        },
        failed: (message) => {
          dispatch({ type: 'toast', tone: 'alarm', message: t.workshop.edit.createFailed(message) });
          cues.play('blocked');
        },
      }),
    [cues.play, dispatch, loadBundle, t],
  );

  const onSelectVehicle = useCallback((iri: Iri) => {
    window.location.hash = hashForVehicle(iri);
  }, []);

  const vehicleState: 'ready' | 'loading' | 'missing' | { error: string } =
    seg === null || (selectedIri && bundles[selectedIri]) ? 'ready' : loadError ? { error: loadError } : !loadingParc && !selectedIri ? 'missing' : 'loading';

  return (
    <WorkshopView
      vehicles={vehicles}
      bundles={bundles}
      selectedIri={seg === null ? null : (selectedIri ?? `#missing:${seg}`)}
      loadingParc={loadingParc}
      vehicleState={vehicleState}
      view={view}
      onView={dispatchView}
      status={status}
      phase={state.phase}
      onListening={onListening}
      onSubmit={send}
      onCancel={cancel}
      onSelectVehicle={onSelectVehicle}
      onAction={onAction}
      theme={theme}
      onTheme={flipTheme}
      reduced={reduced}
      now={now}
      homeHref="#/"
      hrefFor={hashForVehicle}
      onPlan={onPlan}
      parcError={seg === null ? loadError : null}
      notices={state.notices}
      narration={state.narration}
      speakingId={speakingId}
      onDismissNotice={(id) => dispatch({ type: 'dismissNotice', id })}
      voice={voice}
      onVoice={setVoice}
      edits={edits}
      audio={audio}
      capabilities={capabilities}
      onPoint={onPoint}
    />
  );
}
