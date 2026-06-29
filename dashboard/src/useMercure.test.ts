import { describe, expect, it } from 'vitest';
import { buildHubUrl, isDomainEvent, parseFrame, removeVehicle, upsertVehicle } from './useMercure';
import type { DomainEvent, UIAction, VehicleSummary } from './types';

// Pure-core tests for the Mercure transport. The EventSource lifecycle is thin glue; these pin the
// hub-URL build, the keep-alive-tolerant frame parse, the domain/session discriminator, and the
// collection reconciliation the live cascade runs (no follow-up fetch).

function vehicle(iri: string, over: Partial<VehicleSummary> = {}): VehicleSummary {
  return { '@id': iri, make: 'Renault', model: 'Clio', vehicleType: 'car', source: 'user', factCount: 0, addedAt: '2025-01-01', ...over };
}

describe('buildHubUrl', () => {
  it('appends each topic as a repeated ?topic= param against the page origin', () => {
    const url = buildHubUrl('/.well-known/mercure', 'https://localhost', ['https://localhost/garage/vehicles', 'https://localhost/agent/sessions/abc']);
    const u = new URL(url);
    expect(u.origin + u.pathname).toBe('https://localhost/.well-known/mercure');
    expect(u.searchParams.getAll('topic')).toEqual(['https://localhost/garage/vehicles', 'https://localhost/agent/sessions/abc']);
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
