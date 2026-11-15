// Casdoor Authorization Code + PKCE, public client — adapted from dashboard/src/auth.ts (copied, not
// imported). The token POST goes straight to the issuer (https://auth.bonneref.fr), whose CORS must
// allow the page origin. Local dev signs in on auth.bonneref.fr too, so its https://localhost
// callback must be a registered redirect URI on that app.

const ISSUER = (import.meta.env.VITE_CASDOOR_ISSUER ?? 'https://auth.bonneref.fr').replace(/\/$/, '');
const CLIENT_ID = import.meta.env.VITE_CASDOOR_CLIENT_ID ?? '89646c002d2272351ca2';
const SCOPE = 'openid profile email';
const BASE = import.meta.env.BASE_URL;
const CALLBACK_PATH = `${BASE}callback`;
// Shared with the Atelier only where both are served from the same origin (local dev), and then only within
// one tab; on bonneref.fr's own origin the token is bonneref's alone.
const TOKEN_KEY = 'mpa.auth.token';
const VERIFIER_KEY = 'bonneref.auth.pkce_verifier';
const STATE_KEY = 'bonneref.auth.state';
const RETURN_KEY = 'bonneref.auth.return';
const SKEW_MS = 30_000;

export interface StoredToken {
  accessToken: string;
  expiresAt: number;
}

const redirectUri = (): string => `${window.location.origin}${CALLBACK_PATH}`;

export const TOKEN_ENDPOINT = `${ISSUER}/api/login/oauth/access_token`;

export function base64urlEncode(bytes: Uint8Array): string {
  let binary = '';
  for (const b of bytes) binary += String.fromCharCode(b);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export async function pkceChallenge(verifier: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
  return base64urlEncode(new Uint8Array(digest));
}

export const isTokenFresh = (token: StoredToken | null, now: number): boolean =>
  token !== null && token.expiresAt - SKEW_MS > now;

function randomToken(byteLength = 48): string {
  const bytes = new Uint8Array(byteLength);
  crypto.getRandomValues(bytes);
  return base64urlEncode(bytes);
}

type TokenStorage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

function isStoredToken(value: unknown): value is StoredToken {
  const t = value as StoredToken | null;
  return typeof t === 'object' && t !== null && typeof t.accessToken === 'string' && typeof t.expiresAt === 'number';
}

// The tab's token, as in dashboard/src/auth.ts: memory first, mirrored to sessionStorage so a reload keeps the
// session but another tab, or the browser after a restart, does not. A storage that throws degrades to memory.
export function createTokenStore(storage: TokenStorage | null) {
  let memory: StoredToken | null | undefined;
  const attempt = (fn: () => void) => {
    try {
      fn();
    } catch {
      /* memory still holds it */
    }
  };
  return {
    get(): StoredToken | null {
      if (memory !== undefined) return memory;
      let parsed: unknown = null;
      attempt(() => {
        const raw = storage?.getItem(TOKEN_KEY);
        parsed = raw ? JSON.parse(raw) : null;
      });
      memory = isStoredToken(parsed) ? parsed : null;
      return memory;
    },
    set(token: StoredToken): void {
      memory = token;
      attempt(() => storage?.setItem(TOKEN_KEY, JSON.stringify(token)));
    },
  };
}

function tabStorage(): TokenStorage | null {
  try {
    return sessionStorage;
  } catch {
    return null;
  }
}

const tokens = createTokenStore(tabStorage());

// Older builds kept the token in localStorage, readable by any script on the origin in every tab and surviving
// a browser restart.
try {
  localStorage.removeItem(TOKEN_KEY);
} catch {
  /* nothing to wipe */
}

export function getToken(): string | null {
  const token = tokens.get();
  return token !== null && isTokenFresh(token, Date.now()) ? token.accessToken : null;
}

export const isCallbackPath = (): boolean => window.location.pathname === CALLBACK_PATH;

export const isAuthenticated = (): boolean => getToken() !== null;

export async function login(): Promise<void> {
  const verifier = randomToken();
  const state = randomToken(16);
  sessionStorage.setItem(VERIFIER_KEY, verifier);
  sessionStorage.setItem(STATE_KEY, state);
  sessionStorage.setItem(RETURN_KEY, window.location.hash);
  const params = new URLSearchParams({
    client_id: CLIENT_ID,
    response_type: 'code',
    redirect_uri: redirectUri(),
    scope: SCOPE,
    state,
    code_challenge: await pkceChallenge(verifier),
    code_challenge_method: 'S256',
  });
  window.location.assign(`${ISSUER}/login/oauth/authorize?${params.toString()}`);
}

let exchangeInFlight: Promise<boolean> | null = null;

export function completeLoginIfCallback(): Promise<boolean> {
  if (exchangeInFlight) return exchangeInFlight;
  if (window.location.pathname !== CALLBACK_PATH) return Promise.resolve(false);
  exchangeInFlight = exchangeCallback();
  return exchangeInFlight;
}

async function exchangeCallback(): Promise<boolean> {
  const url = new URL(window.location.href);
  const verifier = sessionStorage.getItem(VERIFIER_KEY);
  const expectedState = sessionStorage.getItem(STATE_KEY);
  const back = sessionStorage.getItem(RETURN_KEY) ?? '';
  sessionStorage.removeItem(VERIFIER_KEY);
  sessionStorage.removeItem(STATE_KEY);
  sessionStorage.removeItem(RETURN_KEY);
  window.history.replaceState(null, '', `${BASE}${back}`);

  const code = url.searchParams.get('code');
  if (!code || !verifier || url.searchParams.get('state') !== expectedState) throw new Error('invalid OAuth callback');

  const res = await fetch(TOKEN_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'authorization_code',
      code,
      client_id: CLIENT_ID,
      redirect_uri: redirectUri(),
      code_verifier: verifier,
    }),
  });
  if (!res.ok) throw new Error(`token exchange failed: ${res.status}`);
  const json = (await res.json()) as { access_token?: string; expires_in?: number };
  if (!json.access_token) throw new Error('token exchange returned no access_token');
  const lifetime = typeof json.expires_in === 'number' && json.expires_in > 0 ? json.expires_in : 3600;
  tokens.set({ accessToken: json.access_token, expiresAt: Date.now() + lifetime * 1000 });
  return true;
}

export async function authFetch(input: string, init: RequestInit = {}): Promise<Response> {
  const token = getToken();
  const headers = new Headers(init.headers);
  if (token) headers.set('Authorization', `Bearer ${token}`);
  headers.set('Accept-Language', 'fr');
  return fetch(input, { ...init, headers });
}
