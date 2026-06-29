import { describe, expect, it } from 'vitest';
import { buildTurnBody } from './agent';

// The agent turn request shape (CONTRACT.md §Agent). The pure body-builder pins the exact field names
// and the omit-when-absent behavior so an unset optional never reaches the wire as a null.

describe('buildTurnBody', () => {
  it('carries instruction + sessionId, omitting absent optionals', () => {
    expect(buildTurnBody('check the oil', { sessionId: 's1' })).toEqual({ instruction: 'check the oil', sessionId: 's1' });
  });

  it('includes selectedId / summary / pendingContext when provided', () => {
    expect(
      buildTurnBody('la 2.0 16V', {
        sessionId: 's1',
        selectedId: '/garage/vehicles/01J',
        summary: 'MAZDA6 · en retard',
        pendingContext: 'Quelle motorisation ? Options : 2.0, 2.2',
      }),
    ).toEqual({
      instruction: 'la 2.0 16V',
      sessionId: 's1',
      selectedId: '/garage/vehicles/01J',
      summary: 'MAZDA6 · en retard',
      pendingContext: 'Quelle motorisation ? Options : 2.0, 2.2',
    });
  });

  it('omits keys whose value is undefined (never sends selectedId: undefined)', () => {
    const body = buildTurnBody('x', { sessionId: 's1', selectedId: undefined, summary: undefined });
    expect(Object.keys(body).sort()).toEqual(['instruction', 'sessionId']);
  });
});
