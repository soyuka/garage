import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { hubUrl, initialTurn, isBusy, parseFrame, postCancel, postTurn, sessionTopic, turnBody, TurnRequestError, turnReducer } from '../agent';
import { isAuthenticated, login } from '../auth';
import type { BuildLabel, FinderFrame, HeldVehicle, PartPhotoHints, TurnIntent, TurnState, Vehicle } from '../types';

const HUB = import.meta.env.VITE_MERCURE_URL ?? '/.well-known/mercure';
const API_BASE = import.meta.env.VITE_API_BASE ?? window.location.origin;
const SILENCE_MS = 90_000;
const PENDING_KEY = 'finder.agent.pending';
const SILENT = 'L’agent ne répond plus. Réessayez, ou choisissez une catégorie dans la liste.';

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
  pendingContext?: string | null;
  intent?: TurnIntent;
  images?: string[];
  photoHints?: PartPhotoHints | null;
  // The on-screen result the request continues, and whether the previous parts stay up meanwhile.
  summary?: string | null;
  buildLabel?: BuildLabel | null;
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
  replay: (instruction: string, frames: { frame: FinderFrame; at: number }[], synthetic: boolean) => void;
}

// `ref` is the route's vehicle ref ('' on the entry page, where a turn starts without a vehicle).
export function useAgentTurn(vehicle: Vehicle | null, ref: string, enabled: boolean): AgentTurn {
  const [state, dispatch] = useReducer(turnReducer, initialTurn);
  const [needsLogin, setNeedsLogin] = useState<{ text: string; intent: TurnIntent } | null>(null);
  const pending = useMemo(() => readPending(ref), [ref]);
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
    (text: string, options: AskOptions = {}) => {
      if (!enabled || text.trim() === '') return;
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
        vehicle: vehicle?.carId ? { carId: vehicle.carId, carType: vehicle.carType, make: vehicle.make, model: vehicle.model, engine: vehicle.engineCode, plate: vehicle.plate } : null,
        pendingContext: options.pendingContext ?? null,
        intent,
        images: options.images,
        photoHints: options.photoHints ?? null,
        summary: options.summary ?? null,
        buildLabel: options.buildLabel ?? null,
      });
      postTurn(body).catch((e: unknown) => {
        if (e instanceof TurnRequestError && e.kind === 'auth') {
          dispatch({ type: 'reset' });
          setNeedsLogin({ text, intent });
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
    if (needsLogin !== null) {
      try {
        sessionStorage.setItem(PENDING_KEY, JSON.stringify({ ref, text: needsLogin.text, intent: needsLogin.intent } satisfies Pending));
      } catch {
        /* the typed text is lost on return, the sign-in still works */
      }
    }
    void login();
  }, [ref, needsLogin]);

  // Demo mode only: plays recorded frames through the same reducer, no request, no hub.
  const timers = useRef<number[]>([]);
  const replay = useCallback((instruction: string, frames: { frame: FinderFrame; at: number }[], synthetic: boolean) => {
    timers.current.forEach((t) => window.clearTimeout(t));
    dispatch({ type: 'start', instruction, synthetic });
    timers.current = frames.map(({ frame, at }) => window.setTimeout(() => dispatch({ type: 'frame', frame }), at));
  }, []);
  useEffect(() => () => timers.current.forEach((t) => window.clearTimeout(t)), []);

  const resumed = useRef(false);
  const vehicleReady = ref === '' || Boolean(vehicle?.carId);
  useEffect(() => {
    if (resumed.current || pending === null || !vehicleReady || !isAuthenticated()) return;
    resumed.current = true;
    ask(pending.text, { intent: pending.intent });
  }, [pending, vehicleReady, ask]);

  return { state, needsLogin: needsLogin?.text ?? null, restored: pending?.text ?? null, ask, cancel, reset, signIn, replay };
}
