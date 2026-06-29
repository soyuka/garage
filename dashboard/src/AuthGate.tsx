import { useEffect, useState, type ReactNode } from 'react';
import './auth.css';
import { completeLoginIfCallback, isAuthenticated, login } from './auth';

// The React glue over the pure auth core. AuthBoot resolves the OAuth callback before the app paints
// (so a held token is ready for the first read); RequireAuth bounces a signed-out visitor to Casdoor.
// Public dev playgrounds mount under AuthBoot but NOT RequireAuth — only the live garage surface is
// gated (every write + the agent turn need a Bearer; reads are public but show nothing pre-sign-in).

type BootPhase = 'checking' | 'ready';

/**
 * Completes the `/callback` token exchange (a no-op on any other path) before rendering children. A
 * failed exchange is swallowed — the visitor simply lands signed-out. Shows a terse splash meanwhile.
 */
export function AuthBoot({ children }: { children: ReactNode }) {
  const [phase, setPhase] = useState<BootPhase>('checking');
  useEffect(() => {
    completeLoginIfCallback()
      .catch(() => undefined)
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

/** Gate the live app: signed out → a Casdoor sign-in bounce; signed in → the children. */
export function RequireAuth({ children }: { children: ReactNode }) {
  if (isAuthenticated()) return <>{children}</>;
  return (
    <div className="at-auth-screen">
      <div className="at-auth-card">
        <div className="at-auth-mark" aria-hidden="true">
          ◉
        </div>
        <h1>mon-parc-auto · l’Atelier</h1>
        <p>Connectez-vous pour ouvrir votre garage.</p>
        <button type="button" className="at-auth-button" onClick={() => void login()}>
          Se connecter
        </button>
      </div>
    </div>
  );
}
