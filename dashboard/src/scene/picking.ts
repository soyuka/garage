import { useCallback, useEffect, useRef } from 'react';
import type { ThreeEvent } from '@react-three/fiber';

// Picking — the body points back. Shared by the three
// station components (VisitRing, FactConstellation, FluidVolumes): hovering a body marks it AND tells the
// legend which row to highlight; a click scrolls that row into view. "Nothing is selected, nothing opens:
// a pick is a look, not an action" — so this file owns only the LOOK state (legendFocus) + the cursor
// affordance, never a selection/edit path.

export type PickKind = 'visit' | 'fact' | 'fluid';

export interface LegendFocus {
  kind: PickKind;
  key: string;
}

export type LegendFocusEvent =
  | { type: 'hover'; kind: PickKind; key: string | null }
  | { type: 'pick'; kind: PickKind; key: string }
  | { type: 'leave'; kind: PickKind };

/**
 * A click (`pick`) sets the focus. Hovering off the body (`hover` with `key: null`) or an explicit
 * `leave` clears it, but only when it owns the current focus's `kind` — three stations sharing one
 * SceneView-held state must never clear each other's pick. Hovering a DIFFERENT key while a pick
 * stands never reassigns it: only a click does ("a pick is a look, not an action" — hover alone
 * cannot move it, it can only end it by leaving).
 */
export function legendFocusReducer(state: LegendFocus | null, event: LegendFocusEvent): LegendFocus | null {
  switch (event.type) {
    case 'pick':
      return { kind: event.kind, key: event.key };
    case 'hover':
      if (event.key != null) return state;
      return state && state.kind === event.kind ? null : state;
    case 'leave':
      return state && state.kind === event.kind ? null : state;
    default:
      return state;
  }
}

// document.body.style.cursor, ref-counted: two overlapping bodies (e.g. a leader wire crossing a slab)
// must not fight over the cursor — it only clears once the LAST hover ends, and only sets once for the
// first, so a pointer sliding directly from one body to another never flickers back to the default.
let cursorRefCount = 0;

// The DOM write is guarded (also SSR-safe) so the ref-count logic itself stays testable in a plain
// node environment without a DOM.
function setBodyCursor(value: string): void {
  if (typeof document !== 'undefined') document.body.style.cursor = value;
}

export function cursorEnter(): void {
  cursorRefCount += 1;
  setBodyCursor('pointer');
}

export function cursorLeave(): void {
  cursorRefCount = Math.max(0, cursorRefCount - 1);
  if (cursorRefCount === 0) setBodyCursor('');
}

/** Test seam: the count actually driving the cursor write. */
export function cursorHoverCount(): number {
  return cursorRefCount;
}

export interface PickHandlers {
  onPointerOver: (e: ThreeEvent<PointerEvent>) => void;
  onPointerOut: (e: ThreeEvent<PointerEvent>) => void;
  onClick: (e: ThreeEvent<MouseEvent>) => void;
}

/**
 * Attach to a body's outer `<group>` (not its mesh): the same three handlers regardless of whether the
 * body is filled or wire-only, since either the fill mesh or the wire's invisible hit-proxy is what the
 * raycaster hits, and the pointer event bubbles up to this group either way. `e.stopPropagation()` on
 * every handler keeps the disc/car from also receiving the event.
 */
export function usePickHandlers(
  key: string,
  onHover?: (key: string | null) => void,
  onPick?: (key: string) => void,
): PickHandlers {
  const over = useRef(false);

  // A body can unmount (e.g. a station sinking away) while still hovered — without this the cursor
  // would stay stuck on 'pointer' forever.
  useEffect(
    () => () => {
      if (over.current) {
        over.current = false;
        cursorLeave();
      }
    },
    [],
  );

  const onPointerOver = useCallback(
    (e: ThreeEvent<PointerEvent>) => {
      e.stopPropagation();
      if (over.current) return;
      over.current = true;
      cursorEnter();
      onHover?.(key);
    },
    [key, onHover],
  );
  const onPointerOut = useCallback(
    (e: ThreeEvent<PointerEvent>) => {
      e.stopPropagation();
      if (!over.current) return;
      over.current = false;
      cursorLeave();
      onHover?.(null);
    },
    [onHover],
  );
  const onClick = useCallback(
    (e: ThreeEvent<MouseEvent>) => {
      e.stopPropagation();
      onPick?.(key);
    },
    [key, onPick],
  );

  return { onPointerOver, onPointerOut, onClick };
}
