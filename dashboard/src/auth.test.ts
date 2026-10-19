import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  TOKEN_ENDPOINT,
  base64urlEncode,
  decodeJwtClaims,
  isTokenFresh,
  pkceChallenge,
  tokenExpiryMs,
  validateCallback,
} from './auth';

// Pure-core tests for the OAuth/PKCE transport. The thin window/storage/fetch glue is left untested
// (trivial wiring) — this pins the security-bearing logic: PKCE math, token-freshness skew, the
// callback state check, and JWT claim decoding.

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

// StrictMode double-invokes mount effects, so completeLoginIfCallback() is called twice in the same
// tick. The second call must join the first exchange instead of seeing the already-scrubbed URL and
// resolving `false` before the token lands — that race is what made every sign-in take two rounds.
describe('completeLoginIfCallback under a double invocation (StrictMode)', () => {
  const store = (): Storage => {
    const map = new Map<string, string>();
    return {
      getItem: (k: string) => map.get(k) ?? null,
      setItem: (k: string, v: string) => void map.set(k, v),
      removeItem: (k: string) => void map.delete(k),
      clear: () => map.clear(),
      key: (i: number) => [...map.keys()][i] ?? null,
      get length() {
        return map.size;
      },
    } as Storage;
  };

  let local: Storage;
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    local = store();
    const session = store();
    session.setItem('mpa.auth.pkce_verifier', 'verifier-1');
    session.setItem('mpa.auth.state', 'state-1');

    const location = { origin: 'https://localhost', pathname: '/callback', href: 'https://localhost/callback?code=c1&state=state-1' };
    vi.stubGlobal('window', {
      location,
      history: { replaceState: (_s: unknown, _t: string, url: string) => void (location.pathname = url) },
    });
    vi.stubGlobal('localStorage', local);
    vi.stubGlobal('sessionStorage', session);

    fetchMock = vi.fn(
      async () =>
        new Promise((resolve) =>
          setTimeout(() => resolve({ ok: true, status: 200, json: async () => ({ access_token: 'tok-1', expires_in: 3600 }) } as Response), 0),
        ),
    );
    vi.stubGlobal('fetch', fetchMock);
    vi.resetModules();
  });

  afterEach(() => vi.unstubAllGlobals());

  it('runs the exchange once and reports the handled callback to both callers', async () => {
    const { completeLoginIfCallback } = await import('./auth');

    const [first, second] = await Promise.all([completeLoginIfCallback(), completeLoginIfCallback()]);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(first).toBe(true);
    expect(second).toBe(true);
    expect(local.getItem('mpa.auth.token')).toContain('tok-1');
  });

  it('still reports no callback on a plain page load', async () => {
    (globalThis as unknown as { window: { location: { pathname: string } } }).window.location.pathname = '/';
    const { completeLoginIfCallback } = await import('./auth');

    await expect(completeLoginIfCallback()).resolves.toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
