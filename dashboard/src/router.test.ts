import { describe, it, expect } from 'vitest';
import { hashForVehicle, matchRoute, parseHash, segForIri, vehicleSegFromHash, type DevRoute } from './router';

const routes: DevRoute[] = [
  { path: '/', title: 'Atelier — dev index' },
  { path: '/noyau', title: 'Le Noyau (playground)' },
];

describe('parseHash', () => {
  it('treats an empty hash, a bare "#", and "#/" as the index path', () => {
    expect(parseHash('')).toBe('/');
    expect(parseHash('#')).toBe('/');
    expect(parseHash('#/')).toBe('/');
  });

  it('strips the leading "#" to yield the route path', () => {
    expect(parseHash('#/noyau')).toBe('/noyau');
  });

  it('drops a query/anchor tail so only the path is matched', () => {
    expect(parseHash('#/noyau?scenario=overdue')).toBe('/noyau');
  });

  it('normalizes a trailing slash to no trailing slash (except the root)', () => {
    expect(parseHash('#/noyau/')).toBe('/noyau');
  });
});

describe('matchRoute', () => {
  it('finds the route whose path matches the parsed hash', () => {
    expect(matchRoute('#/noyau', routes)?.title).toBe('Le Noyau (playground)');
  });

  it('returns the index route for an empty hash', () => {
    expect(matchRoute('', routes)?.path).toBe('/');
  });

  it('returns undefined for an unknown path (caller renders a not-found)', () => {
    expect(matchRoute('#/nope', routes)).toBeUndefined();
  });
});

describe('vehicle routing (#/v/<seg>)', () => {
  it('segForIri takes the last path segment of an IRI', () => {
    expect(segForIri('/garage/vehicles/01KW9RF0ZAFP')).toBe('01KW9RF0ZAFP');
  });

  it('hashForVehicle builds the selection hash from an IRI', () => {
    expect(hashForVehicle('/garage/vehicles/01KW9RF0ZAFP')).toBe('#/v/01KW9RF0ZAFP');
  });

  it('vehicleSegFromHash extracts the seg on the /v/ route', () => {
    expect(vehicleSegFromHash('/v/01KW9RF0ZAFP')).toBe('01KW9RF0ZAFP');
  });

  it('vehicleSegFromHash returns null off the /v/ route (the parc landing)', () => {
    expect(vehicleSegFromHash('/')).toBeNull();
    expect(vehicleSegFromHash('/scene')).toBeNull();
    expect(vehicleSegFromHash('/v/')).toBeNull();
  });
});
