import { carRef, parseRef, parseRoute, routeToHash } from './route';

describe('route', () => {
  it('round-trips every view', () => {
    for (const r of [
      { view: 'entry' } as const,
      { view: 'vehicle', ref: 'XB-301-ZB' } as const,
      { view: 'vehicle', ref: '1234 AB 56' } as const,
      { view: 'vehicle', ref: 'k19107-PC' } as const,
      { view: 'result', ref: 'XB-301-ZB', categoryId: 100259, productGroupId: null, cluster: null } as const,
      { view: 'result', ref: 'k19107-PC', categoryId: 100259, productGroupId: null, cluster: { id: 'c2', by: 'answer' } } as const,
      { view: 'result', ref: 'XD-303-ZD', categoryId: 100198, productGroupId: 51, cluster: null } as const,
      { view: 'result', ref: 'k16182-PC', categoryId: 100198, productGroupId: 51, cluster: { id: 'c2', by: 'answer' } } as const,
      { view: 'result', ref: 'k16182-PC', categoryId: 100198, productGroupId: 51, cluster: { id: 'c2', by: 'user' } } as const,
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
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/51')).toEqual({ view: 'result', ref: 'XD-303-ZD', categoryId: 100198, productGroupId: 51, cluster: null });
    expect(routeToHash({ view: 'result', ref: 'k16182-PC', categoryId: 100198, productGroupId: 51, cluster: { id: 'c2', by: 'answer' } })).toBe('#/k/16182-PC/c/100198/g/51/k/c2');
    expect(parseRoute('#/k/16182-PC/c/100198/k/c1')).toEqual({ view: 'result', ref: 'k16182-PC', categoryId: 100198, productGroupId: null, cluster: { id: 'c1', by: 'answer' } });
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/x')).toEqual({ view: 'result', ref: 'XD-303-ZD', categoryId: 100198, productGroupId: null, cluster: null });
  });

  it('keeps a part picked by the user apart from one settled by the question', () => {
    expect(routeToHash({ view: 'result', ref: 'k16182-PC', categoryId: 100198, productGroupId: 51, cluster: { id: 'c2', by: 'user' } })).toBe('#/k/16182-PC/c/100198/g/51/v/c2');
    expect(parseRoute('#/k/16182-PC/c/100198/g/51/v/c2')).toEqual({ view: 'result', ref: 'k16182-PC', categoryId: 100198, productGroupId: 51, cluster: { id: 'c2', by: 'user' } });
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/51/k/c1')).toEqual({ view: 'result', ref: 'XD-303-ZD', categoryId: 100198, productGroupId: 51, cluster: { id: 'c1', by: 'answer' } });
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/51/v')).toEqual({ view: 'result', ref: 'XD-303-ZD', categoryId: 100198, productGroupId: 51, cluster: null });
  });

  it('carries a part retained by the agent with its basis, apart from the user and the cote', () => {
    const basis = 'code de fabrication (OPR 09824 ≤ 10310)';
    const r = { view: 'result', ref: 'XD-303-ZD', categoryId: 100198, productGroupId: 51, cluster: { id: 'c1', by: 'agent', basis } } as const;
    expect(routeToHash(r)).toBe('#/p/XD-303-ZD/c/100198/g/51/r/c1/code%20de%20fabrication%20(OPR%2009824%20%E2%89%A4%2010310)');
    expect(parseRoute(routeToHash(r))).toEqual(r);
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/51/r/c1')).toEqual({ ...r, cluster: { id: 'c1', by: 'agent', basis: '' } });
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/51/r')).toEqual({ ...r, cluster: null });
  });

  it('carries what the agent set aside, and a pick that keeps the agent’s verdict', () => {
    const verdict = { id: 'c1', basis: 'OPR 09824 ≤ 10310', setAside: 'OPR ≥ 10311' };
    const agent = { view: 'result', ref: 'XD-303-ZD', categoryId: 100198, productGroupId: 51, cluster: { id: 'c1', by: 'agent', basis: verdict.basis, setAside: verdict.setAside } } as const;
    expect(routeToHash(agent)).toBe('#/p/XD-303-ZD/c/100198/g/51/r/c1/OPR%2009824%20%E2%89%A4%2010310/OPR%20%E2%89%A5%2010311');
    expect(parseRoute(routeToHash(agent))).toEqual(agent);
    const pick = { ...agent, cluster: { id: 'c2', by: 'user', verdict } } as const;
    expect(routeToHash(pick)).toBe('#/p/XD-303-ZD/c/100198/g/51/v/c2/r/c1/OPR%2009824%20%E2%89%A4%2010310/OPR%20%E2%89%A5%2010311');
    expect(parseRoute(routeToHash(pick))).toEqual(pick);
  });

  it('tells a plate from a catalogue car', () => {
    expect(parseRef('XB-301-ZB')).toEqual({ kind: 'plate', plate: 'XB-301-ZB' });
    expect(parseRef('k19107-CV')).toEqual({ kind: 'car', carId: 19107, carType: 'CV' });
    expect(carRef(19107, 'PC')).toBe('k19107-PC');
  });
});
