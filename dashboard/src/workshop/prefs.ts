export const TURNTABLE_KEY = 'mpa.turntable';
export const SOUND_KEY = 'mpa.sound';
export const VOICE_ON_KEY = 'mpa.voice.enabled';

export function readFlag(storage: Storage, key: string, fallback: boolean): boolean {
  try {
    const v = storage.getItem(key);
    return v === '1' ? true : v === '0' ? false : fallback;
  } catch {
    return fallback;
  }
}

export function writeFlag(storage: Storage, key: string, value: boolean): void {
  try {
    storage.setItem(key, value ? '1' : '0');
  } catch {
    /* the preference simply does not persist */
  }
}
