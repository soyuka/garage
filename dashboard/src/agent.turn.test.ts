import { beforeEach, describe, expect, it, vi } from 'vitest';

const authFetch = vi.fn<(input: string, init?: RequestInit) => Promise<Response>>();
vi.mock('./auth', () => ({ API: '/api', authFetch: (input: string, init?: RequestInit) => authFetch(input, init) }));

const { postTurn, TurnRefusedError } = await import('./agent');

const ctx = { sessionId: 's1', selectedId: '/garage/vehicles/v1' };

function sent(): RequestInit {
  const init = authFetch.mock.calls[0]?.[1];
  if (!init) throw new Error('nothing posted');
  return init;
}

beforeEach(() => {
  authFetch.mockReset();
  authFetch.mockResolvedValue(new Response(null, { status: 202 }));
});

describe('postTurn', () => {
  it('posts JSON when nothing is attached', async () => {
    await postTurn('check the oil', ctx);

    expect(authFetch.mock.calls[0]?.[0]).toBe('/api/agent/turn');
    expect(new Headers(sent().headers).get('Content-Type')).toBe('application/json');
    expect(JSON.parse(sent().body as string)).toEqual({ instruction: 'check the oil', sessionId: 's1', selectedId: '/garage/vehicles/v1' });
  });

  it('posts multipart with files[] and lets the browser set the boundary', async () => {
    const photo = new File(['png'], 'photo.png', { type: 'image/png' });
    const bill = new File(['pdf'], 'bill.pdf', { type: 'application/pdf' });

    await postTurn('', ctx, [photo, bill]);

    const body = sent().body;
    expect(body).toBeInstanceOf(FormData);
    const form = body as FormData;
    expect(form.getAll('files[]').map((f) => (f as File).name)).toEqual(['photo.png', 'bill.pdf']);
    expect(form.get('instruction')).toBe('');
    expect(form.get('sessionId')).toBe('s1');
    expect(form.get('selectedId')).toBe('/garage/vehicles/v1');
    expect(form.has('summary')).toBe(false);
    expect(new Headers(sent().headers).has('Content-Type')).toBe(false);
  });

  it.each([
    [413, 'tooLarge'],
    [415, 'unsupported'],
    [422, 'invalid'],
  ] as const)('turns a %i into a %s refusal', async (status, kind) => {
    authFetch.mockResolvedValue(new Response(JSON.stringify({ detail: 'nope' }), { status, headers: { 'Content-Type': 'application/problem+json' } }));

    const refusal = await postTurn('x', ctx, [new File(['a'], 'a.png', { type: 'image/png' })]).catch((e: unknown) => e);

    expect(refusal).toBeInstanceOf(TurnRefusedError);
    expect((refusal as InstanceType<typeof TurnRefusedError>).kind).toBe(kind);
    expect((refusal as InstanceType<typeof TurnRefusedError>).status).toBe(status);
  });

  it('reads when to retry from a 429', async () => {
    authFetch.mockResolvedValue(new Response(null, { status: 429, headers: { 'Retry-After': '120' } }));

    const refusal = (await postTurn('x', ctx).catch((e: unknown) => e)) as InstanceType<typeof TurnRefusedError>;

    expect(refusal).toBeInstanceOf(TurnRefusedError);
    expect(refusal.kind).toBe('rateLimited');
    expect(refusal.retryAfter).toBe(120);
  });

  it('keeps any other failure a plain transport error', async () => {
    authFetch.mockResolvedValue(new Response(null, { status: 500 }));

    const error = await postTurn('x', ctx).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(Error);
    expect(error).not.toBeInstanceOf(TurnRefusedError);
  });
});
