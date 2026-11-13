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

  const SEL = '01JA5Z3M8Q6W4XK7T2N9R0BCDE';

  it('carries a part retained by the agent as ids only, the selection the server stored', () => {
    const r = { view: 'result', ref: 'XD-303-ZD', categoryId: 100198, productGroupId: 51, cluster: { id: 'c1', by: 'agent', selectionId: SEL } } as const;
    expect(routeToHash(r)).toBe(`#/p/XD-303-ZD/c/100198/g/51/r/c1/${SEL}`);
    expect(parseRoute(routeToHash(r))).toEqual(r);
    const pick = { ...r, cluster: { id: 'c2', by: 'user', retained: { id: 'c1', selectionId: SEL } } } as const;
    expect(routeToHash(pick)).toBe(`#/p/XD-303-ZD/c/100198/g/51/v/c2/r/c1/${SEL}`);
    expect(parseRoute(routeToHash(pick))).toEqual(pick);
  });

  it('never reads text from an agent route: old or forged ones fall back to the neutral state', () => {
    const base = { view: 'result', ref: 'XD-303-ZD', categoryId: 100198, productGroupId: 51 } as const;
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/51/r/c1/code%20de%20fabrication%20(OPR%2009824%20%E2%89%A4%2010310)')).toEqual({ ...base, cluster: null });
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/51/r/c1/OPR%2009824/OPR%20%E2%89%A5%2010311')).toEqual({ ...base, cluster: null });
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/51/r/c1')).toEqual({ ...base, cluster: null });
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/51/r')).toEqual({ ...base, cluster: null });
    expect(parseRoute('#/p/XD-303-ZD/c/100198/g/51/v/c2/r/c1/Votre%20v%C3%A9hicule')).toEqual({ ...base, cluster: { id: 'c2', by: 'user' } });
    expect(JSON.stringify(parseRoute(`#/p/XD-303-ZD/c/100198/g/51/r/c1/${SEL}/OPR%20%E2%89%A5%2010311`))).not.toContain('OPR');
  });

  it('opens the garage list and one garage vehicle by its ULID', () => {
    const id = '01M4X5D9R4FV02S6Q9XW30PBZK';
    expect(parseRoute('#/garage')).toEqual({ view: 'garage' });
    expect(parseRoute(`#/garage/${id}`)).toEqual({ view: 'garageVehicle', id });
    expect(routeToHash({ view: 'garage' })).toBe('#/garage');
    expect(routeToHash({ view: 'garageVehicle', id })).toBe(`#/garage/${id}`);
    expect(parseRoute('#/garage/not-a-ulid')).toEqual({ view: 'garage' });
  });

  it('tells a plate from a catalogue car', () => {
    expect(parseRef('XB-301-ZB')).toEqual({ kind: 'plate', plate: 'XB-301-ZB' });
    expect(parseRef('k19107-CV')).toEqual({ kind: 'car', carId: 19107, carType: 'CV' });
    expect(carRef(19107, 'PC')).toBe('k19107-PC');
    expect(parseRef('k4242-MC')).toEqual({ kind: 'car', carId: 4242, carType: 'MC' });
    expect(carRef(4242, 'MC')).toBe('k4242-MC');
    expect(parseRef('k4242-XX')).toBeNull();
  });
});
