import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import type { GarageRow, GarageSheet, PlannedItem } from '../garage-vehicles';
import { ConfirmAction, GarageList, GarageLogin, GarageSheetView } from './GaragePage';

const peugeot: GarageRow = {
  id: '01M4X5D9R4FV02S6Q9XW30PBZK', iri: '/garage/vehicles/01M4X5D9R4FV02S6Q9XW30PBZK', plate: 'XD-303-ZD', vin: 'VF33CKFWB83471925', make: 'PEUGEOT', model: '307', name: 'Peugeot 307', year: 2004,
  engineVariant: '1.4 KFW (TU3JP) 75 ch', engineCode: null, vehicleType: 'car', carId: 16182, carType: 'PC', usage: null, addedAt: '2026-10-14T12:15:19+00:00',
};
const toro: GarageRow = {
  id: '01KVKMEFM6V36AWETKE7A9QCJ8', iri: '/garage/vehicles/01KVKMEFM6V36AWETKE7A9QCJ8', plate: null, vin: null, make: 'TORO', model: 'Wheel Horse 246-H', name: 'Toro Wheel Horse 246-H', year: 1992,
  engineVariant: 'Briggs & Stratton', engineCode: null, vehicleType: 'groundscare', carId: null, carType: 'PC', usage: { value: 3, unit: 'h' }, addedAt: '2026-06-20T23:04:26+00:00',
};
const sheet = (vehicle: GarageRow): GarageSheet => ({ vehicle, facts: [{ key: 'paint_code', label: 'Code peinture', value: 'EWP', source: 'Vous', url: null }] });
const planned: PlannedItem[] = [{ id: '01M4D8R97311BQ0S9NSPR7PC4N', label: 'Embrayage', parts: [{ ref: '623 3150 33', brand: 'LuK', label: "Kit d'embrayage" }] }];
const noop = () => undefined;
const view = (s: GarageSheet, p: PlannedItem[] = planned) => renderToStaticMarkup(createElement(GarageSheetView, { sheet: s, planned: p, plannedLoading: false, onSearch: noop, onDeleteVehicle: noop, onDeleteFact: noop, deletingFact: null, deletingVehicle: false, error: null }));

describe('GarageList', () => {
  it('shows every vehicle as a title block opening its sheet, plate or not', () => {
    const html = renderToStaticMarkup(createElement(GarageList, { rows: [peugeot, toro] }));
    expect(html).toContain('href="#/garage/01M4X5D9R4FV02S6Q9XW30PBZK"');
    expect(html).toContain('href="#/garage/01KVKMEFM6V36AWETKE7A9QCJ8"');
    expect(html).toContain('XD-303-ZD');
    expect(html).toContain('Toro Wheel Horse 246-H');
    expect(html).toContain('16182');
  });

  it('says so in French when the garage is empty', () => {
    expect(renderToStaticMarkup(createElement(GarageList, { rows: [] }))).toContain('Aucun véhicule dans le garage');
  });
});

describe('GarageSheetView', () => {
  it('prints the identity, the facts with a delete per row and the planned parts', () => {
    const html = view(sheet(peugeot));
    expect(html).toContain('VF33CKFWB83471925');
    expect(html).toContain('16182');
    expect(html).toContain('14 octobre 2026');
    expect(html).toContain('Code peinture');
    expect(html).toContain('aria-label="Supprimer Code peinture"');
    expect(html).toContain('623 3150 33');
    expect(html).toContain('LuK');
    expect(html).toContain('Chercher une pièce');
    expect(html).toContain('Supprimer le véhicule');
  });

  it('offers no catalogue search for a machine without plate or kType', () => {
    const html = view(sheet(toro), []);
    expect(html).not.toContain('Chercher une pièce');
    expect(html).toContain('3 h');
    expect(html).toContain('Aucune pièce prévue');
  });

  it('never prints the maintenance forecast', () => {
    expect(view(sheet(peugeot))).not.toMatch(/Santé|Prochain|échéance/i);
  });
});

describe('ConfirmAction', () => {
  it('asks once more inline before acting, never through a browser dialog', () => {
    const idle = renderToStaticMarkup(createElement(ConfirmAction, { label: 'Supprimer le véhicule', question: 'Retirer ce véhicule ?', confirm: 'Supprimer', busy: false, onConfirm: noop }));
    expect(idle).toContain('Supprimer le véhicule');
    expect(idle).not.toContain('Retirer ce véhicule ?');
  });
});

describe('GarageLogin', () => {
  it('asks to sign in, and says when the session expired', () => {
    expect(renderToStaticMarkup(createElement(GarageLogin, { expired: false }))).toContain('Se connecter');
    expect(renderToStaticMarkup(createElement(GarageLogin, { expired: true }))).toContain('Session expirée');
  });
});
