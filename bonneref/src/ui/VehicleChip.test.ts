import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import type { Vehicle } from '../types';
import { VehicleChip, vehicleName } from './VehicleChip';

const bare: Vehicle = { plate: null, make: null, model: null, modelEn: null, version: null, engineCode: null, powerHp: null, firstRegistration: null, energy: null, vin: null, carId: 16182, carType: 'PC' };

describe('VehicleChip', () => {
  it('shows the kType once when the car has no name', () => {
    const html = renderToStaticMarkup(createElement(VehicleChip, { vehicle: bare, onSearch: () => {} }));
    expect(html.match(/kType 16182/g)).toHaveLength(2);
    expect(html).toContain('aria-label="Véhicule kType 16182, rechercher sur ce véhicule"');
    expect(html).toContain('<span class="chip-name">Véhicule</span>');
  });

  it('names a car the server identified', () => {
    expect(vehicleName({ ...bare, make: 'PEUGEOT', model: '307 (3A/C)' })).toBe('Peugeot 307 (3A/C)');
  });
});
