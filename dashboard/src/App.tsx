import { lazy, Suspense, type ReactNode } from 'react';
import { useHashPath } from './router';
import { appRoute } from './appRoute';
import { RequireAuth } from './AuthGate';
import { Workshop } from './workshop/Workshop';

// The router. The LIVE garage owns the root (#/, the parc) and the vehicle route (#/v/<seg>, the hero),
// both behind RequireAuth. The legacy Atelier (#/legacy) loads on demand. The fixture playgrounds
// (devRoutes.tsx) exist only in a dev build: `import.meta.env.DEV` is a build-time constant, so
// production drops the import and never emits the chunk.

const Atelier = lazy(() => import('./Atelier').then((m) => ({ default: m.Atelier })));
const DevRoutes = import.meta.env.DEV ? lazy(() => import('./devRoutes')) : null;

function NotFound({ path }: { path: string }): ReactNode {
  return (
    <main style={{ fontFamily: 'monospace', padding: '2rem' }}>
      <p>
        {import.meta.env.DEV && (
          <>
            <a href="#/dev">← dev index</a> ·{' '}
          </>
        )}
        <a href="#/">Le Parc</a>
      </p>
      <p>
        No route for <code>{path}</code>.
      </p>
    </main>
  );
}

export default function App(): ReactNode {
  const path = useHashPath();
  switch (appRoute(path, import.meta.env.DEV)) {
    case 'workshop':
      return (
        <RequireAuth>
          <Workshop />
        </RequireAuth>
      );
    case 'legacy':
      return (
        <RequireAuth>
          <Suspense fallback={null}>
            <Atelier />
          </Suspense>
        </RequireAuth>
      );
    case 'dev':
      return DevRoutes ? (
        <Suspense fallback={null}>
          <DevRoutes path={path} />
        </Suspense>
      ) : null;
    case 'notFound':
      return <NotFound path={path} />;
  }
}
