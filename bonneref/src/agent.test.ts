import { fetchSessionLink, hubUrl, openSessionStream, type StreamSource, initialTurn, parseFrame, postTurn, SILENCE_MS, SILENT, silent, TURN_FAILED, turnBody, TurnRequestError, turnReducer, turnVehicle } from './agent';
import narrowed from '../test-fixtures/narrowed.json';
import type { FinderFrame, TurnState } from './types';

const frame = (f: FinderFrame) => ({ type: 'frame' as const, frame: f });
const running = (): TurnState => turnReducer(turnReducer(initialTurn, { type: 'start', instruction: 'filtre à huile' }), frame({ type: 'turn', phase: 'start' }));

describe('parseFrame', () => {
  it('reads a showParts frame', () => {
    const f = parseFrame(JSON.stringify({ type: 'showParts', categoryId: 100259, categoryLabel: 'Filtre à huile', rationale: 'r', narrowed }));
    expect(f).toMatchObject({ type: 'showParts', categoryId: 100259, categoryLabel: 'Filtre à huile', rationale: 'r' });
  });

  it('drops keep-alives, unknown types and malformed frames', () => {
    expect(parseFrame(':')).toBeNull();
    expect(parseFrame(JSON.stringify({ type: 'selectVehicle', id: 'x' }))).toBeNull();
    expect(parseFrame(JSON.stringify({ type: 'showParts', categoryLabel: 'x' }))).toBeNull();
    expect(parseFrame(JSON.stringify({ type: 'askQuestion', question: 'q', options: 'nope', context: '' }))).toBeNull();
    expect(parseFrame(JSON.stringify({ type: 'showWebPart', categoryLabel: 'x', reference: 'R', brand: 'B', sourceUrl: 'javascript:alert(1)', sourceTitle: 't', rationale: '' }))).toBeNull();
  });

  it('reads the failed outcome of a turn end, and only that one', () => {
    expect(parseFrame(JSON.stringify({ type: 'turn', phase: 'end', outcome: 'failed' }))).toEqual({ type: 'turn', phase: 'end', outcome: 'failed' });
    expect(parseFrame(JSON.stringify({ type: 'turn', phase: 'end', outcome: 'whatever' }))).toEqual({ type: 'turn', phase: 'end' });
    expect(parseFrame(JSON.stringify({ type: 'turn', phase: 'end' }))).toEqual({ type: 'turn', phase: 'end' });
  });

  it('keeps only well-formed question options', () => {
    const f = parseFrame(JSON.stringify({ type: 'askQuestion', question: 'Avant ou arrière ?', options: [{ label: 'Avant', value: 'avant' }, { label: 3 }], context: 'c' }));
    expect(f).toEqual({ type: 'askQuestion', question: 'Avant ou arrière ?', options: [{ label: 'Avant', value: 'avant' }], context: 'c' });
  });
});

describe('turnReducer', () => {
  it('starts clean and runs on the turn start frame', () => {
    const s = running();
    expect(s.phase).toBe('running');
    expect(s.instruction).toBe('filtre à huile');
    expect(s.parts).toEqual([]);
  });

  it('shows the agent status line while working', () => {
    expect(turnReducer(running(), frame({ type: 'status', message: 'J’affine les références…' })).status).toBe('J’affine les références…');
  });

  it('maps showParts through the same mapper as GET /narrowed, one entry per category', () => {
    const shown = frame({ type: 'showParts', categoryId: 100259, categoryLabel: 'Filtre à huile', rationale: 'Change à chaque vidange.', narrowed });
    const s = turnReducer(turnReducer(running(), shown), shown);
    expect(s.parts).toHaveLength(1);
    const part = s.parts[0]!;
    expect(part.kind).toBe('catalog');
    if (part.kind !== 'catalog') return;
    expect(part.label).toBe('Filtre à huile');
    expect(part.narrowing.totalArticles).toBe(220);
    expect(part.narrowing.clusters[0]?.representative.brand).toBe('MANN-FILTER');
  });

  it('carries the variant the agent retained under the id the server stored, and drops a malformed selection', () => {
    const basis = 'code de fabrication (OPR 09824 ≤ 10310)';
    const id = '01JA5Z3M8Q6W4XK7T2N9R0BCDE';
    const f = parseFrame(JSON.stringify({ type: 'showParts', categoryId: 100198, categoryLabel: 'Rotule', rationale: basis, narrowed, selection: { id, clusterId: 'c1', basis } }));
    expect(f).toMatchObject({ type: 'showParts', selection: { id, clusterId: 'c1', basis, setAside: null } });
    const part = turnReducer(running(), frame(f!)).parts[0]!;
    expect(part.kind === 'catalog' && part.selection).toEqual({ id, clusterId: 'c1', basis, setAside: null });
    expect(parseFrame(JSON.stringify({ type: 'showParts', categoryId: 1, categoryLabel: 'x', rationale: '', narrowed, selection: { clusterId: 3 } }))).toMatchObject({ selection: null });
    expect(parseFrame(JSON.stringify({ type: 'showParts', categoryId: 1, categoryLabel: 'x', rationale: '', narrowed, selection: { clusterId: 'c1', basis } }))).toMatchObject({ selection: null });
    const plain = turnReducer(running(), frame({ type: 'showParts', categoryId: 1, categoryLabel: 'x', rationale: '', narrowed, selection: null })).parts[0]!;
    expect(plain.kind === 'catalog' && plain.selection).toBeNull();
  });

  it('carries what the agent set aside with the variant it retained', () => {
    const f = parseFrame(JSON.stringify({ type: 'showParts', categoryId: 1, categoryLabel: 'x', rationale: '', narrowed, selection: { id: '01JA5Z3M8Q6W4XK7T2N9R0BCDE', clusterId: 'c1', basis: 'b', setAside: 'OPR ≥ 10311' } }));
    expect(f).toMatchObject({ selection: { clusterId: 'c1', basis: 'b', setAside: 'OPR ≥ 10311' } });
  });

  it('pins the decoded build code on the turn, a malformed one is dropped', () => {
    const f = parseFrame(JSON.stringify({ type: 'setBuildCode', organisationNumber: '09824', date: '2003-10-02', codeKind: 'dam' }));
    expect(f).toEqual({ type: 'setBuildCode', organisationNumber: '09824', date: '2003-10-02' });
    expect(turnReducer(running(), frame(f!)).buildCode).toEqual({ organisationNumber: '09824', date: '2003-10-02' });
    expect(parseFrame(JSON.stringify({ type: 'setBuildCode', organisationNumber: '9', date: '2003-10-02' }))).toBeNull();
    expect(parseFrame(JSON.stringify({ type: 'setBuildCode', organisationNumber: '09824', date: 'oct' }))).toBeNull();
    expect(initialTurn.buildCode).toBeNull();
  });

  it('keeps several parts in arrival order, web finds included', () => {
    let s = turnReducer(running(), frame({ type: 'showParts', categoryId: 1, categoryLabel: 'Courroie de distribution', rationale: '', narrowed }));
    s = turnReducer(s, frame({ type: 'showWebPart', categoryLabel: 'Pompe à eau', reference: 'PA1234', brand: 'GRAF', sourceUrl: 'https://example.org/pa', sourceTitle: 'Graf', rationale: 'r' }));
    expect(s.parts.map((p) => [p.kind, p.label])).toEqual([
      ['catalog', 'Courroie de distribution'],
      ['web', 'Pompe à eau'],
    ]);
  });

  it('holds the question and settles on turn end', () => {
    let s = turnReducer(running(), frame({ type: 'askQuestion', question: 'Avant ou arrière ?', options: [{ label: 'Avant', value: 'avant' }, { label: 'Arrière', value: 'arriere' }], context: 'essieu ?' }));
    s = turnReducer(s, frame({ type: 'turn', phase: 'end' }));
    expect(s.phase).toBe('done');
    expect(s.status).toBeNull();
    expect(s.ask?.context).toBe('essieu ?');
    expect(s.notice).toBeNull();
  });

  it('says so plainly when the turn ends with nothing to show', () => {
    const s = turnReducer(running(), frame({ type: 'turn', phase: 'end' }));
    expect(s.phase).toBe('done');
    expect(s.notice).toMatch(/Aucune pièce/);
  });

  it('surfaces a server toast as the notice', () => {
    let s = turnReducer(running(), frame({ type: 'toast', tone: 'warn', message: 'Je n’ai pas pu terminer cette demande.' }));
    s = turnReducer(s, frame({ type: 'turn', phase: 'end' }));
    expect(s.notice).toBe('Je n’ai pas pu terminer cette demande.');
  });

  it('accepts frames that beat the POST acknowledgement', () => {
    const sending = turnReducer(initialTurn, { type: 'start', instruction: 'x' });
    expect(turnReducer(sending, frame({ type: 'status', message: 'Je cherche…' })).status).toBe('Je cherche…');
  });

  it('ignores late frames once the turn was cancelled or failed', () => {
    const cancelled = turnReducer(running(), { type: 'cancel' });
    expect(cancelled.phase).toBe('done');
    expect(cancelled.notice).toBe('Demande interrompue.');
    expect(turnReducer(cancelled, frame({ type: 'showParts', categoryId: 1, categoryLabel: 'x', rationale: '', narrowed }))).toBe(cancelled);

    const failed = turnReducer(running(), { type: 'fail', message: 'Le serveur ne répond pas.' });
    expect(failed.phase).toBe('failed');
    expect(turnReducer(failed, frame({ type: 'turn', phase: 'end' }))).toBe(failed);
  });
});

describe('turn failure', () => {
  const failedEnd = frame({ type: 'turn', phase: 'end', outcome: 'failed' });

  it('leaves the busy state as failed on a failed turn end', () => {
    const s = turnReducer(running(), failedEnd);
    expect(s.phase).toBe('failed');
    expect(s.status).toBeNull();
    expect(s.notice).toBe(TURN_FAILED);
  });

  it('fails a turn whose end beat the POST acknowledgement', () => {
    expect(turnReducer(turnReducer(initialTurn, { type: 'start', instruction: 'x' }), failedEnd).phase).toBe('failed');
  });

  it('ends a failed frame and a silence timeout in the same state', () => {
    const s = turnReducer(running(), frame({ type: 'status', message: 'Je cherche…' }));
    expect(turnReducer(s, failedEnd)).toEqual({ ...turnReducer(s, { type: 'fail', message: SILENT }), notice: TURN_FAILED });
  });

  it('times out on frame inactivity, not on total duration', () => {
    expect(silent(0, SILENCE_MS)).toBe(false);
    expect(silent(0, SILENCE_MS + 1)).toBe(true);
    expect(silent(10 * SILENCE_MS, 10 * SILENCE_MS + 1_000)).toBe(false);
  });
});

describe('follow-up turns', () => {
  const withParts = (): TurnState => {
    const shown = turnReducer(running(), frame({ type: 'showParts', categoryId: 100259, categoryLabel: 'Filtre à huile', rationale: '', narrowed }));
    return turnReducer(turnReducer(shown, frame({ type: 'askQuestion', question: 'Code ?', options: [], context: 'code de fabrication ?' })), frame({ type: 'turn', phase: 'end' }));
  };

  it('keeps the parts on screen while a follow-up runs, then replaces them on the first new part', () => {
    const next = turnReducer(withParts(), { type: 'start', instruction: '10345', continued: true });
    expect(next.phase).toBe('sending');
    expect(next.parts).toHaveLength(1);
    expect(next.stale).toBe(true);
    expect(next.ask).toBeNull();
    const replaced = turnReducer(turnReducer(next, frame({ type: 'turn', phase: 'start' })), frame({ type: 'showParts', categoryId: 100198, categoryLabel: 'Rotule axiale', rationale: '', narrowed }));
    expect(replaced.parts.map((p) => p.key)).toEqual(['c100198']);
    expect(replaced.stale).toBe(false);
  });

  it('keeps the previous parts when the follow-up shows none, and says nothing was found only for a fresh turn', () => {
    const next = turnReducer(turnReducer(withParts(), { type: 'start', instruction: 'merci', continued: true }), frame({ type: 'turn', phase: 'end' }));
    expect(next.parts).toHaveLength(1);
    expect(next.stale).toBe(false);
    expect(next.notice).toBeNull();
  });

  it('starts clean when not continued', () => {
    expect(turnReducer(withParts(), { type: 'start', instruction: 'x' }).parts).toEqual([]);
  });
});

describe('follow-up from a deterministic result', () => {
  const mazda = { carId: 19107, carType: 'PC' as const };
  const held = (): TurnState => turnReducer(turnReducer(initialTurn, { type: 'start', instruction: 'et le filtre à air ?', held: mazda }), frame({ type: 'turn', phase: 'start' }));

  it('holds the on-screen result while the turn runs', () => {
    expect(held().held).toEqual(mazda);
    expect(held().parts).toEqual([]);
  });

  it('releases on the first part the agent shows', () => {
    expect(turnReducer(held(), frame({ type: 'showParts', categoryId: 100198, categoryLabel: 'Rotule axiale', rationale: '', narrowed })).held).toBeNull();
    expect(turnReducer(held(), frame({ type: 'showWebPart', categoryLabel: 'Pompe', reference: 'P1', brand: 'GRAF', sourceUrl: 'https://example.org', sourceTitle: '', rationale: '' })).held).toBeNull();
  });

  it('releases on another vehicle, not on the one already on screen', () => {
    const set = (carId: number) => frame({ type: 'setVehicle', carId, carType: 'PC', make: null, model: null, engine: null, plate: null });
    expect(turnReducer(held(), set(19107)).held).toEqual(mazda);
    expect(turnReducer(held(), set(16182)).held).toBeNull();
  });

  it('keeps the result when the turn ends with only a question, or with nothing', () => {
    const asked = turnReducer(held(), frame({ type: 'askQuestion', question: 'Avant ou arrière ?', options: [], context: 'essieu ?' }));
    expect(turnReducer(asked, frame({ type: 'turn', phase: 'end' })).held).toEqual(mazda);
    expect(turnReducer(held(), frame({ type: 'turn', phase: 'end' })).held).toEqual(mazda);
  });

  it('holds nothing for a fresh turn', () => {
    expect(turnReducer(initialTurn, { type: 'start', instruction: 'x' }).held).toBeNull();
  });
});

describe('turn state', () => {
  it('carries no replayed or synthetic marker: every turn is a live one', () => {
    expect(Object.keys(turnReducer(initialTurn, { type: 'start', instruction: 'rotule' }))).not.toContain('synthetic');
  });
});

describe('transport helpers', () => {
  it('builds the hub subscription URL, which never carries the token', () => {
    expect(hubUrl('/.well-known/mercure', 'https://localhost', 'https://localhost/agent/sessions/abc')).toBe(
      'https://localhost/.well-known/mercure?match=https%3A%2F%2Flocalhost%2Fagent%2Fsessions%2Fabc',
    );
    expect(new URL(hubUrl('https://localhost:8444/.well-known/mercure', 'http://localhost:5173', 'https://localhost:8444/agent/sessions/abc')).origin).toBe('https://localhost:8444');
  });

  it('sends the finder surface and the vehicle ids, never the catalogue names', () => {
    const v = { plate: 'XD-303-ZD', make: 'PEUGEOT', model: '307', modelEn: null, version: null, engineCode: 'RFN', powerHp: null, firstRegistration: null, energy: null, vin: null, carId: 16182, carType: 'PC' } as const;
    expect(turnBody({ instruction: 'Avant', sessionId: 'sess', vehicle: turnVehicle(v) })).toEqual({
      instruction: 'Avant',
      sessionId: 'sess',
      surface: 'finder',
      intent: 'text',
      vehicle: { carId: 16182, carType: 'PC', plate: 'XD-303-ZD' },
    });
  });

  it('sends the build code known for the car with its vehicle', () => {
    const v = { plate: 'XD-303-ZD', make: 'PEUGEOT', model: '307', modelEn: null, version: null, engineCode: null, powerHp: null, firstRegistration: null, energy: null, vin: null, carId: 16182, carType: 'PC' } as const;
    expect(turnVehicle(v, { organisationNumber: '09824', date: '2003-10-02' })).toMatchObject({ carId: 16182, buildCode: { organisationNumber: '09824' } });
    expect(turnVehicle(v, null)).not.toHaveProperty('buildCode');
  });

  it('carries the previous turn, the on-screen result and the intake id on a follow-up', () => {
    const onScreen = { categoryId: 100198, productGroupId: 7, clusterId: 'c1', chosenBy: 'agent' as const, selectionId: '01JA5Z3M8Q6W4XK7T2N9R0BCDE' };
    const body = turnBody({ instruction: 'x', sessionId: 's', vehicle: { carId: 16182, carType: 'PC' }, previousTurnId: 'T1', onScreen, intakeId: 'I1' });
    expect(body).toMatchObject({ previousTurnId: 'T1', onScreen, intakeId: 'I1' });
    const bare = turnBody({ instruction: 'x', sessionId: 's', vehicle: null, previousTurnId: null, onScreen: null, intakeId: null });
    for (const key of ['previousTurnId', 'onScreen', 'intakeId', 'summary', 'pendingContext', 'photoHints', 'buildLabel']) expect(bare).not.toHaveProperty(key);
  });

  it('omits the vehicle when none is known and carries photos and intent', () => {
    const body = turnBody({ instruction: 'quelle pièce ?', sessionId: 's', vehicle: null, intent: 'photo', images: ['data:image/jpeg;base64,AAAA'] });
    expect(body).not.toHaveProperty('vehicle');
    expect(body).toMatchObject({ intent: 'photo', images: ['data:image/jpeg;base64,AAAA'] });
    expect(turnBody({ instruction: 'x', sessionId: 's', vehicle: null })).not.toHaveProperty('images');
  });
});

describe('fetchSessionLink', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('takes the owner-bound topic and its subscriber token from the server, never builds them', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ sessionTopic: 'https://localhost/agent/sessions/9f86d081', subscriberToken: 'a.b.c', expiresAt: 1_800_000_000 }), { status: 200 }));
    vi.stubGlobal('fetch', fetch);

    expect(await fetchSessionLink('sess-1')).toEqual({ topic: 'https://localhost/agent/sessions/9f86d081', token: 'a.b.c', expiresAt: 1_800_000_000_000 });
    expect(String(fetch.mock.calls[0]?.[0])).toContain('/agent/link?session=sess-1');
  });

  it('has no link when the server refuses or answers garbage', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(null, { status: 401 })));
    expect(await fetchSessionLink('sess-1')).toBeNull();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ sessionTopic: 3 }), { status: 200 })));
    expect(await fetchSessionLink('sess-1')).toBeNull();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ sessionTopic: 'https://x/agent/sessions/1', expiresAt: 1 }), { status: 200 })));
    expect(await fetchSessionLink('sess-1')).toBeNull();
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('down')));
    expect(await fetchSessionLink('sess-1')).toBeNull();
  });
});

describe('openSessionStream', () => {
  class FakeSource implements StreamSource {
    readyState = 0;
    closed = false;
    onmessage: ((e: MessageEvent<string>) => void) | null = null;
    onerror: (() => void) | null = null;
    constructor(
      public readonly url: string,
      public readonly token: string,
    ) {}
    close(): void {
      this.closed = true;
      this.readyState = 2;
    }
    fail(): void {
      this.readyState = 2;
      this.onerror?.();
    }
  }

  const HOUR = 3_600_000;
  let sources: FakeSource[];
  let links: number;
  const start = (link: () => Promise<{ topic: string; token: string; expiresAt: number } | null>) =>
    openSessionStream({
      link,
      connect: (url, token) => {
        const s = new FakeSource(url, token);
        sources.push(s);
        return s;
      },
      hub: 'https://localhost:8444/.well-known/mercure',
      origin: 'http://localhost:5173',
      onFrame: () => undefined,
    });
  const fresh = () => {
    links += 1;
    return Promise.resolve({ topic: 'https://localhost:8444/agent/sessions/t', token: `tok${links}`, expiresAt: Date.now() + HOUR });
  };

  beforeEach(() => {
    vi.useFakeTimers();
    sources = [];
    links = 0;
  });
  afterEach(() => vi.useRealTimers());

  it('subscribes with the token the link handed out', async () => {
    const stream = start(fresh);
    expect(await stream.ready).toBe(true);
    expect(sources[0]!.token).toBe('tok1');
    stream.close();
    expect(sources[0]!.closed).toBe(true);
  });

  it('fetches a new token and reconnects before the current one expires', async () => {
    const stream = start(fresh);
    await stream.ready;
    await vi.advanceTimersByTimeAsync(HOUR - 30_000);

    expect(sources).toHaveLength(2);
    expect(sources[1]!.token).toBe('tok2');
    expect(sources[0]!.closed).toBe(true);
    expect(sources[1]!.closed).toBe(false);
    stream.close();
  });

  it('refetches the link and reconnects when the hub drops the stream', async () => {
    const stream = start(fresh);
    await stream.ready;
    sources[0]!.fail();
    await vi.advanceTimersByTimeAsync(5_000);

    expect(sources).toHaveLength(2);
    expect(sources[1]!.token).toBe('tok2');
    stream.close();
  });

  it('leaves a reconnecting stream to the browser while it is only retrying', async () => {
    const stream = start(fresh);
    await stream.ready;
    sources[0]!.readyState = 0;
    sources[0]!.onerror?.();
    await vi.advanceTimersByTimeAsync(5_000);

    expect(sources).toHaveLength(1);
    stream.close();
  });

  it('keeps retrying the link after a failure, and stops once closed', async () => {
    let answers = 0;
    const stream = start(() => {
      answers += 1;
      return answers === 2 ? Promise.resolve(null) : fresh();
    });
    await stream.ready;
    sources[0]!.fail();
    await vi.advanceTimersByTimeAsync(60_000);
    expect(sources).toHaveLength(2);

    stream.close();
    sources[1]!.readyState = 2;
    await vi.advanceTimersByTimeAsync(10 * HOUR);
    expect(sources).toHaveLength(2);
  });

  it('is not ready when the first link fails', async () => {
    const stream = start(() => Promise.resolve(null));
    expect(await stream.ready).toBe(false);
    expect(sources).toHaveLength(0);
    stream.close();
  });
});

describe('postTurn', () => {
  const body = turnBody({ instruction: 'x', sessionId: 's', vehicle: null });
  const answer = (status: number, payload?: string) => vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(payload ?? null, { status })));
  const failure = async () => postTurn(body).catch((e: unknown) => e as TurnRequestError);
  afterEach(() => vi.unstubAllGlobals());

  it('returns the turn id of the 202', async () => {
    answer(202, JSON.stringify({ turnId: '01JA5Z3M8Q6W4XK7T2N9R0BCDE' }));
    expect(await postTurn(body)).toBe('01JA5Z3M8Q6W4XK7T2N9R0BCDE');
  });

  it('tolerates an empty or malformed body', async () => {
    answer(202);
    expect(await postTurn(body)).toBeNull();
    answer(202, 'nope');
    expect(await postTurn(body)).toBeNull();
    answer(202, JSON.stringify({ turnId: 3 }));
    expect(await postTurn(body)).toBeNull();
  });

  it('maps the limit, the refusal, auth and server errors to their kind', async () => {
    answer(429);
    expect(await failure()).toMatchObject({ kind: 'limit', message: 'Limite de recherches atteinte. Réessayez plus tard.' });
    answer(422);
    expect(await failure()).toMatchObject({ kind: 'invalid', message: 'Demande refusée par le serveur. Reformulez ou choisissez une catégorie.' });
    answer(400);
    expect(await failure()).toMatchObject({ kind: 'invalid' });
    answer(401);
    expect(await failure()).toMatchObject({ kind: 'auth' });
    answer(500);
    expect(await failure()).toMatchObject({ kind: 'server' });
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('down')));
    expect(await failure()).toMatchObject({ kind: 'network' });
  });
});

describe('turn id', () => {
  it('starts null, is set once the server accepted the turn, and resets on a new turn', () => {
    expect(initialTurn.turnId).toBeNull();
    const accepted = turnReducer(turnReducer(initialTurn, { type: 'start', instruction: 'x' }), { type: 'accepted', turnId: 'T1' });
    expect(accepted.turnId).toBe('T1');
    expect(turnReducer(accepted, { type: 'start', instruction: 'y', continued: true }).turnId).toBeNull();
  });

  it('survives the frames of its turn and ignores an acceptance once reset', () => {
    const accepted = turnReducer(running(), { type: 'accepted', turnId: 'T1' });
    expect(turnReducer(accepted, frame({ type: 'turn', phase: 'end' })).turnId).toBe('T1');
    expect(turnReducer(initialTurn, { type: 'accepted', turnId: 'T1' }).turnId).toBeNull();
  });
});

describe('setVehicle', () => {
  const set = { type: 'setVehicle', carId: 19107, carType: 'PC', make: 'MAZDA', model: '6', engine: '2.0 MZR', plate: null };

  it('parses the vehicle the agent identified', () => {
    expect(parseFrame(JSON.stringify(set))).toEqual(set);
    expect(parseFrame(JSON.stringify({ ...set, plate: 'xb-301-zb' }))).toMatchObject({ plate: 'XB-301-ZB' });
    expect(parseFrame(JSON.stringify({ ...set, carId: 0 }))).toBeNull();
    expect(parseFrame(JSON.stringify({ ...set, carType: 'XX' }))).toBeNull();
  });

  it('keeps it on the turn so the page can adopt it', () => {
    const s = turnReducer(running(), frame(parseFrame(JSON.stringify(set))!));
    expect(s.vehicle).toEqual({ carId: 19107, carType: 'PC', make: 'MAZDA', model: '6', engine: '2.0 MZR', plate: null });
    expect(turnReducer(s, { type: 'start', instruction: 'y' }).vehicle).toBeNull();
  });
});
