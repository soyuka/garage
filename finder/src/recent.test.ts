import { recentFor, seedRecent } from './recent';

function memoryStorage(): Storage {
  const m = new Map<string, string>();
  return {
    get length() { return m.size; },
    clear: () => m.clear(),
    getItem: (k) => m.get(k) ?? null,
    key: (i) => [...m.keys()][i] ?? null,
    removeItem: (k) => void m.delete(k),
    setItem: (k, v) => void m.set(k, v),
  };
}

describe('seedRecent', () => {
  beforeEach(() => vi.stubGlobal('localStorage', memoryStorage()));
  afterEach(() => vi.unstubAllGlobals());

  it('fills an empty history for a plate', () => {
    seedRecent('XB-301-ZB', [{ categoryId: 100032, label: 'Disque de frein' }]);
    expect(recentFor('XB-301-ZB')).toEqual([{ categoryId: 100032, label: 'Disque de frein' }]);
  });

  it('never overwrites a real history', () => {
    seedRecent('XB-301-ZB', [{ categoryId: 1, label: 'a' }]);
    seedRecent('XB-301-ZB', [{ categoryId: 2, label: 'b' }]);
    expect(recentFor('XB-301-ZB')).toEqual([{ categoryId: 1, label: 'a' }]);
  });
});
