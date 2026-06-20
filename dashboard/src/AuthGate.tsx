import { useEffect, useState, type ReactNode } from 'react';
import { completeLoginIfCallback, isAuthenticated, login } from './auth';

type Phase = 'checking' | 'anon' | 'authed';

/**
 * Gates the app behind a Casdoor login. On mount it first completes the OAuth callback if the page
 * loaded on /callback (exchanging the code for a token), then renders the app when a token is held
 * or a sign-in screen when not. The whole dashboard is behind auth because every write + the agent
 * turn now require a Bearer; reads are public but there is nothing to show before sign-in anyway.
 */
export function AuthGate({ children }: { children: ReactNode }) {
  const [phase, setPhase] = useState<Phase>('checking');

  useEffect(() => {
    completeLoginIfCallback()
      .catch(() => undefined)
      .finally(() => setPhase(isAuthenticated() ? 'authed' : 'anon'));
  }, []);

  if (phase === 'checking') {
    return <div className="auth-screen">Connexion…</div>;
  }

  if (phase === 'anon') {
    return (
      <div className="auth-screen">
        <div className="auth-card">
          <div className="mark">M</div>
          <h1>mon-parc-auto · Atelier</h1>
          <p>Connectez-vous pour accéder à votre garage.</p>
          <button type="button" className="auth-button" onClick={() => void login()}>
            Se connecter
          </button>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}
