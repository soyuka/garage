import type { Messages } from '../i18n/messages';

// The opening ticker. Pure decision,
// clock-free: given the settled selection before and after, it says whether a greeting line is due
// and for what, then draws the line itself from the catalog's pools. The impure edge (Atelier.tsx)
// only tracks `previous`, keeps the draw bookkeeping and dispatches the result.
//
// The line was briefly written by a model (`GET /agent/greeting`, Haiku); that was reversed hours
// later on 2026-09-11 and the endpoint deleted. The variety now comes from the SIZE of the pools,
// which is why drawing is a rule here and not a `Math.random()` at the call site.

export type Greeting =
  /** The tab's first line, nothing open: a welcome, and the question the parc is there to answer. */
  | { kind: 'welcome' }
  /**
   * The tab's first line with a car already open — a direct load of `#/v/<seg>`. ONE line does both
   * jobs: a welcome followed half a second later by a vehicle line is the ticker talking over itself.
   */
  | { kind: 'welcomeVehicle'; name: string }
  /** A later change of selection: the car alone, no second hello. */
  | { kind: 'vehicle'; name: string };

/** Which lines each pool has already given up this session, so a fresh one is always preferred. */
export interface Drawn {
  welcome: readonly number[];
  welcomeVehicle: readonly number[];
  vehicle: readonly number[];
}

export const nothingDrawn: Drawn = { welcome: [], welcomeVehicle: [], vehicle: [] };

export interface DrawState {
  drawn: Drawn;
  /** The operator's display name, from the token. Absent is normal — the greeting then omits it. */
  operator: string | undefined;
  /** Injected: the caller passes `Math.random`, tests pass a fixed sequence. */
  rand: () => number;
}

/**
 * `previous` is `undefined` for "nothing greeted yet" (so the very first settle always greets) and
 * `null` for "settled on no vehicle". `next` is only ever a settled value — the caller must withhold
 * a call while a selection is still resolving (e.g. a URL hash awaiting the vehicle collection), or
 * the transient null would fire the wrong line before the real one lands.
 */
export function greetingForSelection(
  previous: string | null | undefined,
  next: string | null,
  vehicleName: string | undefined,
  isBusy: boolean,
): Greeting | null {
  if (previous === next) return null; // no change — never re-greet a repaint or the same vehicle
  if (isBusy) return null; // the ticker belongs to the turn while one is working

  const opening = undefined === previous;
  if (null === next) {
    // Arriving on the parc is a welcome; coming BACK to it is not. The operator is looking at every
    // car they own, which answers "which one" on its own, and a line here would stay on screen for
    // as long as they browse — that is what got the old fixed sentence removed (2026-09-11).
    return opening ? { kind: 'welcome' } : null;
  }

  // A car whose make AND model are both blank would greet « sélectionnée, que veux-tu savoir ? » —
  // headless, and worse aloud than on screen. Silence is the degraded mode everywhere else in this
  // app (no vendor, no budget, no gesture → silence), so it is the honest one here too.
  if (!vehicleName) return null;

  return opening ? { kind: 'welcomeVehicle', name: vehicleName } : { kind: 'vehicle', name: vehicleName };
}

/**
 * Draw the line, and report the pool state to carry forward.
 *
 * A line already drawn is not drawn again while a fresh one is left — and NOT merely "not the same one
 * twice in a row" the way `acknowledge.ts` picks, because the audition refuses a sentence it has
 * already spoken for the rest of the session (`voice.ts` `saidOnce`): a repeat would print on the
 * ticker and then be silent, which reads as a broken voice rather than as a repeat.
 */
export function drawGreeting(
  greeting: Greeting,
  lines: Messages['greeting'],
  state: DrawState,
): { text: string; drawn: Drawn } {
  const hello = lines.hello(state.operator);

  switch (greeting.kind) {
    case 'welcome': {
      const { item, used } = draw(lines.welcome, state.drawn.welcome, state.rand);

      return { text: `${hello}, ${item}`, drawn: { ...state.drawn, welcome: used } };
    }

    case 'welcomeVehicle': {
      const { item, used } = draw(lines.welcomeVehicle, state.drawn.welcomeVehicle, state.rand);

      return { text: `${hello}, ${item(greeting.name)}`, drawn: { ...state.drawn, welcomeVehicle: used } };
    }

    case 'vehicle': {
      const { item, used } = draw(lines.vehicle, state.drawn.vehicle, state.rand);

      return { text: item(greeting.name), drawn: { ...state.drawn, vehicle: used } };
    }
  }
}

/**
 * One item out of `pool`, preferring those not yet in `used`. Once every line has been given out the
 * cycle starts over, minus the one that just spoke — a pool of four heard four times is variety; the
 * same sentence twice across the restart is not.
 */
function draw<T>(pool: readonly T[], used: readonly number[], rand: () => number): { item: T; used: number[] } {
  const all = pool.map((_, i) => i);
  let fresh = all.filter((i) => !used.includes(i));
  let cycle: readonly number[] = used;

  if (0 === fresh.length) {
    cycle = [];
    const last = used[used.length - 1];
    fresh = all.filter((i) => i !== last);
    if (0 === fresh.length) fresh = all; // a pool of one has nothing to vary
  }

  const index = fresh[Math.min(fresh.length - 1, Math.floor(rand() * fresh.length))];

  return { item: pool[index], used: [...cycle, index] };
}
