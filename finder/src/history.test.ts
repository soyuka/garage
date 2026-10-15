import { formatRelative, formatWhen, historyEntries, historyFor, withoutRemoved } from './history';
import { mapRows } from './journal';

const row = (id: string, startedAt: string, extra: Record<string, unknown>) => ({
  '@id': `/agent/turns/${id}`,
  instruction: `q${id}`,
  kind: 'search',
  status: 'completed',
  startedAt,
  ...extra,
});

const rows = mapRows({
  member: [
    row('1', '2026-10-15T10:00:00+02:00', { route: 'p/XB-301-ZB/c/100259', vehicle: { plate: 'XB-301-ZB', carId: 19107, carType: 'PC', make: 'MAZDA', model: '6' }, search: { rule: 'category', clusterCount: 1, outcome: 'ok' } }),
    row('2', '2026-10-15T12:00:00+02:00', { kind: 'agent', route: 'k/16182-PC/c/100198', vehicle: { plate: null, carId: 16182, carType: 'PC', make: 'Peugeot', model: '307' } }),
    row('3', '2026-10-15T11:00:00+02:00', { route: null }),
    row('4', '2026-10-15T09:00:00+02:00', { route: 'p/XB-301-ZB/c/100259' }),
    row('5', '2026-10-15T08:00:00+02:00', { route: 'javascript:alert(1)' }),
  ],
});

describe('historyEntries', () => {
  it('lists restorable searches newest first, one per route', () => {
    const list = historyEntries(rows);
    expect(list.map((e) => e.id)).toEqual(['/agent/turns/2', '/agent/turns/1']);
  });

  it('turns the server route into the page hash and names the vehicle', () => {
    const [agent, search] = historyEntries(rows);
    expect(agent).toMatchObject({ hash: '#/k/16182-PC/c/100198', input: 'q2', vehicle: { plate: null, name: 'Peugeot 307' }, outcome: { label: 'terminé' } });
    expect(search).toMatchObject({ hash: '#/p/XB-301-ZB/c/100259', vehicle: { plate: 'XB-301-ZB', name: 'Mazda 6' }, outcome: { label: '1 pièce' } });
  });

  it('shows the category label, else the typed text, never a bare category id', () => {
    const r = mapRows({
      member: [
        row('a', '2026-10-15T10:00:00+02:00', { instruction: '100259', categoryLabel: 'Filtre à huile', route: 'k/19107-PC/c/100259' }),
        row('b', '2026-10-15T09:00:00+02:00', { instruction: 'filtre à huile', route: 'k/19107-PC/c/100260' }),
        row('c', '2026-10-15T08:00:00+02:00', { instruction: '100261', route: 'k/19107-PC/c/100261' }),
      ],
    });
    expect(historyEntries(r).map((e) => e.input)).toEqual(['Filtre à huile', 'filtre à huile', '']);
  });

  it('carries every row id of a route so hiding an entry hides its duplicates', () => {
    const [agent, search] = historyEntries(rows);
    expect(agent!.ids).toEqual(['/agent/turns/2']);
    expect(search!.ids).toEqual(['/agent/turns/1', '/agent/turns/4']);
  });

  it('prints the plate in the SIV form whatever the stored casing', () => {
    const [entry] = historyEntries(mapRows({ member: [row('9', '2026-10-15T10:00:00+02:00', { route: 'p/XB301ZB', vehicle: { plate: 'XB301ZB', carId: 19107, carType: 'PC', make: 'MAZDA', model: 'MAZDA6' } })] }));
    expect(entry?.vehicle.plate).toBe('XB-301-ZB');
  });

  it('caps the list', () => {
    const many = mapRows({ member: Array.from({ length: 30 }, (_, i) => row(String(i), `2026-10-${String(i % 28 + 1).padStart(2, '0')}T10:00:00+02:00`, { route: `p/XB-301-ZB/c/${100000 + i}` })) });
    expect(historyEntries(many)).toHaveLength(10);
  });
});

describe('formatWhen', () => {
  const now = new Date('2026-10-15T18:00:00+02:00');
  it('prints the time for today and the day before that', () => {
    expect(formatWhen('2026-10-15T14:21:07+02:00', now)).toBe('14:21');
    expect(formatWhen('2026-10-12T09:05:00+02:00', now)).toBe('12 oct. 09:05');
    expect(formatWhen('nope', now)).toBe('');
  });
});

describe('formatRelative', () => {
  const now = new Date('2026-10-15T18:00:00+02:00');
  it('says how long ago, coarser as it gets older', () => {
    expect(formatRelative('2026-10-15T17:59:40+02:00', now)).toBe('à l’instant');
    expect(formatRelative('2026-10-15T17:55:00+02:00', now)).toBe('il y a 5 min');
    expect(formatRelative('2026-10-15T14:21:07+02:00', now)).toBe('il y a 3 h');
    expect(formatRelative('2026-10-14T09:05:00+02:00', now)).toBe('hier');
    expect(formatRelative('2026-10-11T09:05:00+02:00', now)).toBe('il y a 4 jours');
    expect(formatRelative('2026-09-02T09:05:00+02:00', now)).toBe('2 sept.');
    expect(formatRelative('nope', now)).toBe('');
  });
});

describe('withoutRemoved', () => {
  it('drops the entries whose id was hidden, keeping order', () => {
    const entries = historyEntries(rows);
    expect(withoutRemoved(entries, new Set())).toEqual(entries);
    expect(withoutRemoved(entries, new Set([entries[0]!.id])).map((e) => e.id)).toEqual([entries[1]!.id]);
  });
});

describe('history vehicle name', () => {
  const name = (vehicle: Record<string, unknown>) =>
    historyEntries(mapRows({ member: [row('9', '2026-10-15T10:00:00+02:00', { route: 'k/16182-PC', vehicle })] }))[0]!.vehicle.name;

  it('shows make, model and engine', () => {
    expect(name({ plate: null, carId: 16182, carType: 'PC', make: 'PEUGEOT', model: '307', engine: '1.6 HDi' })).toBe('Peugeot 307 1.6 HDi');
  });

  it('leaves the name empty when only the plate is known: the row already shows it', () => {
    expect(name({ plate: 'AB123CD', carId: 16182, carType: 'PC', make: null, model: null })).toBeNull();
  });

  it('falls back to the kType only when nothing else names the car', () => {
    expect(name({ plate: null, carId: 16182, carType: 'PC', make: null, model: null })).toBe('kType 16182');
  });
});

describe('historyFor', () => {
  it('keeps the searches made on this vehicle only', () => {
    expect(historyFor(historyEntries(rows), 'XB-301-ZB').map((e) => e.id)).toEqual(['/agent/turns/1']);
    expect(historyFor(historyEntries(rows), 'k16182-PC').map((e) => e.id)).toEqual(['/agent/turns/2']);
    expect(historyFor(historyEntries(rows), 'AB-123-CD')).toEqual([]);
  });
});
