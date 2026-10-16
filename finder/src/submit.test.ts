import type { Mock } from 'vitest';
import { ApiError } from './api';
import { turnBody, turnVehicle } from './agent';
import { type FinderApi } from './client';
import { parseRoute, routeToHash } from './route';
import { routeText } from './route-input';
import { registrationBody, runSubmission, type Reading, type SubmitDeps } from './submit';
import type { Registration, Vehicle } from './types';

const registration: Registration = {
  plate: 'XB-301-ZB',
  vin: 'JMZGG14F671528417',
  fields: [
    { code: 'A', label: 'Immatriculation', value: 'XB-301-ZB' },
    { code: 'B', label: 'Première immatriculation', value: '2007-03-29' },
    { code: 'D.1', label: 'Marque', value: 'MAZDA' },
    { code: 'D.2.1', label: 'Code national (CNIT)', value: 'MAZ0001' },
    { code: 'D.3', label: 'Dénomination commerciale', value: 'MAZDA6' },
    { code: 'E', label: 'Numéro VIN', value: 'JMZGG14F671528417' },
    { code: 'P.1', label: 'Cylindrée (cm³)', value: '1999' },
    { code: 'P.2', label: 'Puissance nette (kW)', value: '108' },
    { code: 'P.3', label: 'Énergie', value: 'ES' },
    { code: 'P.6', label: 'Puissance fiscale (CV)', value: '11' },
  ],
  legible: true,
  warnings: [],
};
const vehicle: Vehicle = {
  plate: 'XB-301-ZB', make: 'MAZDA', model: 'MAZDA6', modelEn: '6 Hatchback', version: '2.0 MZR147', engineCode: 'LFF7',
  powerHp: 200, firstRegistration: '2007-03-29', energy: 'ESSENCE', vin: 'JMZGG14F671528417', carId: 19107, carType: 'PC',
};
const pdf = new File(['%PDF'], 'carte-grise.pdf', { type: 'application/pdf' });

function setup(overrides: Partial<FinderApi> = {}, reading: Reading = { kind: 'carte_grise', registration }) {
  const api = {
    vehicle: vi.fn().mockResolvedValue(vehicle),
    saveVehicle: vi.fn().mockResolvedValue({ vehicle: '/garage/vehicles/01V', created: true }),
    ...overrides,
  } as { vehicle: Mock; saveVehicle: Mock };
  const read = vi.fn().mockResolvedValue(reading);
  const statuses: (string | null)[] = [];
  const deps: SubmitDeps = { api, read, status: (s) => statuses.push(s) };
  return { api, read, statuses, deps };
}

describe('registrationBody', () => {
  it('maps only the read fields to the save payload, never the holder section', () => {
    expect(registrationBody(registration)).toEqual({ vin: 'JMZGG14F671528417', firstRegistration: '2007-03-29', cnit: 'MAZ0001', displacement: '1999', powerKw: '108', energy: 'ES', fiscalPower: '11' });
    expect(registrationBody({ ...registration, fields: [{ code: 'E', label: 'Numéro VIN', value: 'X' }] })).toEqual({ vin: 'X' });
  });
});

describe('single-submit pipeline', () => {
  it('reads the carte grise once, saves once, sets the route, then the typed part carries the vehicle', async () => {
    const { api, read, statuses, deps } = setup();
    const out = await runSubmission({ route: routeText('filtre à huile'), files: [pdf] }, deps);

    expect(read).toHaveBeenCalledTimes(1);
    expect(api.saveVehicle).toHaveBeenCalledTimes(1);
    expect(api.saveVehicle.mock.calls[0]![0]).toMatchObject({ make: 'MAZDA', model: 'MAZDA6', carId: 19107, plate: 'XB-301-ZB', registration: { cnit: 'MAZ0001' } });
    expect(statuses).toEqual(['Lecture de la carte grise…', 'Véhicule : Mazda 6']);
    if (out.kind !== 'vehicle') throw new Error(out.kind);
    expect(parseRoute(routeToHash({ view: 'vehicle', ref: out.ref }))).toEqual({ view: 'vehicle', ref: 'XB-301-ZB' });
    expect(out.ask).toMatchObject({ text: 'filtre à huile' });
    const body = turnBody({ instruction: out.ask!.text, sessionId: 's', vehicle: turnVehicle(out.vehicle) });
    expect(body.vehicle).toMatchObject({ carId: 19107, plate: 'XB-301-ZB' });
  });

  it('with only a carte grise sets the vehicle and sends no turn', async () => {
    const { deps } = setup();
    const out = await runSubmission({ route: { kind: 'empty' }, files: [pdf] }, deps);
    expect(out).toMatchObject({ kind: 'vehicle', ref: 'XB-301-ZB', ask: null });
  });

  it('keeps the text and sends no turn when the plate is not in the SIV', async () => {
    const { api, deps } = setup({ vehicle: vi.fn().mockRejectedValue(new ApiError('notFound')) });
    const out = await runSubmission({ route: routeText('filtre à huile'), files: [pdf] }, deps);
    expect(out).toMatchObject({ kind: 'stopped', keepText: true });
    expect((out as { message: string }).message).toContain('XB-301-ZB');
    expect(api.saveVehicle.mock.calls[0]?.[0]).toMatchObject({ make: 'MAZDA', model: 'MAZDA6', plate: null, vin: 'JMZGG14F671528417' });
  });

  it('does not save a lookup miss that carries no VIN', async () => {
    const { api, deps } = setup({ vehicle: vi.fn().mockRejectedValue(new ApiError('notFound')) }, { kind: 'carte_grise', registration: { ...registration, vin: null } });
    const out = await runSubmission({ route: { kind: 'empty' }, files: [pdf] }, deps);
    expect(out.kind).toBe('stopped');
    expect(api.saveVehicle).not.toHaveBeenCalled();
  });

  it('stops without a lookup when the plate was unreadable', async () => {
    const { api, deps } = setup({}, { kind: 'carte_grise', registration: { ...registration, plate: null } });
    const out = await runSubmission({ route: routeText('filtre à huile'), files: [pdf] }, deps);
    expect(out).toMatchObject({ kind: 'stopped', keepText: true });
    expect(api.vehicle).not.toHaveBeenCalled();
  });

  it('still opens the vehicle when the garage save fails', async () => {
    const { deps } = setup({ saveVehicle: vi.fn().mockRejectedValue(new Error('down')) });
    const out = await runSubmission({ route: { kind: 'empty' }, files: [pdf] }, deps);
    expect(out).toMatchObject({ kind: 'vehicle', saved: false });
  });

  it('reads one intake per attachment and hands a part photo to the agent', async () => {
    const photo = new File(['x'], 'p.jpg', { type: 'image/jpeg' });
    const { read, deps } = setup({}, { kind: 'part', part: { familyGuess: 'filtre', printedReferences: ['W 712/73'], brandGuess: null }, images: ['data:a'] });
    const out = await runSubmission({ route: { kind: 'empty' }, files: [photo, photo] }, deps);
    expect(read).toHaveBeenCalledTimes(2);
    expect(out).toMatchObject({ kind: 'ask', ask: { intent: 'photo', images: ['data:a', 'data:a'] } });
  });
});
