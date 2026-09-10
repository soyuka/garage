import { describe, expect, it } from 'vitest';
import { applyVoice, readVoicePreference, VOICE_KEY, writeVoicePreference } from './voicePreference';
import type { SpeechVoice } from '../types';

const VOICES: SpeechVoice[] = [
  { id: 'v1', label: 'Voix 1' },
  { id: 'v2', label: 'Voix 2' },
];

// No jsdom in this suite (`vitest.config.ts` runs `environment: 'node'`) — a plain Map-backed fake
// mirrors the pattern already used for Storage in auth.test.ts.
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

/** A storage that throws on every access — private mode / storage denied. */
class ThrowingStorage implements Storage {
  readonly length = 0;
  clear(): void {
    throw new Error('denied');
  }
  getItem(): never {
    throw new Error('denied');
  }
  key(): never {
    throw new Error('denied');
  }
  removeItem(): never {
    throw new Error('denied');
  }
  setItem(): never {
    throw new Error('denied');
  }
}

describe('readVoicePreference / writeVoicePreference — round-trip', () => {
  it('reads back what it wrote', () => {
    const storage = store();
    writeVoicePreference(storage, 'v2');
    expect(readVoicePreference(storage)).toBe('v2');
  });

  it('null removes the key', () => {
    const storage = store();
    writeVoicePreference(storage, 'v2');
    writeVoicePreference(storage, null);
    expect(readVoicePreference(storage)).toBeNull();
    expect(storage.getItem(VOICE_KEY)).toBeNull();
  });

  it('reads null when nothing was ever written', () => {
    expect(readVoicePreference(store())).toBeNull();
  });
});

describe('a throwing storage — private mode / storage denied', () => {
  it('read never throws, returns null', () => {
    expect(readVoicePreference(new ThrowingStorage())).toBeNull();
  });

  it('write never throws — best effort, swallowed', () => {
    expect(() => writeVoicePreference(new ThrowingStorage(), 'v1')).not.toThrow();
    expect(() => writeVoicePreference(new ThrowingStorage(), null)).not.toThrow();
  });
});

describe('applyVoice — the preference against the live list', () => {
  it('keeps the preferred id when the server still offers it', () => {
    expect(applyVoice(VOICES, 'v2')).toBe('v2');
  });

  it('falls back to null (server default) when the id is stale or absent', () => {
    expect(applyVoice(VOICES, 'retired-voice')).toBeNull();
  });

  it('null preference stays null', () => {
    expect(applyVoice(VOICES, null)).toBeNull();
  });

  it('an empty voice list never matches anything', () => {
    expect(applyVoice([], 'v1')).toBeNull();
  });
});
