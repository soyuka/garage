import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiUrl, hydraMembers, mergeTaskParts, normalizeIri } from './garage';

// Pure-core tests for the garage REST client. The authFetch verbs are thin glue; these pin the two
// shape-bugs that bite: the hydra member key (member vs hydra:member) and the @id→id normalization
// (a bare ULID would build a broken `https://host<ulid>` PATCH URL).

describe('hydraMembers', () => {
  it('reads the modern `member` key', () => {
    expect(hydraMembers({ member: [{ a: 1 }, { a: 2 }] })).toEqual([{ a: 1 }, { a: 2 }]);
  });

  it('falls back to the legacy `hydra:member` key', () => {
    expect(hydraMembers({ 'hydra:member': [{ a: 1 }] })).toEqual([{ a: 1 }]);
  });

  it('prefers `member` when both are present', () => {
    expect(hydraMembers({ member: [{ a: 1 }], 'hydra:member': [{ a: 9 }] })).toEqual([{ a: 1 }]);
  });

  it('returns [] when neither key exists', () => {
    expect(hydraMembers({})).toEqual([]);
    expect(hydraMembers({ member: undefined })).toEqual([]);
  });
});

describe('normalizeIri', () => {
  it('overwrites a bare-ULID `id` with the dereferenceable `@id` IRI', () => {
    const raw = { '@id': '/garage/planned-tasks/01J', id: '01J', type: 'oil_change' };
    expect(normalizeIri(raw)).toMatchObject({ id: '/garage/planned-tasks/01J', type: 'oil_change' });
  });

  it('keeps an existing `id` when no `@id` is present', () => {
    expect(normalizeIri({ id: '/already/iri' })).toMatchObject({ id: '/already/iri' });
  });

  it('strips the `@id` key from the normalized object', () => {
    const out = normalizeIri({ '@id': '/x/1', id: '1' }) as Record<string, unknown>;
    expect(out['@id']).toBeUndefined();
    expect(out.id).toBe('/x/1');
  });
});

describe('apiUrl', () => {
  it('prefixes a well-formed API path with the API base', () => {
    expect(apiUrl('/garage/planned-tasks/01J', 'https://localhost')).toBe('https://localhost/garage/planned-tasks/01J');
    expect(apiUrl('/garage/vehicles/01V/documents/01D/file', '')).toBe('/garage/vehicles/01V/documents/01D/file');
  });

  it.each([
    ['a host-suffix that rewrites the origin', '.evil.tld/x'],
    ['a scheme-relative URL', '//evil.tld/x'],
    ['an absolute URL', 'https://evil.tld/x'],
    ['an embedded double slash', '/garage//evil'],
    ['a parent traversal', '/garage/../admin'],
    ['a current-dir segment', '/garage/./x'],
    ['a credentials marker', '/@evil.tld'],
    ['a query string', '/garage/vehicles?x=1'],
    ['a backslash', '/\\evil.tld'],
    ['an empty path', ''],
    ['a bare ULID', '01J'],
  ])('throws on %s', (_label, iri) => {
    expect(() => apiUrl(iri, 'https://localhost')).toThrow(/not an API path/);
  });
});

describe('mergeTaskParts', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('refuses a model-supplied task id that is not an API path, before any request', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    await expect(mergeTaskParts('.evil.tld/x', [])).rejects.toThrow(/not an API path/);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
