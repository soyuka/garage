import { StrictMode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import { completeLoginIfCallback } from './auth';
import { ToastProvider } from './ui/Toast';
import './styles.css';

function Boot() {
  const [ready, setReady] = useState(false);
  useEffect(() => {
    completeLoginIfCallback()
      .catch(() => undefined)
      .finally(() => setReady(true));
  }, []);
  return ready ? <App /> : null;
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ToastProvider>
      <Boot />
    </ToastProvider>
  </StrictMode>,
);
