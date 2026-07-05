import { describe, it, expect } from 'vitest';
import { selectFacts } from './SpecConstellation';
import type { VehicleFact } from '../types';

function fact(key: string, value = 'x', verified = false): VehicleFact {
  return { key, value, source: verified ? 'partsapi' : 'user', verified };
}

// The spec constellation projects `vehicle.facts` filtered to the focus keys. An EMPTY key set means
// "the whole spec sheet" (a generic "Infos moteur" chip), not "nothing" — so a broad ask still answers.

describe('selectFacts — filter facts to the focus keys, empty ⇒ all', () => {
  const facts = [fact('oil_type'), fact('oil_capacity'), fact('tyres_ref')];

  it('returns all facts when no keys are given', () => {
    expect(selectFacts(facts, []).map((f) => f.key)).toEqual(['oil_type', 'oil_capacity', 'tyres_ref']);
  });

  it('keeps only the requested keys, in the facts source order', () => {
    expect(selectFacts(facts, ['tyres_ref', 'oil_type']).map((f) => f.key)).toEqual(['oil_type', 'tyres_ref']);
  });

  it('ignores a requested key that has no fact', () => {
    expect(selectFacts(facts, ['nonexistent', 'oil_type']).map((f) => f.key)).toEqual(['oil_type']);
  });

  it('is empty when the vehicle has no facts', () => {
    expect(selectFacts([], ['oil_type'])).toEqual([]);
    expect(selectFacts([], [])).toEqual([]);
  });
});
