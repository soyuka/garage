import { hubUrl, initialTurn, parseFrame, sessionTopic, turnBody, turnReducer } from './agent';
import narrowed from './fixtures/narrowed.json';
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

describe('transport helpers', () => {
  it('builds the session topic the API publishes to and the hub subscription URL', () => {
    expect(sessionTopic('https://localhost/', 'abc')).toBe('https://localhost/agent/sessions/abc');
    expect(hubUrl('/.well-known/mercure', 'https://localhost', 'https://localhost/agent/sessions/abc')).toBe(
      'https://localhost/.well-known/mercure?match=https%3A%2F%2Flocalhost%2Fagent%2Fsessions%2Fabc',
    );
  });

  it('sends the finder surface, the vehicle and the pending question context', () => {
    expect(
      turnBody({ instruction: 'Avant', sessionId: 'sess', vehicle: { carId: 19107, carType: 'PC', make: 'MAZDA', model: '6', engine: null }, pendingContext: 'essieu ?' }),
    ).toEqual({
      instruction: 'Avant',
      sessionId: 'sess',
      surface: 'finder',
      intent: 'text',
      vehicle: { carId: 19107, carType: 'PC', make: 'MAZDA', model: '6', engine: null },
      pendingContext: 'essieu ?',
    });
    expect(turnBody({ instruction: 'x', sessionId: 's', vehicle: { carId: 1, carType: 'CV', make: null, model: null, engine: null } })).not.toHaveProperty('pendingContext');
  });

  it('omits the vehicle when none is known and carries photos, intent and photo hints', () => {
    const body = turnBody({
      instruction: 'quelle pièce ?',
      sessionId: 's',
      vehicle: null,
      intent: 'photo',
      images: ['data:image/jpeg;base64,AAAA'],
      photoHints: { familyGuess: 'filtre à huile', printedReferences: ['W 712/73'], brandGuess: null },
    });
    expect(body).not.toHaveProperty('vehicle');
    expect(body).toMatchObject({ intent: 'photo', images: ['data:image/jpeg;base64,AAAA'], photoHints: { printedReferences: ['W 712/73'] } });
    expect(turnBody({ instruction: 'x', sessionId: 's', vehicle: null })).not.toHaveProperty('images');
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
