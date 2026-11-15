// A Server-Sent Events reader over fetch, because the hub only takes a Mercure 1.0 subscriber token in the
// Authorization header (or a cookie): it refuses one in the `authorization` query parameter, and a native
// EventSource can send neither a header nor, to a hub on another origin, a cookie set by the API.
// The surface mirrors the EventSource subset the session stream uses; it never reconnects on its own, the
// caller re-links (agent.ts openSessionStream), which also renews the token.

export const OPEN = 1;
export const CLOSED = 2;

export interface StreamSource {
  readonly readyState: number;
  onmessage: ((e: MessageEvent<string>) => void) | null;
  onerror: ((e: Event) => void) | null;
  close(): void;
}

type Fetch = (url: string, init: RequestInit) => Promise<Response>;

export function fetchEventSource(url: string, token: string, fetchImpl: Fetch = (u, i) => fetch(u, i)): StreamSource {
  const abort = new AbortController();
  let state = 0;

  const source: StreamSource = {
    get readyState() {
      return state;
    },
    onmessage: null,
    onerror: null,
    close() {
      state = CLOSED;
      abort.abort();
    },
  };

  const fail = (): void => {
    if (state === CLOSED) return;
    state = CLOSED;
    source.onerror?.(new Event('error'));
  };

  const dispatch = (block: string): void => {
    const data: string[] = [];
    for (const line of block.split('\n')) {
      if (line === 'data') data.push('');
      else if (line.startsWith('data:')) data.push(line.slice(line.startsWith('data: ') ? 6 : 5));
    }
    if (data.length > 0 && state === OPEN) source.onmessage?.(new MessageEvent('message', { data: data.join('\n') }));
  };

  void (async () => {
    try {
      const res = await fetchImpl(url, { headers: { Accept: 'text/event-stream', Authorization: `Bearer ${token}` }, signal: abort.signal, cache: 'no-store' });
      if (!res.ok || res.body === null) return fail();
      if (state === CLOSED) return;
      state = OPEN;
      const reader = res.body.pipeThrough(new TextDecoderStream()).getReader();
      let buffer = '';
      for (;;) {
        const { done, value } = await reader.read();
        if (done) return fail();
        buffer = (buffer + value).replace(/\r\n?/g, '\n');
        let end = buffer.indexOf('\n\n');
        while (end !== -1) {
          dispatch(buffer.slice(0, end));
          buffer = buffer.slice(end + 2);
          end = buffer.indexOf('\n\n');
        }
      }
    } catch {
      fail();
    }
  })();

  return source;
}
