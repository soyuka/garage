import { hubUrl, initialTurn, parseFrame, sessionTopic, turnBody, turnReducer, turnVehicle } from './agent';
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

  it('carries the variant the agent retained, and drops a malformed selection', () => {
    const basis = 'code de fabrication (OPR 09824 ≤ 10310)';
    const f = parseFrame(JSON.stringify({ type: 'showParts', categoryId: 100198, categoryLabel: 'Rotule', rationale: basis, narrowed, selection: { clusterId: 'c1', basis } }));
    expect(f).toMatchObject({ type: 'showParts', selection: { clusterId: 'c1', basis } });
    const part = turnReducer(running(), frame(f!)).parts[0]!;
    expect(part.kind === 'catalog' && part.selection).toEqual({ clusterId: 'c1', basis });
    expect(parseFrame(JSON.stringify({ type: 'showParts', categoryId: 1, categoryLabel: 'x', rationale: '', narrowed, selection: { clusterId: 3 } }))).toMatchObject({ selection: null });
    const plain = turnReducer(running(), frame({ type: 'showParts', categoryId: 1, categoryLabel: 'x', rationale: '', narrowed, selection: null })).parts[0]!;
    expect(plain.kind === 'catalog' && plain.selection).toBeNull();
  });

  it('carries what the agent set aside with the variant it retained', () => {
    const f = parseFrame(JSON.stringify({ type: 'showParts', categoryId: 1, categoryLabel: 'x', rationale: '', narrowed, selection: { clusterId: 'c1', basis: 'b', setAside: 'OPR ≥ 10311' } }));
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

  it('sends the build code known for the car with its vehicle', () => {
    const v = { plate: 'XD-303-ZD', make: 'PEUGEOT', model: '307', modelEn: null, version: null, engineCode: null, powerHp: null, firstRegistration: null, energy: null, vin: null, carId: 16182, carType: 'PC' } as const;
    expect(turnVehicle(v, { organisationNumber: '09824', date: '2003-10-02' })).toMatchObject({ carId: 16182, buildCode: { organisationNumber: '09824' } });
    expect(turnVehicle(v, null)).not.toHaveProperty('buildCode');
  });

  it('carries the on-screen result, the plate and a build label on a follow-up', () => {
    const body = turnBody({
      instruction: 'Code relevé sur la vignette : 10345',
      sessionId: 's',
      vehicle: { carId: 16182, carType: 'PC', make: 'PEUGEOT', model: '307', engine: null, plate: 'AB-123-CD' },
      pendingContext: 'code de fabrication ?',
      summary: 'Rotule axiale (categoryId 100198)',
      buildLabel: { opr: '10345', printedCodes: ['DAM 10345'] },
    });
    expect(body).toMatchObject({
      vehicle: { plate: 'AB-123-CD' },
      pendingContext: 'code de fabrication ?',
      summary: 'Rotule axiale (categoryId 100198)',
      buildLabel: { opr: '10345', printedCodes: ['DAM 10345'] },
    });
    expect(turnBody({ instruction: 'x', sessionId: 's', vehicle: null, summary: '' })).not.toHaveProperty('summary');
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
