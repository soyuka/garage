import { describe, expect, it, vi } from 'vitest';
import { buildHubUrl, isDomainEvent, openStreams, parseFrame, reconcileVehicleEvent, removeVehicle, upsertVehicle } from './useMercure';
import type { DomainEvent, UIAction, VehicleSummary } from './types';

// Pure-core tests for the Mercure transport. The EventSource lifecycle is thin glue; these pin the
// hub-URL build, the keep-alive-tolerant frame parse, the domain/session discriminator, and the
// collection reconciliation the live cascade runs (no follow-up fetch).

function vehicle(iri: string, over: Partial<VehicleSummary> = {}): VehicleSummary {
  return { '@id': iri, make: 'Renault', model: 'Clio', vehicleType: 'car', source: 'user', factCount: 0, addedAt: '2025-01-01', ...over };
}

describe('buildHubUrl', () => {
  it('appends each topic as a repeated ?match= param against the page origin', () => {
    const url = buildHubUrl('/.well-known/mercure', 'https://localhost', ['https://localhost/garage/owners/cafe/vehicles', 'https://localhost/agent/sessions/abc']);
    const u = new URL(url);
    expect(u.origin + u.pathname).toBe('https://localhost/.well-known/mercure');
    expect(u.searchParams.getAll('match')).toEqual(['https://localhost/garage/owners/cafe/vehicles', 'https://localhost/agent/sessions/abc']);
    expect(u.searchParams.has('topic')).toBe(false);
    // The subscriber token rides the hub's HttpOnly cookie (set by GET /agent/link), never the URL.
    expect(u.searchParams.has('authorization')).toBe(false);
  });
});

class FakeSource {
  static CLOSED = 2;
  static instances: FakeSource[] = [];
  readyState = 0;
  closed = false;
  onopen: (() => void) | null = null;
  onerror: (() => void) | null = null;
  onmessage: ((e: MessageEvent<string>) => void) | null = null;
  constructor(readonly url: string) {
    FakeSource.instances.push(this);
  }
  close() {
    this.closed = true;
  }
}

describe('openStreams', () => {
  const subs = [
    { driver: 'app' as const, topics: ['https://localhost/garage/owners/cafe/vehicles'], generation: 1 },
    { driver: 'pilot' as const, topics: ['https://localhost/agent/pilots/abc'], generation: 1 },
  ];

  it('opens one stream per subscription and reports when the hub refuses one', () => {
    FakeSource.instances = [];
    const refused = vi.fn();
    const opened = vi.fn();
    const close = openStreams(subs, '/.well-known/mercure', 'https://localhost', () => {}, { onRefused: refused, onOpen: opened }, FakeSource as unknown as typeof EventSource);

    expect(FakeSource.instances.map((s) => new URL(s.url).searchParams.getAll('match'))).toEqual([['https://localhost/garage/owners/cafe/vehicles'], ['https://localhost/agent/pilots/abc']]);

    // A network blip: EventSource reconnects on its own (CONNECTING), nothing to refresh.
    FakeSource.instances[0].onerror?.();
    expect(refused).not.toHaveBeenCalled();

    // The reconnect is refused (expired token → 401): the browser gives up for good.
    FakeSource.instances[0].readyState = FakeSource.CLOSED;
    FakeSource.instances[0].onerror?.();
    expect(refused).toHaveBeenCalledTimes(1);

    FakeSource.instances[1].onopen?.();
    expect(opened).toHaveBeenCalledTimes(1);

    close();
    expect(FakeSource.instances.every((s) => s.closed)).toBe(true);
  });
});

describe('parseFrame', () => {
  it('parses a JSON frame', () => {
    expect(parseFrame('{"type":"turn","phase":"start"}')).toEqual({ type: 'turn', phase: 'start' });
  });
  it('returns null on a non-JSON keep-alive', () => {
    expect(parseFrame(':')).toBeNull();
    expect(parseFrame('')).toBeNull();
  });
});

describe('isDomainEvent', () => {
  it('is true for a vehicle domain event (discriminated by kind)', () => {
    const e: DomainEvent = { kind: 'vehicle', event: 'updated', vehicle: 'x', iri: '/garage/vehicles/1', resource: vehicle('/garage/vehicles/1') };
    expect(isDomainEvent(e)).toBe(true);
  });
  it('is false for a session UIAction (discriminated by type)', () => {
    const a: UIAction = { type: 'status', message: 'hi' };
    expect(isDomainEvent(a)).toBe(false);
  });
});

describe('upsertVehicle — collection reconciliation', () => {
  it('replaces an existing member by @id (preserving order)', () => {
    const list = [vehicle('/garage/vehicles/A', { make: 'Old' }), vehicle('/garage/vehicles/B')];
    const out = upsertVehicle(list, vehicle('/garage/vehicles/A', { make: 'New' }));
    expect(out.map((v) => v.make)).toEqual(['New', 'Renault']);
    expect(out.length).toBe(2);
  });
  it('appends a newly-created member', () => {
    const out = upsertVehicle([vehicle('/garage/vehicles/A')], vehicle('/garage/vehicles/C'));
    expect(out.map((v) => v['@id'])).toEqual(['/garage/vehicles/A', '/garage/vehicles/C']);
  });
});

describe('removeVehicle', () => {
  it('drops the member with the given IRI', () => {
    const list = [vehicle('/garage/vehicles/A'), vehicle('/garage/vehicles/B')];
    expect(removeVehicle(list, '/garage/vehicles/A').map((v) => v['@id'])).toEqual(['/garage/vehicles/B']);
  });
});

describe('parseFrame — discriminant validation', () => {
  it.each([
    ['an unknown UIAction type', { type: 'setVehicles', vehicles: [] }],
    ['a made-up type', { type: 'runScript', code: 'x' }],
    ['a non-string type', { type: 42 }],
    ['no discriminant at all', { hello: 'world' }],
    ['a JSON array', [{ type: 'toast' }]],
    ['a JSON scalar', 'toast'],
    ['null', null],
    ['a domain event of another kind', { kind: 'user', event: 'updated', iri: '/u/1', resource: {} }],
    ['a domain event with an unknown verb', { kind: 'vehicle', event: 'hijacked', iri: '/garage/vehicles/01V', resource: { '@id': '/garage/vehicles/01V' } }],
    ['a domain event without a resource', { kind: 'vehicle', event: 'updated', iri: '/garage/vehicles/01V' }],
    ['a domain event whose resource has no @id', { kind: 'vehicle', event: 'updated', iri: '/garage/vehicles/01V', resource: {} }],
  ])('drops %s', (_label, payload) => {
    expect(parseFrame(JSON.stringify(payload))).toBeNull();
  });

  it('keeps every known UIAction type and a well-formed domain event', () => {
    expect(parseFrame('{"type":"selectVehicle","id":"/garage/vehicles/01V"}')).not.toBeNull();
    expect(parseFrame('{"type":"turn","phase":"start"}')).not.toBeNull();
    expect(parseFrame('{"type":"documentScan","vehicle":"/garage/vehicles/01V","query":"q","hits":[]}')).not.toBeNull();
    expect(parseFrame('{"kind":"vehicle","event":"deleted","vehicle":"01V","iri":"/garage/vehicles/01V","resource":{"@id":"/garage/vehicles/01V"}}')).not.toBeNull();
  });
});

describe('reconcileVehicleEvent — a pushed vehicle only updates the fleet already loaded', () => {
  it('replaces a known member in place', () => {
    const list = [vehicle('/garage/vehicles/1'), vehicle('/garage/vehicles/2')];
    const out = reconcileVehicleEvent(list, vehicle('/garage/vehicles/2', { model: 'Kangoo' }));
    expect(out.refetch).toBe(false);
    expect(out.list.map((v) => v.model)).toEqual(['Clio', 'Kangoo']);
  });

  it('never appends an unknown @id from the frame; it asks for the owner listing instead', () => {
    const list = [vehicle('/garage/vehicles/1')];
    const out = reconcileVehicleEvent(list, vehicle('/garage/vehicles/foreign'));
    expect(out.list).toBe(list);
    expect(out.refetch).toBe(true);
  });
});
