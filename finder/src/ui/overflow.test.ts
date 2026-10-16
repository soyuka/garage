import { watchOverflow } from './overflow';

describe('watchOverflow', () => {
  const observers: { cb: () => void; disconnect: ReturnType<typeof vi.fn> }[] = [];
  const listeners = new Map<string, () => void>();
  let fontsReady: () => void;

  beforeEach(() => {
    observers.length = 0;
    listeners.clear();
    vi.stubGlobal('ResizeObserver', class {
      disconnect = vi.fn();
      constructor(cb: () => void) {
        observers.push({ cb, disconnect: this.disconnect });
      }
      observe() {}
    });
    vi.stubGlobal('window', { addEventListener: (n: string, f: () => void) => listeners.set(n, f), removeEventListener: (n: string) => listeners.delete(n) });
    vi.stubGlobal('document', { fonts: { ready: new Promise<void>((r) => (fontsReady = r)) } });
  });
  afterEach(() => vi.unstubAllGlobals());

  it('reports whether the content is taller than its box, again after a resize or the fonts landing', async () => {
    const box = { scrollHeight: 60, clientHeight: 36 };
    const el = box as HTMLElement;
    const seen: boolean[] = [];
    const stop = watchOverflow(el, (o) => seen.push(o));
    expect(seen).toEqual([true]);

    box.scrollHeight = 36;
    observers[0]!.cb();
    expect(seen).toEqual([true, false]);

    box.scrollHeight = 72;
    listeners.get('resize')?.();
    expect(seen.at(-1)).toBe(true);

    box.scrollHeight = 36;
    fontsReady();
    await Promise.resolve();
    await Promise.resolve();
    expect(seen.at(-1)).toBe(false);

    stop();
    expect(observers[0]!.disconnect).toHaveBeenCalled();
    expect(listeners.has('resize')).toBe(false);
  });

  it('tolerates a sub-pixel difference', () => {
    const seen: boolean[] = [];
    watchOverflow({ scrollHeight: 37, clientHeight: 36 } as HTMLElement, (o) => seen.push(o));
    expect(seen).toEqual([false]);
  });
});
