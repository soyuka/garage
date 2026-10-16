import { formatCount, formatMeasure, formatPlateInput, isPlate, isVinShape, normalisePlate, normaliseVin } from './format';

const NNBSP = ' ';

describe('plate', () => {
  it('normalises a SIV plate to AA-123-AA whatever the separators and case', () => {
    expect(normalisePlate('xb301zb')).toBe('XB-301-ZB');
    expect(normalisePlate(' XB 301 ZB ')).toBe('XB-301-ZB');
    expect(normalisePlate('xb-301_zb')).toBe('XB-301-ZB');
  });

  it('rejects what is not a SIV plate', () => {
    expect(normalisePlate('1234 AB 75')).toBeNull();
    expect(normalisePlate('FJ109X')).toBeNull();
    expect(isPlate('XB-301-ZB')).toBe(true);
    expect(isPlate('FJ-109-X')).toBe(false);
  });

  it('inserts the dashes while typing', () => {
    expect(formatPlateInput('f')).toBe('F');
    expect(formatPlateInput('fj1')).toBe('FJ-1');
    expect(formatPlateInput('fj109')).toBe('FJ-109');
    expect(formatPlateInput('fj109x')).toBe('FJ-109-X');
    expect(formatPlateInput('XB-301-ZBZZ')).toBe('XB-301-ZB');
  });
});

describe('vin', () => {
  it('uppercases, strips separators and maps the forbidden I O Q', () => {
    expect(normaliseVin('jmz gg14f6-71528417')).toBe('JMZGG14F671528417');
    expect(normaliseVin('JMZGGI4F67I528417')).toBe('JMZGG14F671528417');
    expect(normaliseVin('VF1OOOOOOOOOOOOOQ')).toBe('VF10000000000000' + '0');
  });

  it('checks the 17-character shape', () => {
    expect(isVinShape('JMZGG14F671528417')).toBe(true);
    expect(isVinShape('JMZGG14F67169388')).toBe(false);
  });
});

describe('numbers', () => {
  it('groups thousands with a narrow no-break space', () => {
    expect(formatCount(220)).toBe('220');
    expect(formatCount(12345)).toBe(`12${NNBSP}345`);
  });

  it('joins a measure and its unit with a narrow no-break space', () => {
    expect(formatMeasure('93', 'mm')).toBe(`93${NNBSP}mm`);
    expect(formatMeasure('1.5', 'bar')).toBe(`1,5${NNBSP}bar`);
    expect(formatMeasure('Filtre vissé', null)).toBe('Filtre vissé');
  });
});
