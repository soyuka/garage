import { parseRoute, routeToHash } from './route';

describe('route', () => {
  it('round-trips every view', () => {
    for (const r of [
      { view: 'entry' } as const,
      { view: 'vehicle', plate: 'XB-301-ZB' } as const,
      { view: 'result', plate: 'XB-301-ZB', categoryId: 100259, clusterId: null } as const,
      { view: 'result', plate: 'XB-301-ZB', categoryId: 100259, clusterId: 'c2' } as const,
    ]) {
      expect(parseRoute(routeToHash(r))).toEqual(r);
    }
  });

  it('normalises a hand-typed plate and drops garbage', () => {
    expect(parseRoute('#/p/xb301zb')).toEqual({ view: 'vehicle', plate: 'XB-301-ZB' });
    expect(parseRoute('#/p/nope')).toEqual({ view: 'entry' });
    expect(parseRoute('#/p/XB-301-ZB/c/abc')).toEqual({ view: 'vehicle', plate: 'XB-301-ZB' });
    expect(parseRoute('')).toEqual({ view: 'entry' });
  });
});
