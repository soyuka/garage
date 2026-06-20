// OAuth2 Authorization Code + PKCE against Casdoor, for a PUBLIC client (no secret in the bundle).
// Casdoor accepts an empty client_secret on the token exchange when a PKCE code_verifier is present
// (object/token_oauth.go: the secret check is skipped when code_challenge != ""), so the SPA never
// ships a secret. The access token is a JWT signed by Casdoor (tokenFormat=JWT on the seeded app);
// the API validates it on the `api` firewall via App\Security\CasdoorTokenHandler (issuer +
// signature, no audience). Reads stay public; the Bearer is what unlocks the gated writes.

const ISSUER = (import.meta.env.VITE_CASDOOR_ISSUER ?? 'http://casdoor:8000').replace(/\/$/, '');
const CLIENT_ID = import.meta.env.VITE_CASDOOR_CLIENT_ID ?? 'monparc-dashboard-0a1b2c3d';
const SCOPE = 'openid profile email';

const AUTHORIZE_ENDPOINT = `${ISSUER}/login/oauth/authorize`;
const TOKEN_ENDPOINT = `${ISSUER}/api/login/oauth/access_token`;

// Persisted across the redirect round-trip. The token lives in localStorage so a reload keeps the
// session; the PKCE verifier/state are single-use and live in sessionStorage (one login attempt).
const TOKEN_KEY = 'mpa.auth.token';
const VERIFIER_KEY = 'mpa.auth.pkce_verifier';
const STATE_KEY = 'mpa.auth.state';

const CALLBACK_PATH = '/callback';

interface StoredToken {
  accessToken: string;
  expiresAt: number; // epoch ms
}

const redirectUri = (): string => `${window.location.origin}${CALLBACK_PATH}`;

function base64url(bytes: Uint8Array): string {
  let binary = '';
  for (const b of bytes) binary += String.fromCharCode(b);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function randomToken(byteLength = 48): string {
  const bytes = new Uint8Array(byteLength);
  crypto.getRandomValues(bytes);
  return base64url(bytes);
}

async function pkceChallenge(verifier: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
  return base64url(new Uint8Array(digest));
}

function readToken(): StoredToken | null {
  const raw = localStorage.getItem(TOKEN_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as StoredToken;
  } catch {
    return null;
  }
}

/** The current access token if present and not within 30s of expiry, else null. */
export function getToken(): string | null {
  const token = readToken();
  if (!token) return null;
  return token.expiresAt - 30_000 > Date.now() ? token.accessToken : null;
}

export function isAuthenticated(): boolean {
  return getToken() !== null;
}

/** Decode the JWT access-token claims for display (name/email). Never trust this for authz. */
export function getIdentity(): { name?: string; email?: string } {
  const token = getToken();
  if (!token) return {};
  const payload = token.split('.')[1];
  if (!payload) return {};
  try {
    const json = JSON.parse(atob(payload.replace(/-/g, '+').replace(/_/g, '/'))) as Record<string, unknown>;
    return { name: json.name as string | undefined, email: json.email as string | undefined };
  } catch {
    return {};
  }
}

/** Kick off the redirect to Casdoor's hosted login page (username/password, GitHub/Google, …). */
export async function login(): Promise<void> {
  const verifier = randomToken();
  const state = randomToken(16);
  sessionStorage.setItem(VERIFIER_KEY, verifier);
  sessionStorage.setItem(STATE_KEY, state);

  const params = new URLSearchParams({
    client_id: CLIENT_ID,
    response_type: 'code',
    redirect_uri: redirectUri(),
    scope: SCOPE,
    state,
    code_challenge: await pkceChallenge(verifier),
    code_challenge_method: 'S256',
  });
  window.location.assign(`${AUTHORIZE_ENDPOINT}?${params.toString()}`);
}

/**
 * If the page loaded on the OAuth callback (…/callback?code=…&state=…), exchange the code for a
 * token and clean the URL back to "/". Returns true when a callback was handled (success or not).
 * Throws on a state mismatch or a failed exchange so the gate can fall back to an anonymous screen.
 */
export async function completeLoginIfCallback(): Promise<boolean> {
  if (window.location.pathname !== CALLBACK_PATH) return false;

  const url = new URL(window.location.href);
  const code = url.searchParams.get('code');
  const state = url.searchParams.get('state');
  const verifier = sessionStorage.getItem(VERIFIER_KEY);
  const expectedState = sessionStorage.getItem(STATE_KEY);

  sessionStorage.removeItem(VERIFIER_KEY);
  sessionStorage.removeItem(STATE_KEY);
  // Strip the code/state from history immediately — they are single-use and must not linger.
  window.history.replaceState(null, '', '/');

  if (!code || !verifier || !state || state !== expectedState) {
    throw new Error('Invalid OAuth callback (missing code or state mismatch)');
  }

  const body = new URLSearchParams({
    grant_type: 'authorization_code',
    code,
    client_id: CLIENT_ID,
    redirect_uri: redirectUri(),
    code_verifier: verifier,
  });

  const res = await fetch(TOKEN_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  });
  if (!res.ok) throw new Error(`token exchange failed: ${res.status}`);

  const json = (await res.json()) as { access_token?: string; expires_in?: number; error?: string };
  if (!json.access_token) throw new Error(`token exchange returned no access_token${json.error ? `: ${json.error}` : ''}`);

  const expiresAt = Date.now() + (json.expires_in ?? 3600) * 1000;
  localStorage.setItem(TOKEN_KEY, JSON.stringify({ accessToken: json.access_token, expiresAt } satisfies StoredToken));
  return true;
}

export function logout(): void {
  localStorage.removeItem(TOKEN_KEY);
  window.location.assign('/');
}

/**
 * fetch() with the Bearer attached when a token is present. A 401 means the token is missing or
 * expired against a gated endpoint (a write or /agent/turn) — drop it and bounce to login so the
 * user re-authenticates instead of silently failing. Public GET reads work with or without a token.
 */
export async function authFetch(input: string, init: RequestInit = {}): Promise<Response> {
  const token = getToken();
  const headers = new Headers(init.headers);
  if (token) headers.set('Authorization', `Bearer ${token}`);

  const res = await fetch(input, { ...init, headers });
  if (res.status === 401) {
    localStorage.removeItem(TOKEN_KEY);
    await login();
  }
  return res;
}
