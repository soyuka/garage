import { describe, expect, it } from 'vitest';
import { pickAcknowledgement } from './acknowledge';
import { en } from '../i18n/en';
import { fr } from '../i18n/fr';

describe('pickAcknowledgement', () => {
  it('never repeats the last index when the pool has at least two entries', () => {
    const pool = ['un', 'deux', 'trois'] as const;
    // rand() lands squarely on index 1 (the last one spoken) — the pick must move off it.
    const rand = () => 1 / 3;

    const { index, text } = pickAcknowledgement(pool, 1, rand);

    expect(index).not.toBe(1);
    expect(text).toBe(pool[index]);
  });

  it('returns the sole entry when the pool has only one', () => {
    const pool = ['seule'] as const;

    expect(pickAcknowledgement(pool, 0, () => 0)).toEqual({ index: 0, text: 'seule' });
  });

  it('each locale pool carries 5 distinct variants', () => {
    expect(fr.atelier.acknowledgedPool).toHaveLength(5);
    expect(new Set(fr.atelier.acknowledgedPool).size).toBe(5);
    expect(en.atelier.acknowledgedPool).toHaveLength(5);
    expect(new Set(en.atelier.acknowledgedPool).size).toBe(5);
  });
});
