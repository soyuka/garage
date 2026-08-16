import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import { AuthBoot } from './AuthGate';
import './tokens/stage.css';
import { applyStageLayer } from './tokens/stage';
import { bootLocale } from './i18n/locale';
import { LocaleProvider } from './i18n/LocaleProvider';

// C5: stamp `data-stage` before the first paint, so the projector layer is never a visible re-theme.
applyStageLayer();

// Same reason, for the language: settle it before the first paint so the UI never flashes French and
// then re-renders English, and so <html lang> is right for a screen reader from the very first frame.
bootLocale();

// AuthBoot wraps the whole app so the OAuth `/callback` exchange resolves before the first paint on
// ANY route (the redirect lands on the pathname, not a hash). Public dev playgrounds render under it
// unchanged; the live garage surface gates itself with RequireAuth.
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <LocaleProvider>
      <AuthBoot>
        <App />
      </AuthBoot>
    </LocaleProvider>
  </StrictMode>,
);
