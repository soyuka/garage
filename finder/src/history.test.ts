import { formatWhen, historyEntries } from './history';
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
