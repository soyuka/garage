import { describe, expect, it } from 'vitest';
import { readTheme, resolveTheme, THEME_KEY, toggleTheme, writeTheme } from './theme';

function storage(initial: Record<string, string> = {}): Pick<Storage, 'getItem' | 'setItem'> & { data: Record<string, string> } {
  const data = { ...initial };
  return { data, getItem: (k) => data[k] ?? null, setItem: (k, v) => void (data[k] = v) };
}

const throwing: Pick<Storage, 'getItem' | 'setItem'> = {
  getItem: () => {
    throw new Error('denied');
  },
  setItem: () => {
    throw new Error('denied');
  },
};

describe('theme', () => {
  it('defaults to dark and accepts only the two known values', () => {
    expect(resolveTheme(null)).toBe('dark');
    expect(resolveTheme('light')).toBe('light');
    expect(resolveTheme('dark')).toBe('dark');
    expect(resolveTheme('sepia')).toBe('dark');
  });

  it('toggles', () => {
    expect(toggleTheme('dark')).toBe('light');
    expect(toggleTheme('light')).toBe('dark');
  });

  it('reads and writes under mpa.theme', () => {
    const s = storage({ [THEME_KEY]: 'light' });
    expect(THEME_KEY).toBe('mpa.theme');
    expect(readTheme(s)).toBe('light');
    writeTheme('dark', s);
    expect(s.data[THEME_KEY]).toBe('dark');
  });

  it('survives storage that throws', () => {
    expect(readTheme(throwing)).toBe('dark');
    expect(() => writeTheme('light', throwing)).not.toThrow();
  });
});
