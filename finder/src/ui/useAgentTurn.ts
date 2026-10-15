import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { hubUrl, initialTurn, isBusy, parseFrame, postCancel, postTurn, sessionTopic, turnBody, TurnRequestError, turnReducer } from '../agent';
import { isAuthenticated, login } from '../auth';
import type { TurnState, Vehicle } from '../types';

const HUB = import.meta.env.VITE_MERCURE_URL ?? '/.well-known/mercure';
const API_BASE = import.meta.env.VITE_API_BASE ?? window.location.origin;
const SILENCE_MS = 90_000;
const PENDING_KEY = 'finder.agent.pending';
const SILENT = 'L’agent ne répond plus. Réessayez, ou choisissez une catégorie dans la liste.';

interface Pending {
  plate: string;
  text: string;
}

function readPending(plate: string): string | null {
  try {
    const raw = sessionStorage.getItem(PENDING_KEY);
    const pending = raw ? (JSON.parse(raw) as Pending) : null;
    return pending?.plate === plate ? pending.text : null;
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

export interface AgentTurn {
  state: TurnState;
  needsLogin: string | null;
  restored: string | null;
  ask: (text: string, pendingContext?: string | null) => void;
  cancel: () => void;
  reset: () => void;
  signIn: () => void;
}

export function useAgentTurn(vehicle: Vehicle | null, enabled: boolean): AgentTurn {
  const [state, dispatch] = useReducer(turnReducer, initialTurn);
  const [needsLogin, setNeedsLogin] = useState<string | null>(null);
  const plate = vehicle?.plate ?? null;
  const restored = useMemo(() => (plate ? readPending(plate) : null), [plate]);
  const sessionId = useMemo(() => crypto.randomUUID(), []);
  const lastFrame = useRef(0);

  useEffect(() => {
    if (!enabled) return;
    const source = new EventSource(hubUrl(HUB, window.location.origin, sessionTopic(API_BASE, sessionId)));
    source.onmessage = (e: MessageEvent<string>) => {
      const frame = parseFrame(e.data);
      if (!frame) return;
      lastFrame.current = Date.now();
      dispatch({ type: 'frame', frame });
    };
    return () => source.close();
  }, [sessionId, enabled]);

  const busy = isBusy(state);
  useEffect(() => {
    if (!busy) return;
    const timer = window.setInterval(() => {
      if (Date.now() - lastFrame.current > SILENCE_MS) {
        dispatch({ type: 'fail', message: SILENT });
        void postCancel(sessionId);
      }
    }, 5_000);
    return () => window.clearInterval(timer);
  }, [busy, sessionId]);

  const ask = useCallback(
    (text: string, pendingContext: string | null = null) => {
      if (!enabled || !vehicle?.carId || text.trim() === '') return;
      if (!isAuthenticated()) {
        setNeedsLogin(text);
        return;
      }
      setNeedsLogin(null);
      clearPending();
      lastFrame.current = Date.now();
      dispatch({ type: 'start', instruction: text });
      const body = turnBody(text, sessionId, { carId: vehicle.carId, carType: vehicle.carType, make: vehicle.make, model: vehicle.model, engine: vehicle.engineCode }, pendingContext);
      postTurn(body).catch((e: unknown) => {
        if (e instanceof TurnRequestError && e.kind === 'auth') {
          dispatch({ type: 'reset' });
          setNeedsLogin(text);
          return;
        }
        dispatch({ type: 'fail', message: e instanceof Error ? e.message : SILENT });
      });
    },
    [enabled, vehicle, sessionId],
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
    if (!vehicle || needsLogin === null) return;
    try {
      sessionStorage.setItem(PENDING_KEY, JSON.stringify({ plate: vehicle.plate, text: needsLogin } satisfies Pending));
    } catch {
      /* the typed text is lost on return, the sign-in still works */
    }
    void login();
  }, [vehicle, needsLogin]);

  const resumed = useRef(false);
  useEffect(() => {
    if (resumed.current || restored === null || !vehicle?.carId || !isAuthenticated()) return;
    resumed.current = true;
    ask(restored);
  }, [restored, vehicle, ask]);

  return { state, needsLogin, restored, ask, cancel, reset, signIn };
}
