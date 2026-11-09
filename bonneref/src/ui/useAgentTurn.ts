import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { fetchSessionTopic, hubUrl, initialTurn, isBusy, parseFrame, postCancel, postTurn, silent, SILENT, turnBody, TurnRequestError, turnReducer, turnVehicle } from '../agent';
import { hubDefault } from '../api-base';
import { isAuthenticated, login } from '../auth';
import type { BuildCode, HeldVehicle, OnScreen, TurnIntent, TurnState, Vehicle } from '../types';

const PENDING_KEY = 'bonneref.agent.pending';

interface Pending {
  ref: string;
  text: string;
  intent: TurnIntent;
}

function readPending(ref: string): Pending | null {
  try {
    const raw = sessionStorage.getItem(PENDING_KEY);
    const pending = raw ? (JSON.parse(raw) as Pending) : null;
    return pending?.ref === ref && typeof pending.text === 'string' ? pending : null;
  } catch {
    return null;
  }
}

function clearPending(): void {
  try {
    sessionStorage.removeItem(PENDING_KEY);
  } catch {
    /* storage unavailable: nothing to clear */
  }
}

export interface AskOptions {
  intent?: TurnIntent;
  images?: string[];
  intakeId?: string | null;
  // What the request continues, as ids the server rebuilds the context from, and whether the previous parts stay up meanwhile.
  previousTurnId?: string | null;
  onScreen?: OnScreen | null;
  continued?: boolean;
  held?: HeldVehicle | null;
}

export interface AgentTurn {
  state: TurnState;
  needsLogin: string | null;
  restored: string | null;
  ask: (text: string, options?: AskOptions) => void;
  cancel: () => void;
  reset: () => void;
  signIn: () => void;
}

// `ref` is the route's vehicle ref ('' on the entry page, where a turn starts without a vehicle).
export function useAgentTurn(vehicle: Vehicle | null, ref: string, buildCode: BuildCode | null = null): AgentTurn {
  const [state, dispatch] = useReducer(turnReducer, initialTurn);
  const [needsLogin, setNeedsLogin] = useState<{ text: string; intent: TurnIntent } | null>(null);
  const pending = useMemo(() => readPending(ref), [ref]);
  const sessionId = useMemo(() => crypto.randomUUID(), []);
  const lastFrame = useRef(0);
  // Resolves once the session topic is subscribed (or known to be unavailable), so a turn never starts streaming unheard.
  const subscribed = useRef<Promise<void> | null>(null);
  const sourceRef = useRef<EventSource | null>(null);
  const generation = useRef(0);

  const subscribe = useCallback((): Promise<void> => {
    const mine = generation.current;
    subscribed.current ??= fetchSessionTopic(sessionId).then((topic) => {
      if (mine !== generation.current) return;
      if (topic === null) {
        subscribed.current = null;
        return;
      }
      const source = new EventSource(hubUrl(hubDefault(), window.location.origin, topic));
      source.onmessage = (e: MessageEvent<string>) => {
        const frame = parseFrame(e.data);
        if (!frame) return;
        lastFrame.current = Date.now();
        dispatch({ type: 'frame', frame });
      };
      sourceRef.current = source;
    });
    return subscribed.current;
  }, [sessionId]);

  useEffect(() => {
    if (isAuthenticated()) void subscribe();
    return () => {
      generation.current += 1;
      sourceRef.current?.close();
      sourceRef.current = null;
      subscribed.current = null;
    };
  }, [subscribe]);

  const busy = isBusy(state);
  useEffect(() => {
    if (!busy) return;
    const timer = window.setInterval(() => {
      if (silent(lastFrame.current, Date.now())) {
        dispatch({ type: 'fail', message: SILENT });
        void postCancel(sessionId);
      }
    }, 5_000);
    return () => window.clearInterval(timer);
  }, [busy, sessionId]);

  const ask = useCallback(
    (text: string, options: AskOptions = {}) => {
      if (text.trim() === '') return;
      const intent = options.intent ?? 'text';
      if (!isAuthenticated()) {
        setNeedsLogin({ text, intent });
        return;
      }
      setNeedsLogin(null);
      clearPending();
      lastFrame.current = Date.now();
      dispatch({ type: 'start', instruction: text, continued: options.continued ?? false, held: options.held ?? null });
      const body = turnBody({
        instruction: text,
        sessionId,
        vehicle: turnVehicle(vehicle, buildCode),
        previousTurnId: options.previousTurnId ?? null,
        onScreen: options.onScreen ?? null,
        intent,
        images: options.images,
        intakeId: options.intakeId ?? null,
      });
      subscribe()
        .then(() => postTurn(body))
        .then((turnId) => {
          if (turnId) dispatch({ type: 'accepted', turnId });
        })
        .catch((e: unknown) => {
          if (e instanceof TurnRequestError && e.kind === 'auth') {
            dispatch({ type: 'reset' });
            setNeedsLogin({ text, intent });
            return;
          }
          dispatch({ type: 'fail', message: e instanceof Error ? e.message : SILENT });
        });
    },
    [vehicle, buildCode, sessionId, subscribe],
  );

  const cancel = useCallback(() => {
    void postCancel(sessionId);
    dispatch({ type: 'cancel' });
  }, [sessionId]);

  const reset = useCallback(() => {
    setNeedsLogin(null);
    dispatch({ type: 'reset' });
  }, []);

  const signIn = useCallback(() => {
    if (needsLogin !== null) {
      try {
        sessionStorage.setItem(PENDING_KEY, JSON.stringify({ ref, text: needsLogin.text, intent: needsLogin.intent } satisfies Pending));
      } catch {
        /* the typed text is lost on return, the sign-in still works */
      }
    }
    void login();
  }, [ref, needsLogin]);

  const resumed = useRef(false);
  const vehicleReady = ref === '' || Boolean(vehicle?.carId);
  useEffect(() => {
    if (resumed.current || pending === null || !vehicleReady || !isAuthenticated()) return;
    resumed.current = true;
    ask(pending.text, { intent: pending.intent });
  }, [pending, vehicleReady, ask]);

  return { state, needsLogin: needsLogin?.text ?? null, restored: pending?.text ?? null, ask, cancel, reset, signIn };
}
