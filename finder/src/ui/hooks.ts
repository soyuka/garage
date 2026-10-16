import { useCallback, useEffect, useState } from 'react';
import { ApiError } from '../api';
import { parseRoute, routeToHash, type Route } from '../route';
import { viewTransition } from './Presence';

export type Async<T> =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'ok'; data: T }
  | { status: 'error'; error: ApiError };

export function useAsync<T>(key: string | null, run: () => Promise<T>): Async<T> & { retry: () => void } {
  const [state, setState] = useState<Async<T>>({ status: 'idle' });
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    if (key === null) {
      setState({ status: 'idle' });
      return;
    }
    let live = true;
    setState({ status: 'loading' });
    run().then(
      (data) => live && setState({ status: 'ok', data }),
      (e: unknown) => live && setState({ status: 'error', error: e instanceof ApiError ? e : new ApiError('network') }),
    );
    return () => {
      live = false;
    };
    // `run` is recreated every render; `key` carries its inputs.
  }, [key, attempt]);

  const retry = useCallback(() => setAttempt((n) => n + 1), []);
  return { ...state, retry };
}

export type Navigate = (r: Route, options?: { replace?: boolean }) => void;

export function useRoute(): [Route, Navigate] {
  const [route, setRoute] = useState<Route>(() => parseRoute(window.location.hash));
  useEffect(() => {
    const onHash = () => viewTransition(() => setRoute(parseRoute(window.location.hash)));
    window.addEventListener('hashchange', onHash);
    return () => window.removeEventListener('hashchange', onHash);
  }, []);
  const navigate = useCallback<Navigate>((r, options) => {
    const hash = routeToHash(r);
    if (window.location.hash === hash) setRoute(r);
    else if (options?.replace) {
      // replaceState fires no hashchange: the route state follows by hand.
      window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}${hash}`);
      setRoute(r);
    } else window.location.hash = hash;
  }, []);
  return [route, navigate];
}

export function useReducedMotion(): boolean {
  const query = '(prefers-reduced-motion: reduce)';
  const [reduced, setReduced] = useState(() => window.matchMedia(query).matches);
  useEffect(() => {
    const mq = window.matchMedia(query);
    const on = () => setReduced(mq.matches);
    mq.addEventListener('change', on);
    return () => mq.removeEventListener('change', on);
  }, []);
  return reduced;
}

export function useMedia(query: string): boolean {
  const [match, setMatch] = useState(() => window.matchMedia(query).matches);
  useEffect(() => {
    const mq = window.matchMedia(query);
    const on = () => setMatch(mq.matches);
    on();
    mq.addEventListener('change', on);
    return () => mq.removeEventListener('change', on);
  }, [query]);
  return match;
}

// Whether an element is at least `min` px wide: the sheet's own width, not the viewport's, decides the
// title-block overlay, so a docked journal that narrows the sheet stacks the block below the drawing.
export function useWideEnough(min: number): [(el: HTMLElement | null) => void, boolean] {
  const [el, setEl] = useState<HTMLElement | null>(null);
  const [wide, setWide] = useState(() => typeof window !== 'undefined' && window.innerWidth - 288 >= min);
  useEffect(() => {
    if (!el) return;
    const measure = () => setWide(el.getBoundingClientRect().width >= min);
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [el, min]);
  return [setEl, wide];
}
