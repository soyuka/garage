import { describe, expect, it } from 'vitest';
import { buildTurnBody, dashboardSubscriptions } from './agent';

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

// The pilot channel: an external MCP host drives the SAME dashboard through the owner's pilot topic,
// which GET /agent/link resolves. Until it does (or when unauthenticated), the dashboard runs on its
// two usual topics — the pilot subscription is additive, never a prerequisite.
describe('dashboardSubscriptions', () => {
  it('subscribes to the vehicle collection and this tab session under the in-app driver', () => {
    expect(dashboardSubscriptions('https://localhost', 's1', null)).toEqual([
      { driver: 'app', topics: ['https://localhost/garage/vehicles', 'https://localhost/agent/sessions/s1'] },
    ]);
  });

  it('puts the owner pilot topic on its OWN subscription — the topic a frame arrived on IS its driver', () => {
    expect(dashboardSubscriptions('https://localhost', 's1', 'https://localhost/agent/pilots/deadbeef')).toEqual([
      { driver: 'app', topics: ['https://localhost/garage/vehicles', 'https://localhost/agent/sessions/s1'] },
      { driver: 'pilot', topics: ['https://localhost/agent/pilots/deadbeef'] },
    ]);
  });
});
