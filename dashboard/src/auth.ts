// ─────────────────────────────────────────────────────────────────────────────
// Atelier — Casdoor OAuth2 Authorization Code + PKCE (public client, no secret).
//
// Tier-1 transport (a faithful client of the OAuth contract,
// re-authored fresh from the old dashboard reference, no import path back). The
// security-bearing logic — PKCE S256, token freshness, the callback state guard,
// JWT claim decode — is split into
// pure functions (unit-tested in auth.test.ts); the window/storage/fetch wiring is
// kept as thin, obviously-correct glue beneath them.
//
// Casdoor accepts an empty client_secret on the token exchange when a PKCE
// code_verifier is present, so the SPA ships no secret. The access token is a
// Casdoor-signed JWT; the API validates issuer + signature + `aud` (this client id)
// on the `api` firewall. Reads are public; the Bearer unlocks /agent/turn + every write.
//
// Tokens live in this tab only (memory, mirrored to sessionStorage so a reload keeps
// the session); `offline_access` buys a refresh token that renews the access token
// a minute before it expires, and once more on a 401, before any login redirect.
// ─────────────────────────────────────────────────────────────────────────────

import { currentLocale } from './i18n/locale';

const ISSUER = (import.meta.env.VITE_CASDOOR_ISSUER ?? 'https://auth.bonneref.fr').replace(/\/$/, '');
const CLIENT_ID = import.meta.env.VITE_CASDOOR_CLIENT_ID ?? '671b8c86d75444f3f4c4';
const API = import.meta.env.VITE_API_BASE ?? '';
const SCOPE = 'openid profile email offline_access';

// The authorize step is a top-level navigation. The token POST goes straight to the issuer
// (auth.bonneref.fr), whose CORS must allow the page origin.
const AUTHORIZE_ENDPOINT = `${ISSUER}/login/oauth/authorize`;
export const TOKEN_ENDPOINT = `${ISSUER}/api/login/oauth/access_token`;
// From the issuer's /.well-known/openid-configuration (end_session_endpoint, checked 2026-11-09).
const END_SESSION_ENDPOINT = `${ISSUER}/api/logout`;

const CALLBACK_PATH = '/callback';
const TOKEN_KEY = 'mpa.auth.token';
const VERIFIER_KEY = 'mpa.auth.pkce_verifier';
const STATE_KEY = 'mpa.auth.state';
const RETURN_KEY = 'mpa.auth.return';
const SKEW_MS = 30_000;
const REFRESH_LEAD_MS = 60_000;

export interface StoredToken {
  accessToken: string;
  expiresAt: number; // epoch ms
  refreshToken?: string;
  idToken?: string;
}

const redirectUri = (): string => `${window.location.origin}${CALLBACK_PATH}`;

// ── Pure core (unit-tested) ───────────────────────────────────────────────────

/** RFC 4648 §5 base64url (url-safe alphabet, no `=` padding) of a byte buffer. */
export function base64urlEncode(bytes: Uint8Array): string {
  let binary = '';
  for (const b of bytes) binary += String.fromCharCode(b);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** The S256 PKCE code_challenge = base64url(SHA-256(verifier)) (RFC 7636). */
export async function pkceChallenge(verifier: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
  return base64urlEncode(new Uint8Array(digest));
}

/** True when a token exists and is more than the clock-skew margin away from expiry. */
export function isTokenFresh(token: StoredToken | null, now: number, skewMs = SKEW_MS): boolean {
  return token !== null && token.expiresAt - skewMs > now;
}

export type CallbackCheck = { ok: true; code: string; verifier: string } | { ok: false; reason: string };

/**
 * Validate the OAuth redirect against the single-use values stashed before the redirect. The state
 * equality is the CSRF guard; a missing verifier means no login was in flight (a stray /callback hit).
 */
export function validateCallback(query: { code: string | null; state: string | null }, stored: { verifier: string | null; expectedState: string | null }): CallbackCheck {
  if (!query.code) return { ok: false, reason: 'missing code' };
  if (!stored.verifier) return { ok: false, reason: 'no login in flight' };
  if (!query.state || query.state !== stored.expectedState) return { ok: false, reason: 'state mismatch' };
  return { ok: true, code: query.code, verifier: stored.verifier };
}

/** Decode a JWT's payload claims for display only (never trust client-side for authz). {} on garbage. */
export function decodeJwtClaims(jwt: string): Record<string, unknown> {
  const payload = jwt.split('.')[1];
  if (!payload) return {};
  try {
    // atob yields a Latin-1 binary string; re-read the bytes as UTF-8 so accented identity claims
    // (e.g. "Désiré") survive instead of becoming mojibake.
    const binary = atob(payload.replace(/-/g, '+').replace(/_/g, '/'));
    const bytes = Uint8Array.from(binary, (ch) => ch.charCodeAt(0));
    return JSON.parse(new TextDecoder().decode(bytes)) as Record<string, unknown>;
  } catch {
    return {};
  }
}

/** The origin the Bearer belongs to: the configured API base's, or the page's when the base is empty/relative. */
export function apiOrigin(base: string, pageOrigin: string): string {
  return new URL(base || pageOrigin, pageOrigin).origin;
}

/**
 * Only a request resolving to the API origin may carry the Bearer. Call sites build URLs by
 * concatenating an IRI onto the base, so a hostile IRI (`.evil.tld/x`, `//evil.tld/x`) would otherwise
 * ship the token to another host.
 */
export function bearerAllowed(input: string, pageOrigin: string, allowedOrigin: string): boolean {
  try {
    return new URL(input, pageOrigin).origin === allowedOrigin;
  } catch {
    return false;
  }
}

/** Resolve a token's absolute expiry (epoch ms) from the OAuth `expires_in`, defaulting a missing or
 *  non-positive lifetime to 3600s so a server quirk never mints a dead-on-arrival session. */
export function tokenExpiryMs(expiresIn: number | undefined, now: number): number {
  const seconds = typeof expiresIn === 'number' && expiresIn > 0 ? expiresIn : 3600;
  return now + seconds * 1000;
}

interface TokenResponse {
  access_token?: string;
  refresh_token?: string;
  id_token?: string;
  expires_in?: number;
  error?: string;
}

/** A token-endpoint response as a held token. A refresh response may omit the refresh/id token: the previous ones stay. */
export function tokenSetFromResponse(json: TokenResponse, now: number, previous?: StoredToken | null): StoredToken {
  if (!json.access_token) throw new Error(`token endpoint returned no access_token${json.error ? `: ${json.error}` : ''}`);
  const token: StoredToken = { accessToken: json.access_token, expiresAt: tokenExpiryMs(json.expires_in, now) };
  const refreshToken = json.refresh_token ?? previous?.refreshToken;
  const idToken = json.id_token ?? previous?.idToken;
  if (refreshToken) token.refreshToken = refreshToken;
  if (idToken) token.idToken = idToken;
  return token;
}

/** Milliseconds until the silent refresh should run: a minute ahead of expiry, past the freshness skew. */
export function refreshDueIn(token: StoredToken, now: number): number {
  return Math.max(0, token.expiresAt - REFRESH_LEAD_MS - now);
}

/** Only an in-app hash route survives the login round-trip; anything else lands on the parc. */
export function sanitizeReturnHash(hash: string | null): string {
  if (!hash || !/^#\/[A-Za-z0-9/_.~-]*$/.test(hash) || hash.includes('//')) return '#/';
  return hash;
}

/** Concurrent callers share one in-flight run; the slot frees once it settles. */
export function singleFlight<T>(run: () => Promise<T>): () => Promise<T> {
  let inFlight: Promise<T> | null = null;
  return () =>
    (inFlight ??= run().finally(() => {
      inFlight = null;
    }));
}

/** RP-initiated logout at the issuer (OIDC end_session_endpoint). */
export function endSessionUrl(endpoint: string, idToken: string | null, postLogoutRedirectUri: string): string {
  const params = new URLSearchParams();
  if (idToken) params.set('id_token_hint', idToken);
  params.set('post_logout_redirect_uri', postLogoutRedirectUri);
  return `${endpoint}?${params.toString()}`;
}

type TokenStorage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

function isStoredToken(value: unknown): value is StoredToken {
  const t = value as StoredToken | null;
  return typeof t === 'object' && t !== null && typeof t.accessToken === 'string' && typeof t.expiresAt === 'number';
}

/**
 * The tab's token: memory first, mirrored to sessionStorage so a reload keeps the session but a closed
 * browser does not. A storage that throws (privacy modes) degrades to memory only.
 */
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
    clear(): void {
      memory = null;
      attempt(() => storage?.removeItem(TOKEN_KEY));
    },
  };
}

// ── Storage glue ──────────────────────────────────────────────────────────────

function randomToken(byteLength = 48): string {
  const bytes = new Uint8Array(byteLength);
  crypto.getRandomValues(bytes);
  return base64urlEncode(bytes);
}

function tabStorage(): Storage | null {
  try {
    return sessionStorage;
  } catch {
    return null;
  }
}

const tokens = createTokenStore(tabStorage());

// Tokens used to persist in localStorage, readable by every tab and surviving a browser restart.
try {
  localStorage.removeItem(TOKEN_KEY);
} catch {
  /* nothing to wipe */
}

/** The current access token if present and not within the skew of expiry, else null. */
export function getToken(): string | null {
  const token = tokens.get();
  return isTokenFresh(token, Date.now()) ? token!.accessToken : null;
}

export function isAuthenticated(): boolean {
  return getToken() !== null;
}

/**
 * Display identity decoded from the held token (name/email/sub). Empty when signed out.
 *
 * `name` prefers Casdoor's `displayName` claim over `name`, because `name` is the LOGIN — on this
 * stack the sole user is the built-in `admin`, so greeting by `name` says "bonjour admin" while the
 * person is called Soyuka (measured on a live token, 2026-09-11). `displayName` is the human-facing
 * field and the one the operator can actually change; `name` stays the fallback for an IdP that sends
 * no display name at all.
 */
export function getIdentity(): { name?: string; email?: string; sub?: string } {
  const token = getToken();
  if (!token) return {};
  const c = decodeJwtClaims(token);
  const display = typeof c.displayName === 'string' && c.displayName.trim() !== '' ? c.displayName : undefined;
  return {
    name: display ?? (c.name as string | undefined),
    email: c.email as string | undefined,
    sub: c.sub as string | undefined,
  };
}

// ── Flow glue ─────────────────────────────────────────────────────────────────

let refreshTimer: ReturnType<typeof setTimeout> | null = null;

function scheduleRefresh(): void {
  if (refreshTimer !== null) clearTimeout(refreshTimer);
  refreshTimer = null;
  const token = tokens.get();
  if (!token?.refreshToken) return;
  refreshTimer = setTimeout(() => void refreshSession(), refreshDueIn(token, Date.now()));
}

async function postToken(body: URLSearchParams): Promise<TokenResponse & { ok: boolean; status: number }> {
  const res = await fetch(TOKEN_ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
  const json = (await res.json().catch(() => ({}))) as TokenResponse;
  return { ...json, ok: res.ok, status: res.status };
}

/**
 * Trade the held refresh token for a fresh access token. True on success; on refusal the tab's tokens
 * are dropped so the next gated call goes to login. Concurrent callers (a burst of 401s, the timer)
 * share one exchange: a rotated refresh token is single-use.
 */
const refreshSession = singleFlight(async (): Promise<boolean> => {
  const held = tokens.get();
  if (!held?.refreshToken) return false;
  try {
    const json = await postToken(new URLSearchParams({ grant_type: 'refresh_token', refresh_token: held.refreshToken, client_id: CLIENT_ID, scope: SCOPE }));
    if (!json.ok) throw new Error(`token refresh failed: ${json.status}`);
    tokens.set(tokenSetFromResponse(json, Date.now(), held));
    scheduleRefresh();
    return true;
  } catch {
    tokens.clear();
    return false;
  }
});

/** On boot: renew a stale access token from the refresh token, and arm the pre-expiry refresh. */
export async function resumeSession(): Promise<void> {
  if (!isAuthenticated() && tokens.get()?.refreshToken) await refreshSession();
  scheduleRefresh();
}

let loginInFlight: Promise<void> | null = null;

/**
 * Begin the redirect to Casdoor's hosted login, stashing the PKCE verifier + state + the hash route to
 * come back to (single-use). One redirect per page: concurrent callers (a burst of 401s) join it rather
 * than each overwriting the verifier/state the callback will be checked against.
 */
export function login(): Promise<void> {
  loginInFlight ??= beginLogin().catch((e: unknown) => {
    loginInFlight = null;
    throw e;
  });
  return loginInFlight;
}

async function beginLogin(): Promise<void> {
  const verifier = randomToken();
  const state = randomToken(16);
  sessionStorage.setItem(VERIFIER_KEY, verifier);
  sessionStorage.setItem(STATE_KEY, state);
  sessionStorage.setItem(RETURN_KEY, sanitizeReturnHash(window.location.hash));

  const params = new URLSearchParams({
    client_id: CLIENT_ID,
    response_type: 'code',
    redirect_uri: redirectUri(),
    scope: SCOPE,
    state,
    code_challenge: await pkceChallenge(verifier),
    code_challenge_method: 'S256',
  });
  // Back from the IdP through the bfcache, this page is live again with the guard still set.
  window.addEventListener('pageshow', (e) => {
    if ((e as PageTransitionEvent).persisted) loginInFlight = null;
  });
  window.location.assign(`${AUTHORIZE_ENDPOINT}?${params.toString()}`);
}

/**
 * If the page loaded on /callback, exchange the code for a token and scrub the URL back to the route
 * the login started from. Returns true iff a callback was handled (the gate then re-checks
 * isAuthenticated). Throws on a state mismatch or a failed exchange so the gate can fall back to the
 * anonymous screen.
 *
 * Concurrent calls join the single in-flight exchange. StrictMode double-invokes the mount effect:
 * without this, the second call would see the already-scrubbed URL, resolve `false` in a microtask
 * and paint the signed-out screen while the token was still on the wire — making every sign-in
 * take two rounds.
 */
export function completeLoginIfCallback(): Promise<boolean> {
  if (exchangeInFlight) return exchangeInFlight;
  if (window.location.pathname !== CALLBACK_PATH) return Promise.resolve(false);
  exchangeInFlight = exchangeCallback();
  return exchangeInFlight;
}

let exchangeInFlight: Promise<boolean> | null = null;

async function exchangeCallback(): Promise<boolean> {
  const url = new URL(window.location.href);
  const verifier = sessionStorage.getItem(VERIFIER_KEY);
  const expectedState = sessionStorage.getItem(STATE_KEY);
  const returnTo = sanitizeReturnHash(sessionStorage.getItem(RETURN_KEY));

  sessionStorage.removeItem(VERIFIER_KEY);
  sessionStorage.removeItem(STATE_KEY);
  sessionStorage.removeItem(RETURN_KEY);
  // The code/state are single-use — strip them from history immediately.
  window.history.replaceState(null, '', `/${returnTo}`);

  const check = validateCallback({ code: url.searchParams.get('code'), state: url.searchParams.get('state') }, { verifier, expectedState });
  if (!check.ok) throw new Error(`invalid OAuth callback: ${check.reason}`);

  const json = await postToken(
    new URLSearchParams({
      grant_type: 'authorization_code',
      code: check.code,
      client_id: CLIENT_ID,
      redirect_uri: redirectUri(),
      code_verifier: check.verifier,
    }),
  );
  if (!json.ok) throw new Error(`token exchange failed: ${json.status}`);

  tokens.set(tokenSetFromResponse(json, Date.now()));
  scheduleRefresh();
  return true;
}

/** Forget this tab's session (tokens, PKCE leftovers, the agent session id) and end the IdP session too. */
export function logout(): void {
  const held = tokens.get();
  tokens.clear();
  if (refreshTimer !== null) clearTimeout(refreshTimer);
  refreshTimer = null;
  try {
    sessionStorage.clear();
  } catch {
    /* memory already cleared */
  }
  window.location.assign(endSessionUrl(END_SESSION_ENDPOINT, held?.idToken ?? null, `${window.location.origin}/`));
}

/**
 * fetch() with the Bearer attached when a token is held and the URL is on the API origin. A stale
 * token is renewed before sending; a 401 gets one refresh and one retry, and only then a login
 * redirect. Public GET reads work with or without a token.
 */
export async function authFetch(input: string, init: RequestInit = {}): Promise<Response> {
  const toApi = bearerAllowed(input, window.location.origin, apiOrigin(API, window.location.origin));
  if (!toApi) return send(input, init, null);

  if (!isAuthenticated() && tokens.get()?.refreshToken) await refreshSession();
  const used = getToken();
  const res = await send(input, init, used);
  if (res.status !== 401) return res;

  // Another caller may already have renewed the token this request went out with.
  const renewed = getToken() !== used || (await refreshSession());
  if (renewed && getToken()) {
    const retry = await send(input, init, getToken());
    if (retry.status !== 401) return retry;
  }
  tokens.clear();
  await login();
  return res;
}

function send(input: string, init: RequestInit, token: string | null): Promise<Response> {
  const headers = new Headers(init.headers);
  if (token) headers.set('Authorization', `Bearer ${token}`);
  // The interface language, announced the standard way. The API reads it via Symfony's
  // `set_locale_from_accept_language`, so nothing needs a bespoke field on any payload. Set
  // explicitly rather than left to the browser: the operator's choice (and `?lang=`) must win over
  // whatever languages the browser itself is configured with.
  headers.set('Accept-Language', currentLocale());
  return fetch(input, { ...init, headers });
}

export { API, CALLBACK_PATH };
