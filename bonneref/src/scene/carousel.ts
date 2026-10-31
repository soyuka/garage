import type { MeshId } from '../meshes';

// Parts drawn while a search runs: small game meshes only (≤ 4 000 triangles and ≤ 18 KB each, see
// public/parts/SOURCES.md), recognisable from any angle; flat rings and tiny pins are left out.
export const SMALL_MAX_TRIANGLES = 4000;

export const CAROUSEL_MESHES: readonly MeshId[] = [
  'spark-plug',
  'injector',
  'piston',
  'exhaust-valve',
  'valve-spring',
  'idler-pulley',
  'tensioner-pulley',
  'rear-wheel-bearing',
  'throwout-bearing',
  'water-pump-impeller',
  'radiator-cap',
  'pcv-valve',
  'wheel-stud',
  'axle-nut',
  'sway-bar-end-link',
  'front-brake-pad',
  'lower-control-arm-bushing',
  'oxygen-sensor',
  'connecting-rod',
  'engine-mount',
  'air-filter',
  'rear-hub',
  'battery',
  'coolant-temperature-sensor',
  'ignition-coil-boot',
];

export function pickNext(exclude: readonly MeshId[], random: () => number = Math.random): MeshId {
  const pool = CAROUSEL_MESHES.filter((id) => !exclude.includes(id));
  return pool[Math.min(pool.length - 1, Math.floor(random() * pool.length))]!;
}

export interface Entry<T> {
  id: MeshId;
  item: T;
}

interface Deps<T> {
  load: (id: MeshId) => Promise<T>;
  dispose: (item: T) => void;
  pick?: (exclude: readonly MeshId[]) => MeshId;
}

const MAX_ATTEMPTS = 3;

// At most two meshes alive: the one on screen and the next one (preloaded), or, during a swap, the one
// leaving and the one arriving. The next preload starts only once the leaving one is dropped.
export class Carousel<T> {
  shown: Entry<T> | null = null;
  private next: Entry<T> | null = null;
  private outgoing: Entry<T> | null = null;
  private loading = false;
  private active = false;
  private recent: MeshId[] = [];
  private listeners = new Set<() => void>();

  constructor(private readonly deps: Deps<T>) {}

  subscribe(fn: () => void): () => void {
    this.listeners.add(fn);
    return () => this.listeners.delete(fn);
  }

  start(): void {
    if (this.active) return;
    this.active = true;
    this.fill();
  }

  // Swap in the preloaded part. Nothing happens until it is loaded and the previous swap is over.
  advance(): Entry<T> | null {
    if (!this.active || !this.next || this.outgoing) return null;
    this.outgoing = this.shown;
    this.shown = this.next;
    this.next = null;
    this.emit();
    return this.shown;
  }

  // The part's exit (or the hand-off) is over: free it, and refill the preload slot.
  drop(id: MeshId): void {
    if (this.outgoing?.id === id) {
      this.deps.dispose(this.outgoing.item);
      this.outgoing = null;
    } else if (this.shown?.id === id && !this.active) {
      this.deps.dispose(this.shown.item);
      this.shown = null;
    } else return;
    this.fill();
    this.emit();
  }

  // The result arrived: no more swaps, the preloaded part goes now, the shown one leaves under the hand-off.
  stop(): void {
    this.active = false;
    if (this.next) this.deps.dispose(this.next.item);
    this.next = null;
  }

  release(): void {
    this.stop();
    for (const e of [this.shown, this.outgoing]) if (e) this.deps.dispose(e.item);
    this.shown = null;
    this.outgoing = null;
    this.emit();
  }

  private fill(attempt = 0): void {
    if (!this.active || this.loading || this.next || this.outgoing || attempt >= MAX_ATTEMPTS) return;
    const id = (this.deps.pick ?? pickNext)(this.recent);
    this.loading = true;
    this.deps.load(id).then(
      (item) => {
        this.loading = false;
        if (!this.active) return this.deps.dispose(item);
        this.recent = [id, ...this.recent].slice(0, 2);
        if (!this.shown) {
          this.shown = { id, item };
          this.emit();
          this.fill();
        } else this.next = { id, item };
      },
      () => {
        this.loading = false;
        this.fill(attempt + 1);
      },
    );
  }

  private emit(): void {
    for (const fn of this.listeners) fn();
  }
}
