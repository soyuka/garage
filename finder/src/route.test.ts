import { carRef, parseRef, parseRoute, routeToHash } from './route';

describe('route', () => {
  it('round-trips every view', () => {
    for (const r of [
      { view: 'entry' } as const,
      { view: 'vehicle', ref: 'XB-301-ZB' } as const,
      { view: 'vehicle', ref: '1234 AB 56' } as const,
      { view: 'vehicle', ref: 'k19107-PC' } as const,
      { view: 'result', ref: 'XB-301-ZB', categoryId: 100259, productGroupId: null, clusterId: null } as const,
      { view: 'result', ref: 'k19107-PC', categoryId: 100259, productGroupId: null, clusterId: 'c2' } as const,
      { view: 'result', ref: 'XD-303-ZD', categoryId: 100198, productGroupId: 51, clusterId: null } as const,
      { view: 'result', ref: 'k16182-PC', categoryId: 100198, productGroupId: 51, clusterId: 'c2' } as const,
    ]) {
      expect(parseRoute(routeToHash(r))).toEqual(r);
    }
  });

  it('normalises a hand-typed plate and drops garbage', () => {
    expect(parseRoute('#/p/xb301zb')).toEqual({ view: 'vehicle', ref: 'XB-301-ZB' });
    expect(parseRoute('#/p/nope')).toEqual({ view: 'entry' });
    expect(parseRoute('#/k/0-PC')).toEqual({ view: 'entry' });
    expect(parseRoute('#/k/19107-XX')).toEqual({ view: 'entry' });
    expect(parseRoute('#/p/XB-301-ZB/c/abc')).toEqual({ view: 'vehicle', ref: 'XB-301-ZB' });
    expect(parseRoute('')).toEqual({ view: 'entry' });
  });

  it('reads the product group of a result, old routes without one included', () => {
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/51')).toEqual({ view: 'result', ref: 'XD-303-ZD', categoryId: 100198, productGroupId: 51, clusterId: null });
    expect(routeToHash({ view: 'result', ref: 'k16182-PC', categoryId: 100198, productGroupId: 51, clusterId: 'c2' })).toBe('#/k/16182-PC/c/100198/g/51/k/c2');
    expect(parseRoute('#/k/16182-PC/c/100198/k/c1')).toEqual({ view: 'result', ref: 'k16182-PC', categoryId: 100198, productGroupId: null, clusterId: 'c1' });
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/x')).toEqual({ view: 'result', ref: 'XD-303-ZD', categoryId: 100198, productGroupId: null, clusterId: null });
  });

  it('tells a plate from a catalogue car', () => {
    expect(parseRef('XB-301-ZB')).toEqual({ kind: 'plate', plate: 'XB-301-ZB' });
    expect(parseRef('k19107-CV')).toEqual({ kind: 'car', carId: 19107, carType: 'CV' });
    expect(carRef(19107, 'PC')).toBe('k19107-PC');
  });
});
