import sources from '../../public/parts/SOURCES.md?raw';
import { MESHES, meshFile, type MeshId } from '../meshes';
import { CAROUSEL_MESHES, Carousel, SMALL_MAX_TRIANGLES, pickNext } from './carousel';

const triangles = (id: MeshId): number => {
  const row = sources.split('\n').find((l) => l.startsWith(`| \`${meshFile(id)}\``));
  if (!row) throw new Error(`no SOURCES.md row for ${id}`);
  return Number(row.split('|').at(-2)!.trim());
};

interface Fake {
  id: MeshId;
  disposed: boolean;
}

function harness(order: MeshId[] = [...CAROUSEL_MESHES]) {
  const made: Fake[] = [];
  const pending: { id: MeshId; resolve: () => void }[] = [];
  let i = 0;
  const carousel = new Carousel<Fake>({
    load: (id) =>
      new Promise<Fake>((resolve) => {
        const item = { id, disposed: false };
        pending.push({ id, resolve: () => (made.push(item), resolve(item)) });
      }),
    dispose: (item) => {
      item.disposed = true;
    },
    pick: (exclude) => {
      for (;;) {
        const id = order[i++ % order.length]!;
        if (!exclude.includes(id)) return id;
      }
    },
  });
  const alive = () => made.filter((m) => !m.disposed).length;
  const flush = async () => {
    while (pending.length) pending.shift()!.resolve();
    await Promise.resolve();
    await Promise.resolve();
  };
  return { carousel, made, alive, flush };
}

describe('loading carousel meshes', () => {
  it('only offers small shipped meshes', () => {
    expect(CAROUSEL_MESHES.length).toBeGreaterThanOrEqual(12);
    for (const id of CAROUSEL_MESHES) {
      expect(MESHES).toContain(id);
      expect(triangles(id)).toBeLessThanOrEqual(SMALL_MAX_TRIANGLES);
    }
  });

  it('never picks the part on screen or the one before it', () => {
    const exclude: MeshId[] = [CAROUSEL_MESHES[0]!, CAROUSEL_MESHES[1]!];
    for (const r of [0, 0.25, 0.5, 0.75, 0.999]) {
      const id = pickNext(exclude, () => r);
      expect(CAROUSEL_MESHES).toContain(id);
      expect(exclude).not.toContain(id);
    }
  });
});

describe('Carousel', () => {
  it('shows one part and preloads the next, never more than two loaded', async () => {
    const { carousel, alive, flush } = harness();
    carousel.start();
    await flush();
    expect(carousel.shown?.id).toBe(CAROUSEL_MESHES[0]);
    await flush();
    expect(alive()).toBe(2);

    expect(carousel.advance()?.id).toBe(CAROUSEL_MESHES[1]);
    // The next one waits until the outgoing part has been dropped.
    await flush();
    expect(alive()).toBe(2);
    expect(carousel.advance()).toBeNull();
  });

  it('disposes the part it swaps out once its exit is over, then preloads', async () => {
    const { carousel, made, alive, flush } = harness();
    carousel.start();
    await flush();
    await flush();
    const first = made[0]!;
    carousel.advance();
    expect(first.disposed).toBe(false);
    carousel.drop(first.id);
    expect(first.disposed).toBe(true);
    await flush();
    expect(alive()).toBe(2);
    for (let n = 0; n < 6; n++) {
      const out = carousel.shown!.id;
      carousel.advance();
      carousel.drop(out);
      await flush();
      expect(alive()).toBeLessThanOrEqual(2);
    }
  });

  it('hands off: stop drops the preloaded part at once, keeps the shown one until its exit ends', async () => {
    const { carousel, made, alive, flush } = harness();
    carousel.start();
    await flush();
    await flush();
    const shown = carousel.shown!;
    carousel.stop();
    expect(alive()).toBe(1);
    expect(made.find((m) => m.id === shown.id)!.disposed).toBe(false);
    expect(carousel.advance()).toBeNull();
    carousel.drop(shown.id);
    expect(alive()).toBe(0);
  });

  it('disposes a load that lands after the carousel stopped', async () => {
    const { carousel, made, alive, flush } = harness();
    carousel.start();
    carousel.stop();
    await flush();
    expect(made).toHaveLength(1);
    expect(alive()).toBe(0);
    expect(carousel.shown).toBeNull();
  });

  it('release disposes everything still loaded', async () => {
    const { carousel, alive, flush } = harness();
    carousel.start();
    await flush();
    await flush();
    carousel.advance();
    carousel.release();
    expect(alive()).toBe(0);
  });
});
