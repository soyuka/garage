import { describe, expect, it } from 'vitest';
import { readFlag, SOUND_KEY, TURNTABLE_KEY, VOICE_ON_KEY, writeFlag } from './prefs';

const store = (): Storage => {
  const map = new Map<string, string>();
  return {
    getItem: (k: string) => map.get(k) ?? null,
    setItem: (k: string, v: string) => void map.set(k, v),
    removeItem: (k: string) => void map.delete(k),
    clear: () => map.clear(),
    key: (i: number) => [...map.keys()][i] ?? null,
    get length() {
      return map.size;
    },
  } as Storage;
};

const denied = {
  getItem: () => {
    throw new Error('denied');
  },
  setItem: () => {
    throw new Error('denied');
  },
} as unknown as Storage;

describe('flag preferences', () => {
  it('uses their own keys', () => {
    expect([TURNTABLE_KEY, SOUND_KEY, VOICE_ON_KEY]).toEqual(['mpa.turntable', 'mpa.sound', 'mpa.voice.enabled']);
  });

  it('falls back to the default until written', () => {
    const s = store();
    expect(readFlag(s, SOUND_KEY, true)).toBe(true);
    expect(readFlag(s, TURNTABLE_KEY, false)).toBe(false);
  });

  it('remembers both values', () => {
    const s = store();
    writeFlag(s, SOUND_KEY, false);
    expect(readFlag(s, SOUND_KEY, true)).toBe(false);
    writeFlag(s, SOUND_KEY, true);
    expect(readFlag(s, SOUND_KEY, false)).toBe(true);
  });

  it('ignores a value it did not write', () => {
    const s = store();
    s.setItem(TURNTABLE_KEY, 'yes');
    expect(readFlag(s, TURNTABLE_KEY, false)).toBe(false);
  });

  it('survives a storage that throws', () => {
    expect(readFlag(denied, SOUND_KEY, true)).toBe(true);
    expect(() => writeFlag(denied, SOUND_KEY, false)).not.toThrow();
  });
});
