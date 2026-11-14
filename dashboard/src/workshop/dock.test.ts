import { describe, expect, it } from 'vitest';
import { dockScope, scopeInstruction, waitLabel } from './dock';

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

  it('sends no search phrase when only files go out on Documents', () => {
    expect(scopeInstruction('documents', '  ', phrase)).toBe('');
  });
});

describe('waitLabel', () => {
  it('says seconds under a minute and whole minutes past it', () => {
    expect(waitLabel(45)).toBe('45\u00a0s');
    expect(waitLabel(61)).toBe('2\u00a0min');
    expect(waitLabel(null)).toBeNull();
  });
});
