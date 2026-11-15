import { CLOSED, fetchEventSource } from './sse';

const encoder = new TextEncoder();

function streamOf(chunks: string[], keepOpen = false): ReadableStream<Uint8Array> {
  return new ReadableStream({
    start(controller) {
      for (const c of chunks) controller.enqueue(encoder.encode(c));
      if (!keepOpen) controller.close();
    },
  });
}

const settle = () => new Promise((r) => setTimeout(r, 0));

describe('fetchEventSource', () => {
  it('sends the subscriber token as a bearer header, never in the URL', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(streamOf([], true), { status: 200 }));
    const source = fetchEventSource('https://localhost:8444/.well-known/mercure?match=t', 'a.b.c', fetch);
    await settle();

    const [url, init] = fetch.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('https://localhost:8444/.well-known/mercure?match=t');
    expect(new Headers(init.headers).get('Authorization')).toBe('Bearer a.b.c');
    expect(new Headers(init.headers).get('Accept')).toBe('text/event-stream');
    source.close();
  });

  it('delivers each event data, across chunk boundaries and CRLF, skipping comments', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(streamOf([':\n\nid: 1\ndata: {"a"', ':1}\n\nda', 'ta: line1\r\ndata: line2\r\n\r\n'], true), { status: 200 }));
    const frames: string[] = [];
    const source = fetchEventSource('u', 't', fetch);
    source.onmessage = (e) => frames.push(e.data);
    await settle();
    await settle();

    expect(frames).toEqual(['{"a":1}', 'line1\nline2']);
    expect(source.readyState).toBe(1);
    source.close();
  });

  it('closes for good and reports an error when the hub refuses the token', async () => {
    const source = fetchEventSource('u', 't', vi.fn().mockResolvedValue(new Response('Unauthorized', { status: 401 })));
    const onerror = vi.fn();
    source.onerror = onerror;
    await settle();

    expect(onerror).toHaveBeenCalledOnce();
    expect(source.readyState).toBe(CLOSED);
  });

  it('reports an error when the stream ends or the network fails', async () => {
    const ended = fetchEventSource('u', 't', vi.fn().mockResolvedValue(new Response(streamOf(['data: x\n\n']), { status: 200 })));
    const endedError = vi.fn();
    ended.onerror = endedError;
    const down = fetchEventSource('u', 't', vi.fn().mockRejectedValue(new TypeError('network')));
    const downError = vi.fn();
    down.onerror = downError;
    await settle();
    await settle();

    expect(endedError).toHaveBeenCalledOnce();
    expect(ended.readyState).toBe(CLOSED);
    expect(downError).toHaveBeenCalledOnce();
  });

  it('stays silent once closed by the page', async () => {
    let signal: AbortSignal | undefined;
    const fetch = vi.fn((_url: string, init: RequestInit) => {
      signal = init.signal ?? undefined;
      return Promise.resolve(new Response(streamOf([], true), { status: 200 }));
    });
    const source = fetchEventSource('u', 't', fetch);
    const onerror = vi.fn();
    source.onerror = onerror;
    await settle();
    source.close();
    await settle();

    expect(signal?.aborted).toBe(true);
    expect(onerror).not.toHaveBeenCalled();
    expect(source.readyState).toBe(CLOSED);
  });
});
