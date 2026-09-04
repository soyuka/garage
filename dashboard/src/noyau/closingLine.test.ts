import { describe, expect, it } from 'vitest';
import { dataClosingLine } from './closingLine';

describe('dataClosingLine', () => {
  const withName = (name: string) => `Voici les données collectées sur la ${name}.`;
  const generic = 'Voici les données collectées sur ce véhicule.';

  it('names the vehicle when it has a usable name', () => {
    expect(dataClosingLine('Mazda 6', withName, generic)).toBe('Voici les données collectées sur la Mazda 6.');
  });

  // A blank make+model would produce "sur la ." — a dangling article. Unlike the opening greeting
  // (greeting.ts), which stays silent for a nameless car, a chip that just opened a panel must still
  // narrate SOMETHING, so this falls back to a whole sentence instead of an empty slot.
  it('falls back to a generic sentence with no dangling article when the name is empty', () => {
    expect(dataClosingLine('', withName, generic)).toBe('Voici les données collectées sur ce véhicule.');
  });
});
