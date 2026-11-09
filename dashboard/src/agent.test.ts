import { describe, expect, it } from 'vitest';
import { buildTurnBody, dashboardSubscriptions, parseAgentLink, SESSION_KEY, tabSessionId } from './agent';

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

// The session id is the tab's, not the page instance's: it is what the turn's Mercure topic is keyed
// on, so a reload mid-turn (F5, a Vite full reload) must come back on the SAME topic or every frame
// the worker publishes after the reload lands on a topic nobody listens to (measured 2026-09-05:
// a turn queued 40s, the operator reloaded 1s before it started, nothing was ever shown).
describe('tabSessionId', () => {
  const memoryStorage = (seed: Record<string, string> = {}) => {
    const data = { ...seed };
    return {
      data,
      getItem: (k: string) => data[k] ?? null,
      setItem: (k: string, v: string) => {
        data[k] = v;
      },
    };
  };

  it('reuses the id this tab already holds — a reload keeps its session topic', () => {
    const storage = memoryStorage({ [SESSION_KEY]: 'kept-1' });
    expect(tabSessionId(storage, () => 'fresh')).toBe('kept-1');
    expect(storage.data[SESSION_KEY]).toBe('kept-1');
  });

  it('mints and stores one when the tab has none yet', () => {
    const storage = memoryStorage();
    expect(tabSessionId(storage, () => 'fresh-1')).toBe('fresh-1');
    expect(storage.data[SESSION_KEY]).toBe('fresh-1');
  });

  it('treats an empty stored value as absent', () => {
    const storage = memoryStorage({ [SESSION_KEY]: '' });
    expect(tabSessionId(storage, () => 'fresh-2')).toBe('fresh-2');
    expect(storage.data[SESSION_KEY]).toBe('fresh-2');
  });

  it('still yields a usable id when storage is unavailable or throws', () => {
    expect(tabSessionId(null, () => 'fresh-3')).toBe('fresh-3');
    const throwing = {
      getItem: () => {
        throw new Error('blocked');
      },
      setItem: () => {
        throw new Error('blocked');
      },
    };
    expect(tabSessionId(throwing, () => 'fresh-4')).toBe('fresh-4');
  });

  it('gives two tabs (two storages) two distinct ids', () => {
    let n = 0;
    const mint = () => `id-${++n}`;
    expect(tabSessionId(memoryStorage(), mint)).not.toBe(tabSessionId(memoryStorage(), mint));
  });
});

describe('parseAgentLink', () => {
  it('reads the pilot topic and the capabilities the server advertises', () => {
    expect(
      parseAgentLink({ topic: 'https://localhost/agent/pilots/abc', capabilities: { agent: true, voice: false, dictation: false, web_search: true } }),
    ).toEqual({ topic: 'https://localhost/agent/pilots/abc', capabilities: { agent: true, voice: false, dictation: false, web_search: true } });
  });

  it('treats a server that predates capabilities as having everything on, so the 503 fallbacks stay in charge', () => {
    expect(parseAgentLink({ topic: 't' })).toEqual({ topic: 't', capabilities: { agent: true, voice: true, dictation: true, web_search: true } });
  });

  it('only a literal false switches a capability off', () => {
    expect(parseAgentLink({ capabilities: { agent: 'no', voice: 0, dictation: null } }).capabilities).toEqual({ agent: true, voice: true, dictation: true, web_search: true });
  });

  it('survives a body that is not an object', () => {
    expect(parseAgentLink(null)).toEqual({ topic: null, capabilities: { agent: true, voice: true, dictation: true, web_search: true } });
  });
});
