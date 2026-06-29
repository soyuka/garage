import { describe, expect, it } from 'vitest';
import { hydraMembers, normalizeIri } from './garage';

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
