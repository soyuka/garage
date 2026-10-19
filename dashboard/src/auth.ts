// ─────────────────────────────────────────────────────────────────────────────
// Atelier — Casdoor OAuth2 Authorization Code + PKCE (public client, no secret).
//
// Tier-1 transport (see PROVENANCE: a faithful client of the OAuth contract,
// re-authored fresh from the old dashboard reference, no import path back). The
// security-bearing logic — PKCE S256, token freshness, the callback state guard,
// JWT claim decode — is split into
// pure functions (unit-tested in auth.test.ts); the window/storage/fetch wiring is
// kept as thin, obviously-correct glue beneath them.
//
// Casdoor accepts an empty client_secret on the token exchange when a PKCE
// code_verifier is present, so the SPA ships no secret. The access token is a
// Casdoor-signed JWT; the API validates issuer + signature on the `api` firewall.
// Reads are public; the Bearer unlocks /agent/turn + every write.
// ─────────────────────────────────────────────────────────────────────────────

import { currentLocale } from './i18n/locale';

const ISSUER = (import.meta.env.VITE_CASDOOR_ISSUER ?? 'https://auth.bonneref.fr').replace(/\/$/, '');
const CLIENT_ID = import.meta.env.VITE_CASDOOR_CLIENT_ID ?? '671b8c86d75444f3f4c4';
const API = import.meta.env.VITE_API_BASE ?? '';
const SCOPE = 'openid profile email';

// The authorize step is a top-level navigation. The token POST goes straight to the issuer
// (auth.bonneref.fr), whose CORS must allow the page origin.
const AUTHORIZE_ENDPOINT = `${ISSUER}/login/oauth/authorize`;
export const TOKEN_ENDPOINT = `${ISSUER}/api/login/oauth/access_token`;

const CALLBACK_PATH = '/callback';
const TOKEN_KEY = 'mpa.auth.token';
const VERIFIER_KEY = 'mpa.auth.pkce_verifier';
const STATE_KEY = 'mpa.auth.state';
const SKEW_MS = 30_000;

export interface StoredToken {
  accessToken: string;
  expiresAt: number; // epoch ms
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
export function validateCallback(
  query: { code: string | null; state: string | null },
  stored: { verifier: string | null; expectedState: string | null },
): CallbackCheck {
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

/** Resolve a token's absolute expiry (epoch ms) from the OAuth `expires_in`, defaulting a missing or
 *  non-positive lifetime to 3600s so a server quirk never mints a dead-on-arrival session. */
export function tokenExpiryMs(expiresIn: number | undefined, now: number): number {
  const seconds = typeof expiresIn === 'number' && expiresIn > 0 ? expiresIn : 3600;
  return now + seconds * 1000;
}

// ── Storage glue ──────────────────────────────────────────────────────────────

function randomToken(byteLength = 48): string {
  const bytes = new Uint8Array(byteLength);
  crypto.getRandomValues(bytes);
  return base64urlEncode(bytes);
}

function readStoredToken(): StoredToken | null {
  const raw = localStorage.getItem(TOKEN_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as StoredToken;
  } catch {
    return null;
  }
}

/** The current access token if present and not within the skew of expiry, else null. */
export function getToken(): string | null {
  const token = readStoredToken();
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

/** Begin the redirect to Casdoor's hosted login, stashing the PKCE verifier + state (single-use). */
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
 * If the page loaded on /callback, exchange the code for a token and scrub the URL back to "/".
 * Returns true iff a callback was handled (the gate then re-checks isAuthenticated). Throws on a
 * state mismatch or a failed exchange so the gate can fall back to the anonymous screen.
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

  sessionStorage.removeItem(VERIFIER_KEY);
  sessionStorage.removeItem(STATE_KEY);
  // The code/state are single-use — strip them from history immediately.
  window.history.replaceState(null, '', '/');

  const check = validateCallback(
    { code: url.searchParams.get('code'), state: url.searchParams.get('state') },
    { verifier, expectedState },
  );
  if (!check.ok) throw new Error(`invalid OAuth callback: ${check.reason}`);

  const body = new URLSearchParams({
    grant_type: 'authorization_code',
    code: check.code,
    client_id: CLIENT_ID,
    redirect_uri: redirectUri(),
    code_verifier: check.verifier,
  });
  const res = await fetch(TOKEN_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  });
  if (!res.ok) throw new Error(`token exchange failed: ${res.status}`);

  const json = (await res.json()) as { access_token?: string; expires_in?: number; error?: string };
  if (!json.access_token) throw new Error(`token exchange returned no access_token${json.error ? `: ${json.error}` : ''}`);

  const expiresAt = tokenExpiryMs(json.expires_in, Date.now());
  localStorage.setItem(TOKEN_KEY, JSON.stringify({ accessToken: json.access_token, expiresAt } satisfies StoredToken));
  return true;
}

export function logout(): void {
  localStorage.removeItem(TOKEN_KEY);
  window.location.assign('/');
}

/**
 * fetch() with the Bearer attached when a fresh token is held. A 401 against a gated endpoint (a
 * write or /agent/turn) means the token is gone/expired — wipe it and bounce to login rather than
 * silently fail. Public GET reads work with or without a token.
 */
export async function authFetch(input: string, init: RequestInit = {}): Promise<Response> {
  const token = getToken();
  const headers = new Headers(init.headers);
  if (token) headers.set('Authorization', `Bearer ${token}`);
  // The interface language, announced the standard way. The API reads it via Symfony's
  // `set_locale_from_accept_language`, so nothing needs a bespoke field on any payload. Set
  // explicitly rather than left to the browser: the operator's choice (and `?lang=`) must win over
  // whatever languages the browser itself is configured with.
  headers.set('Accept-Language', currentLocale());

  const res = await fetch(input, { ...init, headers });
  if (res.status === 401) {
    localStorage.removeItem(TOKEN_KEY);
    await login();
  }
  return res;
}

export { API, CALLBACK_PATH };
