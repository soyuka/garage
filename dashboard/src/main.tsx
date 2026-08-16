import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import { AuthBoot } from './AuthGate';
import './tokens/stage.css';
import { applyStageLayer } from './tokens/stage';

// C5: stamp `data-stage` before the first paint, so the projector layer is never a visible re-theme.
applyStageLayer();

// AuthBoot wraps the whole app so the OAuth `/callback` exchange resolves before the first paint on
// ANY route (the redirect lands on the pathname, not a hash). Public dev playgrounds render under it
// unchanged; the live garage surface gates itself with RequireAuth.
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AuthBoot>
      <App />
    </AuthBoot>
  </StrictMode>,
);
