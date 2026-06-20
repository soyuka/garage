import { describe, expect, it } from 'vitest';
import { orderByRecency, recordVisit, vehicleSegment } from './recency';

const iri = (seg: string) => `/garage/vehicles/${seg}`;

describe('vehicleSegment', () => {
  it('returns the last IRI path segment', () => {
    expect(vehicleSegment(iri('abc'))).toBe('abc');
  });
  it('falls back to the input when there is no slash', () => {
    expect(vehicleSegment('abc')).toBe('abc');
  });
});

describe('recordVisit', () => {
  it('prepends the visited segment', () => {
    expect(recordVisit(['a', 'b'], iri('c'))).toEqual(['c', 'a', 'b']);
  });
  it('dedups an existing occurrence (moves it to front)', () => {
    expect(recordVisit(['a', 'b', 'c'], iri('b'))).toEqual(['b', 'a', 'c']);
  });
  it('caps the list length', () => {
    const long = Array.from({ length: 60 }, (_, i) => `s${i}`);
    const out = recordVisit(long, iri('new'));
    expect(out.length).toBe(50);
    expect(out[0]).toBe('new');
  });
  it('does not mutate the input', () => {
    const input = ['a', 'b'];
    recordVisit(input, iri('c'));
    expect(input).toEqual(['a', 'b']);
  });
});

describe('orderByRecency', () => {
  const items = [{ id: iri('a') }, { id: iri('b') }, { id: iri('c') }, { id: iri('d') }];

  it('orders known items by recency index ascending, unknowns last in original order', () => {
    const out = orderByRecency(items, ['c', 'a']);
    expect(out.map((i) => i.id)).toEqual([iri('c'), iri('a'), iri('b'), iri('d')]);
  });
  it('is stable: equal-rank (all unknown) items keep original order', () => {
    const out = orderByRecency(items, []);
    expect(out.map((i) => i.id)).toEqual([iri('a'), iri('b'), iri('c'), iri('d')]);
  });
  it('does not mutate the input array', () => {
    const copy = [...items];
    orderByRecency(items, ['d']);
    expect(items).toEqual(copy);
  });
});
