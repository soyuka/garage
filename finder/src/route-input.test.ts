import { routeFile, routeText } from './route-input';

describe('routeText: plates', () => {
  it.each([
    ['XB-301-ZB', 'XB-301-ZB'],
    ['xb301zb', 'XB-301-ZB'],
    ['xb 301 zb', 'XB-301-ZB'],
    [' Xb-301 zb ', 'XB-301-ZB'],
  ])('reads %s as a SIV plate', (input, plate) => {
    expect(routeText(input)).toEqual({ kind: 'plate', plate, format: 'siv' });
  });

  it.each([
    ['1234 AB 56', '1234 AB 56'],
    ['1234ab56', '1234 AB 56'],
    ['12 ABC 2A', '12 ABC 2A'],
    ['987 xy 75', '987 XY 75'],
  ])('reads %s as an old FNI plate', (input, plate) => {
    expect(routeText(input)).toEqual({ kind: 'plate', plate, format: 'fni' });
  });

  it('wins over free text', () => {
    expect(routeText('XB-301-ZB').kind).toBe('plate');
  });
});

describe('routeText: VIN', () => {
  it('reads 17 VIN characters, spaces and case aside', () => {
    expect(routeText('jmzgg14f671528417')).toEqual({ kind: 'vin', vin: 'JMZGG14F671528417' });
    expect(routeText('JMZ GG14F6 71528417')).toEqual({ kind: 'vin', vin: 'JMZGG14F671528417' });
  });

  it('refuses I, O and Q, which a VIN never holds', () => {
    expect(routeText('JMZGG14F67169388O').kind).not.toBe('vin');
  });
});

describe('routeText: part references', () => {
  it.each(['W 712/73', 'LF10-14-302', 'F 026 407 085', 'LS357', '1S7G-6714-CA', '30711781', 'SP-1296', 'CT1088', 'w 712/73', '0 986 494 054'])(
    'reads %s as a reference',
    (input) => {
      expect(routeText(input)).toEqual({ kind: 'reference', reference: input.trim() });
    },
  );

  it.each([
    'filtre à huile 307 1.4 hdi',
    '307 1.4 hdi',
    'mazda 6 2.0 2005',
    'clio 3 dci 105',
    'disque 280 mm',
    'AB12',
    'W 7',
    'un très long texte avec 123 chiffres dedans',
    'golf 4 tdi 110',
  ])('keeps %s as free text', (input) => {
    expect(routeText(input).kind).toBe('text');
  });
});

describe('routeText: free text', () => {
  it('sends a TecDoc category name to the agent', () => {
    expect(routeText('filtre à huile')).toEqual({ kind: 'text', text: 'filtre à huile' });
    expect(routeText('Filtre a huile')).toEqual({ kind: 'text', text: 'Filtre a huile' });
  });

  it('sends anything else to the agent, trimmed', () => {
    expect(routeText('  mes freins grincent  ')).toEqual({ kind: 'text', text: 'mes freins grincent' });
  });

  it('ignores blank input', () => {
    expect(routeText('   ')).toEqual({ kind: 'empty' });
  });
});

describe('routeFile', () => {
  it.each([
    ['image/jpeg', 'image'],
    ['image/png', 'image'],
    ['image/webp', 'image'],
    ['image/heic', 'image'],
    ['video/mp4', 'video'],
    ['video/quicktime', 'video'],
    ['application/pdf', 'pdf'],
  ] as const)('routes %s to %s', (type, kind) => {
    expect(routeFile({ type, size: 1024 })).toEqual({ kind });
  });

  it('refuses other types and oversized documents', () => {
    expect(routeFile({ type: 'text/plain', size: 10 })).toEqual({ kind: 'unsupported', reason: 'type' });
    expect(routeFile({ type: 'application/pdf', size: 11 * 1024 * 1024 })).toEqual({ kind: 'unsupported', reason: 'size' });
  });

  it('lets a large photo or video through: it is downscaled or sampled on the device', () => {
    expect(routeFile({ type: 'image/jpeg', size: 25 * 1024 * 1024 })).toEqual({ kind: 'image' });
    expect(routeFile({ type: 'video/mp4', size: 200 * 1024 * 1024 })).toEqual({ kind: 'video' });
  });
});
