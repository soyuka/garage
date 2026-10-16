import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import type { Vehicle } from '../types';
import { contextLabel, ContextToken, MyVehicles } from './VehicleContext';

const mazda: Vehicle = {
  plate: 'XB-301-ZB', make: 'MAZDA', model: 'MAZDA6', modelEn: '6 Hatchback', version: '2.0 MZR147', engineCode: 'LFF7',
  powerHp: 200, firstRegistration: null, energy: null, vin: null, carId: 19107, carType: 'PC',
};

describe('contextLabel', () => {
  it('names the car by plate, else short name, else kType', () => {
    expect(contextLabel(mazda)).toEqual({ text: 'XB-301-ZB', mono: true });
    expect(contextLabel({ ...mazda, plate: null })).toEqual({ text: 'Mazda 6', mono: false });
    expect(contextLabel({ ...mazda, plate: null, make: null, model: null, modelEn: null })).toEqual({ text: 'kType 19107', mono: true });
  });
});

describe('ContextToken', () => {
  it('pins the car in the composer with a named remove button', () => {
    const html = renderToStaticMarkup(createElement(ContextToken, { vehicle: mazda, onRemove: () => {} }));
    expect(html).toContain('XB-301-ZB');
    expect(html).toContain('aria-label="Retirer le véhicule XB-301-ZB"');
    expect(html).toContain('type="button"');
  });
});

describe('MyVehicles', () => {
  const picks = [
    { iri: '/garage/vehicles/01A', plate: 'XB-301-ZB', name: 'Mazda 6', carId: 19107, carType: 'PC' as const, make: 'MAZDA', model: 'MAZDA6', engine: null },
    { iri: '/garage/vehicles/01B', plate: null, name: 'Renault Clio', carId: 17010, carType: 'PC' as const, make: 'RENAULT', model: 'CLIO III', engine: null },
  ];

  it('lists the saved cars as one labelled row of buttons', () => {
    const html = renderToStaticMarkup(createElement(MyVehicles, { vehicles: picks, busy: false, onPick: () => {} }));
    expect(html).toContain('Mes véhicules');
    expect(html).toContain('role="group"');
    expect(html.match(/<button/g)).toHaveLength(2);
    expect(html).toContain('XB-301-ZB');
    expect(html).toContain('Renault Clio');
  });

  it('renders nothing without a saved car', () => {
    expect(renderToStaticMarkup(createElement(MyVehicles, { vehicles: [], busy: false, onPick: () => {} }))).toBe('');
  });
});
