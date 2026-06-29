import { useSyncExternalStore } from 'react';

// Bridges the OS `prefers-reduced-motion` flag into React state. The CSS @media backstop cannot reach
// a WebGL useFrame loop (BodyGlow/RimGauge breathe, OrbitControls autoRotate), so the 3D leaves must
// inherit the OS signal through a prop. OR this with the manual dev toggle for the effective value.
const QUERY = '(prefers-reduced-motion: reduce)';

function subscribe(cb: () => void): () => void {
  if (typeof window === 'undefined' || !window.matchMedia) return () => {};
  const mq = window.matchMedia(QUERY);
  mq.addEventListener('change', cb);
  return () => mq.removeEventListener('change', cb);
}

function getSnapshot(): boolean {
  return typeof window !== 'undefined' && !!window.matchMedia && window.matchMedia(QUERY).matches;
}

export function useReducedMotion(): boolean {
  return useSyncExternalStore(subscribe, getSnapshot, () => false);
}
