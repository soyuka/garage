import { excerpt, formatCost, framesOf, getHistory, hideFromHistory, mapDetail, mapRows, outcomeOf, todayUsage } from './journal';

const agentRow = {
  '@id': '/agent/turns/01J0AGENT',
  instruction: 'filtre à huile mazda 6',
  surface: 'finder',
  kind: 'agent',
  status: 'completed',
  error: null,
  startedAt: '2026-10-15T09:00:00+00:00',
  durationMs: 21400,
  toolCallCount: 4,
  webSearchCount: 1,
  totalTokens: 15258,
  cacheReadTokens: 12000,
  cacheCreationTokens: 3000,
  estimatedCostUsd: 0.0421,
  partsApiCalls: 7,
  partsApiCallsByMethod: { getArticles: 1, getArticleCriteria: 6 },
  images: [{ mimeType: 'image/jpeg', bytes: 412000 }],
  search: null,
};

const searchRow = {
  '@id': '/agent/turns/01J0SEARCH',
  instruction: 'filtre à huile',
  surface: 'finder',
  kind: 'search',
  status: 'completed',
  startedAt: '2026-10-15T08:00:00+00:00',
  durationMs: 830,
  toolCallCount: 0,
  webSearchCount: 0,
  totalTokens: null,
  partsApiCalls: 3,
  partsApiCallsByMethod: { getArticleCriteria: 3 },
  search: { rule: 'category', carId: 19107, categoryId: 100259, clusterCount: 2, totalArticles: 220, unclassifiedCount: 12, outcome: 'ok', httpStatus: 200 },
};

describe('mapRows', () => {
  it('reads the hydra listing, agent turns and searches alike', () => {
    const rows = mapRows({ member: [agentRow, searchRow, { nope: true }] });
    expect(rows.map((r) => [r.id, r.kind])).toEqual([
      ['/agent/turns/01J0AGENT', 'agent'],
      ['/agent/turns/01J0SEARCH', 'search'],
    ]);
    expect(rows[0]).toMatchObject({ cacheReadTokens: 12000, estimatedCostUsd: 0.0421, partsApiCalls: 7, images: [{ mimeType: 'image/jpeg', bytes: 412000 }] });
    expect(rows[1]!.search).toMatchObject({ rule: 'category', clusterCount: 2, outcome: 'ok' });
    expect(rows[1]!.totalTokens).toBeNull();
  });

  it('accepts the hydra: prefixed listing too', () => {
    expect(mapRows({ 'hydra:member': [agentRow] })).toHaveLength(1);
  });
});

describe('mapDetail', () => {
  it('keeps the ordered tool calls with their arguments and results', () => {
    const d = mapDetail({
      ...agentRow,
      promptTokens: 8,
      completionTokens: 250,
      context: 'Aucun véhicule identifié…',
      toolCalls: [
        { seq: 1, name: 'show_parts', arguments: { categoryId: 100259 }, result: '{"status":"shown"}', at: '2026-10-15T09:00:10+00:00', serverSide: false },
        { seq: 0, name: 'set_vehicle', arguments: { carId: 19107 }, result: 'ok', at: '2026-10-15T09:00:05+00:00', serverSide: false },
      ],
      webSearchQueries: ['W 712/73 mazda'],
    });
    expect(d.toolCalls.map((c) => c.name)).toEqual(['set_vehicle', 'show_parts']);
    expect(d.promptTokens).toBe(8);
    expect(d.webSearchQueries).toEqual(['W 712/73 mazda']);
    expect(d.context).toBe('Aucun véhicule identifié…');
  });
});

describe('framesOf', () => {
  it('labels a shown part with the product group the server drew, and skips a group choice that drew nothing', () => {
    const calls = [
      { seq: 0, name: 'show_parts', arguments: { categoryId: 100198 }, result: '{"status":"choose_group","productGroups":[]}', at: '', serverSide: false },
      { seq: 1, name: 'show_parts', arguments: { categoryId: 100198, productGroupId: 51 }, result: '{"status":"shown","categoryLabel":"Rotule de direction intérieure, barre de connexion"}', at: '', serverSide: false },
    ];
    expect(framesOf(calls)).toEqual([{ type: 'showParts', label: 'Rotule de direction intérieure, barre de connexion' }]);
  });

  it('lists the frames the render tools emitted, in order', () => {
    const calls = [
      { seq: 0, name: 'list_part_categories', arguments: {}, result: '[]', at: '', serverSide: false },
      { seq: 1, name: 'set_vehicle', arguments: { make: 'MAZDA' }, result: 'ok', at: '', serverSide: false },
      { seq: 2, name: 'show_parts', arguments: { categoryLabel: 'Filtre à huile' }, result: '{"status":"shown"}', at: '', serverSide: false },
      { seq: 3, name: 'show_parts', arguments: { categoryLabel: 'x' }, result: '{"status":"refused"}', at: '', serverSide: false },
      { seq: 4, name: 'ask_question', arguments: { question: 'Avant ?' }, result: 'ok', at: '', serverSide: false },
    ];
    expect(framesOf(calls)).toEqual([
      { type: 'setVehicle', label: 'MAZDA' },
      { type: 'showParts', label: 'Filtre à huile' },
      { type: 'askQuestion', label: 'Avant ?' },
    ]);
  });
});

describe('todayUsage', () => {
  it('sums today’s partsapi calls per method, the unit the daily quota counts', () => {
    const rows = mapRows({ member: [agentRow, searchRow, { ...searchRow, '@id': '/agent/turns/old', startedAt: '2026-10-14T08:00:00+00:00' }] });
    const usage = todayUsage(rows, new Date('2026-10-15T12:00:00+00:00'));
    expect(usage.byMethod).toEqual({ getArticles: 1, getArticleCriteria: 9 });
    expect(usage.total).toBe(10);
    expect(usage.busiest).toEqual({ method: 'getArticleCriteria', calls: 9 });
  });

  it('has no busiest method on a quiet day', () => {
    expect(todayUsage([], new Date()).busiest).toBeNull();
  });
});

describe('formatting', () => {
  it('prints the estimated cost in French, or nothing without prices', () => {
    expect(formatCost(0.0421)).toBe('≈ 0,042 $');
    expect(formatCost(1.5)).toBe('≈ 1,50 $');
    expect(formatCost(null)).toBeNull();
  });

  it('cuts a long tool result to an excerpt and reports its size', () => {
    expect(excerpt('ok')).toEqual({ bytes: 2, text: 'ok', cut: false });
    const long = excerpt('x'.repeat(1000), 100);
    expect(long.cut).toBe(true);
    expect(long.text).toHaveLength(100);
    expect(long.bytes).toBe(1000);
  });

  it('names the outcome of every row', () => {
    const [agent, search] = mapRows({ member: [agentRow, searchRow] });
    expect(outcomeOf(agent!)).toEqual({ label: 'terminé', tone: 'ok' });
    expect(outcomeOf(search!)).toEqual({ label: '2 pièces', tone: 'ok' });
    expect(outcomeOf({ ...search!, search: { ...search!.search!, outcome: 'not_found' } })).toEqual({ label: 'introuvable', tone: 'warn' });
    expect(outcomeOf({ ...agent!, status: 'capped' })).toEqual({ label: 'plafonné', tone: 'warn' });
    expect(outcomeOf({ ...agent!, status: 'failed' })).toEqual({ label: 'échec', tone: 'alarm' });
  });
});

describe('history calls', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('reads the history view, not the journal', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ member: [searchRow] }), { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    const rows = await getHistory();
    expect(fetch.mock.calls[0]![0]).toBe('/agent/turns?surface=finder&history=1');
    expect(rows).toHaveLength(1);
  });

  it('hides one row by its iri', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetch);
    await hideFromHistory('/agent/turns/01J0SEARCH');
    expect(fetch.mock.calls[0]![0]).toBe('/agent/turns/01J0SEARCH/hide');
    expect(fetch.mock.calls[0]![1].method).toBe('POST');
  });

  it('throws when the server refuses', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status: 404 })));
    await expect(hideFromHistory('/agent/turns/x')).rejects.toThrow();
  });
});
