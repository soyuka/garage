import { describe, expect, it } from 'vitest';
import { dockScope, scopeInstruction } from './dock';

describe('dockScope', () => {
  it('is the parc on home and the open section on a car page', () => {
    expect(dockScope(null)).toBe('parc');
    expect(dockScope('documents')).toBe('documents');
    expect(dockScope('pieces')).toBe('pieces');
  });
});

describe('scopeInstruction', () => {
  const phrase = (q: string) => `Cherche dans les documents : « ${q} »`;

  it('phrases a submission on Documents as a document search', () => {
    expect(scopeInstruction('documents', '  couple de serrage culasse ', phrase)).toBe('Cherche dans les documents : « couple de serrage culasse »');
  });

  it('leaves every other screen untouched', () => {
    for (const scope of ['parc', 'entretien', 'pieces', 'caracteristiques'] as const) {
      expect(scopeInstruction(scope, 'vidange', phrase)).toBe('vidange');
    }
  });
});
