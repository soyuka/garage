import { useEffect, useState, type ReactNode } from 'react';
import './auth.css';
import {
  CALLBACK_PATH,
  completeLoginIfCallback,
  hasAuthFailure,
  isAuthenticated,
  justLoggedOut,
  login,
  loginAttemptedRecently,
  resumeSession,
  shouldAutoLogin,
} from './auth';
import { useT } from './i18n/LocaleProvider';

// The React glue over the pure auth core. AuthBoot resolves the OAuth callback before the app paints
// (so a held token is ready for the first read); RequireAuth bounces a signed-out visitor to Casdoor.
// Public dev playgrounds mount under AuthBoot but NOT RequireAuth — only the live garage surface is
// gated (every write + the agent turn need a Bearer; reads are public but show nothing pre-sign-in).

type BootPhase = 'checking' | 'ready';

/**
 * Completes the `/callback` token exchange (a no-op on any other path) and renews a stale token from
 * the refresh token before rendering children. A failed exchange is swallowed — the visitor simply
 * lands signed-out. Shows a terse splash meanwhile.
 */
export function AuthBoot({ children }: { children: ReactNode }) {
  const [phase, setPhase] = useState<BootPhase>('checking');
  useEffect(() => {
    completeLoginIfCallback()
      .catch(() => undefined)
      .then(() => resumeSession())
      .finally(() => setPhase('ready'));
  }, []);
  if (phase === 'checking')
    return (
      <div className="at-auth-splash" role="status" aria-live="polite">
        Connexion…
      </div>
    );
  return <>{children}</>;
}

/**
 * Gate the live app: signed in → the children; a fresh signed-out visit → straight to Casdoor. The
 * sign-in screen (with the error, if any) stays for the cases an automatic redirect would loop or
 * override intent: a failed round-trip, a redirect already attempted in the last ~30 s, an explicit
 * logout. The decision is taken once, on first render — the redirect stamps its own attempt.
 */
export function RequireAuth({ children }: { children: ReactNode }) {
  const t = useT();
  const signedIn = isAuthenticated();
  const [failed] = useState(hasAuthFailure);
  const [autoLogin] = useState(() =>
    shouldAutoLogin({
      signedIn,
      onCallback: window.location.pathname === CALLBACK_PATH,
      error: failed,
      recentAttempt: loginAttemptedRecently(),
      justLoggedOut: justLoggedOut(),
    }),
  );
  useEffect(() => {
    if (autoLogin) login().catch(() => undefined);
  }, [autoLogin]);

  if (signedIn) return <>{children}</>;
  if (autoLogin)
    return (
      <div className="at-auth-splash" role="status" aria-live="polite">
        {t.auth.redirecting}
      </div>
    );
  return (
    <div className="at-auth-screen">
      <div className="at-auth-card">
        <div className="at-auth-mark" aria-hidden="true">
          ◉
        </div>
        <h1>{t.auth.title}</h1>
        {failed && <p role="alert">{t.auth.failed}</p>}
        <p>{t.auth.prompt}</p>
        <button type="button" className="at-auth-button" onClick={() => void login()}>
          {t.auth.signIn}
        </button>
      </div>
    </div>
  );
}
