import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  TOKEN_ENDPOINT,
  apiOrigin,
  base64urlEncode,
  bearerAllowed,
  createTokenStore,
  decodeJwtClaims,
  endSessionUrl,
  isTokenFresh,
  pkceChallenge,
  refreshDueIn,
  sanitizeReturnHash,
  singleFlight,
  tokenExpiryMs,
  tokenSetFromResponse,
  validateCallback,
} from './auth';

describe('base64urlEncode', () => {
  it('uses url-safe chars and strips padding', () => {
    // 0xfb 0xff 0xbf → base64 "+/+/" → base64url "-_-_" (exercises both + → - and / → _).
    expect(base64urlEncode(new Uint8Array([0xfb, 0xff, 0xbf]))).toBe('-_-_');
    // 0xff 0xff 0xff → "////" → "____".
    expect(base64urlEncode(new Uint8Array([0xff, 0xff, 0xff]))).toBe('____');
    // single byte → "AA==" with padding stripped → "AA".
    expect(base64urlEncode(new Uint8Array([0x00]))).toBe('AA');
  });
});

describe('pkceChallenge', () => {
  it('matches the RFC 7636 Appendix B test vector (S256)', async () => {
    const verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    const challenge = await pkceChallenge(verifier);
    expect(challenge).toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
  });
});

describe('TOKEN_ENDPOINT', () => {
  it('posts straight to the https issuer', () => {
    expect(TOKEN_ENDPOINT).toBe('https://auth.bonneref.fr/api/login/oauth/access_token');
  });
});

describe('isTokenFresh', () => {
  const tok = (expiresAt: number) => ({ accessToken: 'x', expiresAt });

  it('is false for a null token', () => {
    expect(isTokenFresh(null, 1_000)).toBe(false);
  });

  it('treats a token expiring inside the 30s skew as stale', () => {
    // expires 20s out, now=0, skew 30s → 20_000 - 30_000 < 0 → stale.
    expect(isTokenFresh(tok(20_000), 0)).toBe(false);
  });

  it('treats a token expiring beyond the skew as fresh', () => {
    expect(isTokenFresh(tok(40_000), 0)).toBe(true);
  });
});

describe('validateCallback', () => {
  it('accepts a matching state and returns the code + verifier', () => {
    const r = validateCallback({ code: 'abc', state: 's1' }, { verifier: 'v1', expectedState: 's1' });
    expect(r).toEqual({ ok: true, code: 'abc', verifier: 'v1' });
  });

  it('rejects a state mismatch (CSRF guard)', () => {
    const r = validateCallback({ code: 'abc', state: 'evil' }, { verifier: 'v1', expectedState: 's1' });
    expect(r.ok).toBe(false);
  });

  it('rejects a missing code', () => {
    expect(validateCallback({ code: null, state: 's1' }, { verifier: 'v1', expectedState: 's1' }).ok).toBe(false);
  });

  it('rejects a missing verifier (no login in flight)', () => {
    expect(validateCallback({ code: 'abc', state: 's1' }, { verifier: null, expectedState: 's1' }).ok).toBe(false);
  });
});

describe('decodeJwtClaims', () => {
  it('decodes a base64url JWT payload', () => {
    const payload = base64urlEncode(new TextEncoder().encode(JSON.stringify({ name: 'Antoine', email: 'a@b.co', sub: 'u-1' })));
    const claims = decodeJwtClaims(`h.${payload}.sig`);
    expect(claims).toMatchObject({ name: 'Antoine', email: 'a@b.co', sub: 'u-1' });
  });

  it('decodes UTF-8 multibyte claims without mojibake (accented French identity)', () => {
    const payload = base64urlEncode(new TextEncoder().encode(JSON.stringify({ name: 'Désiré Tüür', email: 'aé@b.co' })));
    const claims = decodeJwtClaims(`h.${payload}.sig`);
    expect(claims.name).toBe('Désiré Tüür');
    expect(claims.email).toBe('aé@b.co');
  });

  it('returns {} for a malformed token', () => {
    expect(decodeJwtClaims('not-a-jwt')).toEqual({});
  });

  it('returns {} for an empty payload segment', () => {
    expect(decodeJwtClaims('h..sig')).toEqual({});
  });

  it('returns {} when the payload is valid base64 but not JSON', () => {
    const payload = base64urlEncode(new TextEncoder().encode('plain text, not json'));
    expect(decodeJwtClaims(`h.${payload}.sig`)).toEqual({});
  });
});

describe('tokenExpiryMs', () => {
  it('adds expires_in seconds to now', () => {
    expect(tokenExpiryMs(7200, 0)).toBe(7_200_000);
  });

  it('defaults to 3600s when expires_in is missing', () => {
    expect(tokenExpiryMs(undefined, 1_000)).toBe(1_000 + 3_600_000);
  });

  it('defaults to 3600s for a non-positive expires_in (no dead-on-arrival session)', () => {
    expect(tokenExpiryMs(0, 0)).toBe(3_600_000);
    expect(tokenExpiryMs(-5, 0)).toBe(3_600_000);
  });
});

describe('apiOrigin', () => {
  it('reads the origin of an absolute API base', () => {
    expect(apiOrigin('https://localhost', 'https://garage.example')).toBe('https://localhost');
    expect(apiOrigin('https://api.example:8443/base/', 'https://garage.example')).toBe('https://api.example:8443');
  });

  it('falls back to the page origin when the base is empty or relative', () => {
    expect(apiOrigin('', 'https://localhost')).toBe('https://localhost');
    expect(apiOrigin('/api', 'https://localhost')).toBe('https://localhost');
  });
});

describe('bearerAllowed', () => {
  const page = 'https://localhost';
  const api = 'https://localhost';

  it('allows a same-origin API path and an absolute URL on the API origin', () => {
    expect(bearerAllowed('/garage/vehicles', page, api)).toBe(true);
    expect(bearerAllowed('https://localhost/garage/vehicles/01V', page, api)).toBe(true);
  });

  it('refuses a model-supplied suffix that turns the base into another host', () => {
    expect(bearerAllowed('https://localhost.evil.tld/x', page, api)).toBe(false);
    expect(bearerAllowed('https://localhost@evil.tld/x', page, api)).toBe(false);
  });

  it('refuses a scheme-relative URL', () => {
    expect(bearerAllowed('//evil.tld/x', page, api)).toBe(false);
  });

  it('refuses any other absolute origin, scheme or port', () => {
    expect(bearerAllowed('https://evil.tld/garage/vehicles', page, api)).toBe(false);
    expect(bearerAllowed('http://localhost/garage/vehicles', page, api)).toBe(false);
    expect(bearerAllowed('https://localhost:8443/garage/vehicles', page, api)).toBe(false);
    expect(bearerAllowed('javascript:alert(1)', page, api)).toBe(false);
  });

  it('refuses an unparseable URL', () => {
    expect(bearerAllowed('http://[::1', page, api)).toBe(false);
  });
});

const memoryStorage = (seed: Record<string, string> = {}): Storage => {
  const map = new Map<string, string>(Object.entries(seed));
  return {
    getItem: (k: string) => map.get(k) ?? null,
    setItem: (k: string, v: string) => void map.set(k, String(v)),
    removeItem: (k: string) => void map.delete(k),
    clear: () => map.clear(),
    key: (i: number) => [...map.keys()][i] ?? null,
    get length() {
      return map.size;
    },
  } as Storage;
};

const tokenResponse = (body: Record<string, unknown>, status = 200) => ({ ok: status < 400, status, json: async () => body }) as Response;

describe('createTokenStore', () => {
  const tok = { accessToken: 'a', expiresAt: 10, refreshToken: 'r', idToken: 'i' };

  it('keeps the token in sessionStorage so a reload of the tab finds it', () => {
    const storage = memoryStorage();
    createTokenStore(storage).set(tok);
    expect(createTokenStore(storage).get()).toEqual(tok);
  });

  it('serves from memory when storage throws (privacy mode)', () => {
    const broken = {
      getItem: () => {
        throw new Error('denied');
      },
      setItem: () => {
        throw new Error('denied');
      },
      removeItem: () => {
        throw new Error('denied');
      },
    };
    const store = createTokenStore(broken);
    store.set(tok);
    expect(store.get()).toEqual(tok);
    store.clear();
    expect(store.get()).toBeNull();
  });

  it('works with no storage at all and ignores a corrupt entry', () => {
    expect(createTokenStore(null).get()).toBeNull();
    expect(createTokenStore(memoryStorage({ 'mpa.auth.token': '{nope' })).get()).toBeNull();
    expect(createTokenStore(memoryStorage({ 'mpa.auth.token': '{"accessToken":3}' })).get()).toBeNull();
  });

  it('clear() wipes both memory and storage', () => {
    const storage = memoryStorage();
    const store = createTokenStore(storage);
    store.set(tok);
    store.clear();
    expect(store.get()).toBeNull();
    expect(storage.getItem('mpa.auth.token')).toBeNull();
  });
});

describe('tokenSetFromResponse', () => {
  it('reads access, refresh and id tokens with an absolute expiry', () => {
    expect(tokenSetFromResponse({ access_token: 'a', refresh_token: 'r', id_token: 'i', expires_in: 60 }, 1_000)).toEqual({
      accessToken: 'a',
      expiresAt: 61_000,
      refreshToken: 'r',
      idToken: 'i',
    });
  });

  it('keeps the previous refresh and id token when a refresh response omits them', () => {
    const prev = { accessToken: 'old', expiresAt: 0, refreshToken: 'r0', idToken: 'i0' };
    expect(tokenSetFromResponse({ access_token: 'a2', expires_in: 60 }, 0, prev)).toEqual({ accessToken: 'a2', expiresAt: 60_000, refreshToken: 'r0', idToken: 'i0' });
  });

  it('throws when no access token came back, naming the OAuth error', () => {
    expect(() => tokenSetFromResponse({ error: 'invalid_grant' }, 0)).toThrow(/invalid_grant/);
  });
});

describe('refreshDueIn', () => {
  it('fires a minute before expiry, so the token never crosses the freshness skew', () => {
    expect(refreshDueIn({ accessToken: 'a', expiresAt: 3_600_000 }, 0)).toBe(3_540_000);
  });

  it('is immediate for a token already inside that window', () => {
    expect(refreshDueIn({ accessToken: 'a', expiresAt: 30_000 }, 0)).toBe(0);
    expect(refreshDueIn({ accessToken: 'a', expiresAt: 0 }, 5_000)).toBe(0);
  });
});

describe('sanitizeReturnHash', () => {
  it.each([['#/'], ['#/v/espace-iv-01JABC'], ['#/legacy'], ['#/v/clio_2-x.y~z']])('keeps the in-app route %s', (hash) => {
    expect(sanitizeReturnHash(hash)).toBe(hash);
  });

  it.each([
    ['empty', ''],
    ['null', null],
    ['an absolute URL', 'https://evil.tld/#/v/x'],
    ['a scheme-relative URL', '//evil.tld'],
    ['a hash with a double slash', '#//evil.tld'],
    ['a path instead of a hash', '/v/x'],
    ['a javascript: URL', 'javascript:alert(1)'],
    ['a hash with a query-like payload', '#/v/x?next=https://evil.tld'],
    ['a hash with a backslash', '#/\\evil.tld'],
  ])('falls back to the parc for %s', (_label, hash) => {
    expect(sanitizeReturnHash(hash)).toBe('#/');
  });
});

describe('singleFlight', () => {
  it('joins concurrent callers onto one run, then allows a fresh run', async () => {
    let runs = 0;
    let release!: (v: number) => void;
    const once = singleFlight(() => {
      runs++;
      return new Promise<number>((r) => (release = r));
    });
    const a = once();
    const b = once();
    expect(runs).toBe(1);
    release(7);
    expect(await Promise.all([a, b])).toEqual([7, 7]);
    void once();
    expect(runs).toBe(2);
  });

  it('frees the slot after a rejection', async () => {
    let runs = 0;
    const once = singleFlight(async () => {
      runs++;
      throw new Error('x');
    });
    await expect(once()).rejects.toThrow('x');
    await expect(once()).rejects.toThrow('x');
    expect(runs).toBe(2);
  });
});

describe('endSessionUrl', () => {
  it('names the id token and the post-logout landing', () => {
    const url = new URL(endSessionUrl('https://auth.bonneref.fr/api/logout', 'id.jwt', 'https://localhost/'));
    expect(url.origin + url.pathname).toBe('https://auth.bonneref.fr/api/logout');
    expect(url.searchParams.get('id_token_hint')).toBe('id.jwt');
    expect(url.searchParams.get('post_logout_redirect_uri')).toBe('https://localhost/');
  });

  it('omits the hint when no id token is held', () => {
    expect(new URL(endSessionUrl('https://auth.bonneref.fr/api/logout', null, 'https://localhost/')).searchParams.has('id_token_hint')).toBe(false);
  });
});

// ── Glue, driven through stubbed window/storage/fetch ─────────────────────────────

interface Rig {
  session: Storage;
  local: Storage;
  location: { origin: string; pathname: string; href: string; hash: string; assign: ReturnType<typeof vi.fn> };
  replaced: string[];
  fetchMock: ReturnType<typeof vi.fn>;
}

function rig(opts: { session?: Record<string, string>; local?: Record<string, string>; href?: string; fetch?: (url: string, init: RequestInit) => Promise<Response> }): Rig {
  const href = opts.href ?? 'https://localhost/#/';
  const u = new URL(href);
  const location = { origin: u.origin, pathname: u.pathname, href, hash: u.hash, assign: vi.fn() };
  const replaced: string[] = [];
  const session = memoryStorage(opts.session);
  const local = memoryStorage(opts.local);
  vi.stubGlobal('window', {
    location,
    history: {
      replaceState: (_s: unknown, _t: string, url: string) => {
        replaced.push(url);
        const next = new URL(url, location.origin);
        location.pathname = next.pathname;
        location.hash = next.hash;
      },
    },
    addEventListener: () => undefined,
  });
  vi.stubGlobal('localStorage', local);
  vi.stubGlobal('sessionStorage', session);
  const fetchMock = vi.fn(opts.fetch ?? (async () => tokenResponse({})));
  vi.stubGlobal('fetch', fetchMock);
  vi.resetModules();
  return { session, local, location, replaced, fetchMock };
}

const held = (t: Partial<{ accessToken: string; expiresAt: number; refreshToken: string; idToken: string }>) => JSON.stringify({ accessToken: 'a1', expiresAt: Date.now() + 3_600_000, ...t });

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

// StrictMode double-invokes mount effects, so completeLoginIfCallback() is called twice in the same
// tick. The second call must join the first exchange instead of seeing the already-scrubbed URL and
// resolving `false` before the token lands — that race is what made every sign-in take two rounds.
describe('completeLoginIfCallback', () => {
  const callback = (extra: Record<string, string> = {}) =>
    rig({
      href: 'https://localhost/callback?code=c1&state=state-1',
      session: { 'mpa.auth.pkce_verifier': 'verifier-1', 'mpa.auth.state': 'state-1', ...extra },
      fetch: async () => new Promise((resolve) => setTimeout(() => resolve(tokenResponse({ access_token: 'tok-1', refresh_token: 'ref-1', id_token: 'id-1', expires_in: 3600 })), 0)),
    });

  it('runs the exchange once under a double invocation and keeps the token in the tab only', async () => {
    const r = callback();
    const { completeLoginIfCallback } = await import('./auth');

    const [first, second] = await Promise.all([completeLoginIfCallback(), completeLoginIfCallback()]);

    expect(r.fetchMock).toHaveBeenCalledTimes(1);
    expect(first).toBe(true);
    expect(second).toBe(true);
    expect(r.session.getItem('mpa.auth.token')).toContain('tok-1');
    expect(r.session.getItem('mpa.auth.token')).toContain('ref-1');
    expect(r.local.getItem('mpa.auth.token')).toBeNull();
  });

  it('still reports no callback on a plain page load', async () => {
    const r = rig({});
    const { completeLoginIfCallback } = await import('./auth');

    await expect(completeLoginIfCallback()).resolves.toBe(false);
    expect(r.fetchMock).not.toHaveBeenCalled();
  });

  it('lands back on the route the login started from', async () => {
    const r = callback({ 'mpa.auth.return': '#/v/espace-01J' });
    const { completeLoginIfCallback } = await import('./auth');

    await completeLoginIfCallback();

    expect(r.replaced).toEqual(['/#/v/espace-01J']);
    expect(r.session.getItem('mpa.auth.return')).toBeNull();
  });

  it('never lands on a stashed off-app return path', async () => {
    const r = callback({ 'mpa.auth.return': 'https://evil.tld/' });
    const { completeLoginIfCallback } = await import('./auth');

    await completeLoginIfCallback();

    expect(r.replaced).toEqual(['/#/']);
  });
});

describe('login', () => {
  it('stashes one verifier/state/return route even when several callers race into it', async () => {
    const r = rig({ href: 'https://localhost/#/v/clio-01J' });
    const { login } = await import('./auth');

    await Promise.all([login(), login(), login()]);

    expect(r.location.assign).toHaveBeenCalledTimes(1);
    const authorize = new URL(r.location.assign.mock.calls[0][0] as string);
    expect(authorize.searchParams.get('state')).toBe(r.session.getItem('mpa.auth.state'));
    expect(authorize.searchParams.get('scope')).toBe('openid profile email offline_access');
    expect(authorize.searchParams.has('resource')).toBe(false);
    expect(r.session.getItem('mpa.auth.return')).toBe('#/v/clio-01J');
  });
});

describe('the legacy persisted token', () => {
  it('is wiped from localStorage and never read', async () => {
    const r = rig({ local: { 'mpa.auth.token': held({ accessToken: 'leaked' }) } });
    const { getToken } = await import('./auth');

    expect(getToken()).toBeNull();
    expect(r.local.getItem('mpa.auth.token')).toBeNull();
  });
});

describe('authFetch', () => {
  it('never sends the Bearer to a foreign origin', async () => {
    const seen: Array<{ url: string; auth: string | null }> = [];
    rig({
      session: { 'mpa.auth.token': held({ accessToken: 'secret' }) },
      fetch: async (url, init) => {
        seen.push({ url, auth: new Headers(init.headers).get('Authorization') });
        return tokenResponse({});
      },
    });
    const { authFetch } = await import('./auth');

    await authFetch('https://localhost/garage/vehicles');
    await authFetch('https://localhost.evil.tld/x');
    await authFetch('//evil.tld/x');

    expect(seen).toEqual([
      { url: 'https://localhost/garage/vehicles', auth: 'Bearer secret' },
      { url: 'https://localhost.evil.tld/x', auth: null },
      { url: '//evil.tld/x', auth: null },
    ]);
  });

  it('refreshes once on concurrent 401s, then retries each request with the new token', async () => {
    const calls: string[] = [];
    const r = rig({
      session: { 'mpa.auth.token': held({ accessToken: 'old', refreshToken: 'ref-1' }) },
      fetch: async (url, init) => {
        if (url.includes('/access_token')) {
          calls.push(`refresh ${new URLSearchParams(init.body as string).get('refresh_token')}`);
          return tokenResponse({ access_token: 'new', refresh_token: 'ref-2', expires_in: 3600 });
        }
        const auth = new Headers(init.headers).get('Authorization');
        calls.push(`${url} ${auth}`);
        return tokenResponse({}, auth === 'Bearer new' ? 200 : 401);
      },
    });
    const { authFetch } = await import('./auth');

    const [a, b] = await Promise.all([authFetch('https://localhost/garage/a'), authFetch('https://localhost/garage/b')]);

    expect([a.status, b.status]).toEqual([200, 200]);
    expect(calls.filter((c) => c.startsWith('refresh'))).toEqual(['refresh ref-1']);
    expect(calls).toContain('https://localhost/garage/a Bearer new');
    expect(calls).toContain('https://localhost/garage/b Bearer new');
    expect(r.session.getItem('mpa.auth.token')).toContain('ref-2');
    expect(r.location.assign).not.toHaveBeenCalled();
  });

  it('refreshes an expired token before sending instead of going out bare', async () => {
    const calls: string[] = [];
    rig({
      session: { 'mpa.auth.token': held({ accessToken: 'old', expiresAt: Date.now() - 1, refreshToken: 'ref-1' }) },
      fetch: async (url, init) => {
        if (url.includes('/access_token')) return tokenResponse({ access_token: 'new', expires_in: 3600 });
        calls.push(new Headers(init.headers).get('Authorization') ?? 'none');
        return tokenResponse({});
      },
    });
    const { authFetch } = await import('./auth');

    await authFetch('https://localhost/garage/a');

    expect(calls).toEqual(['Bearer new']);
  });

  it('falls back to one login redirect when the refresh is refused', async () => {
    const r = rig({
      session: { 'mpa.auth.token': held({ accessToken: 'old', refreshToken: 'ref-1' }) },
      fetch: async (url) => (url.includes('/access_token') ? tokenResponse({ error: 'invalid_grant' }, 400) : tokenResponse({}, 401)),
    });
    const { authFetch } = await import('./auth');

    await Promise.all([authFetch('https://localhost/garage/a'), authFetch('https://localhost/garage/b')]);

    expect(r.location.assign).toHaveBeenCalledTimes(1);
    expect(r.session.getItem('mpa.auth.token')).toBeNull();
  });
});

describe('logout', () => {
  it('ends the IdP session and forgets everything this tab held', async () => {
    const r = rig({ session: { 'mpa.auth.token': held({ idToken: 'id-1', refreshToken: 'ref-1' }), 'mpa.session': 'sess-1' } });
    const { logout } = await import('./auth');

    logout();

    expect(r.session.getItem('mpa.auth.token')).toBeNull();
    expect(r.session.getItem('mpa.session')).toBeNull();
    const target = new URL(r.location.assign.mock.calls[0][0] as string);
    expect(target.origin + target.pathname).toBe('https://auth.bonneref.fr/api/logout');
    expect(target.searchParams.get('id_token_hint')).toBe('id-1');
    expect(target.searchParams.get('post_logout_redirect_uri')).toBe('https://localhost/');
  });
});
