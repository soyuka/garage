import type { ReactNode } from 'react';
import { matchRoute, type DevRoute } from './router';
import { NoyauPlayground } from './noyau/NoyauPlayground';
import { FloorPlayground } from './floor/FloorPlayground';
import { AtelierScene } from './scene/AtelierScene';
import { ParcPlayground } from './scene/ParcPlayground';
import { AuthPlayground } from './AuthPlayground';
import { WorkshopFixture } from './workshop/WorkshopFixture';

// The PUBLIC fixture-driven dev playgrounds (no auth), reachable from the dev index at #/dev — the
// stage-by-stage reference harness. Loaded only by a dev build (App.tsx): production carries none of it.

interface DevRouteEntry extends DevRoute {
  stage?: string;
  render: () => ReactNode;
}

const ROUTES: DevRouteEntry[] = [
  { path: '/dev', title: 'Atelier — dev index', render: () => <DevIndex /> },
  { path: '/noyau', title: 'Le Noyau (playground)', stage: 'Stage A', render: () => <NoyauPlayground /> },
  { path: '/atelier', title: 'Le sol honnête (truth-panel + plan)', stage: 'Stage B.1', render: () => <FloorPlayground /> },
  { path: '/scene', title: "L'atelier (Le Plateau + Le Noyau)", stage: 'Stage B.2', render: () => <AtelierScene /> },
  { path: '/parc', title: 'Le Parc (vue du parc)', stage: 'Stage B.2', render: () => <ParcPlayground /> },
  { path: '/auth', title: 'Auth (PKCE + gate-verify)', stage: 'Stage C.1', render: () => <AuthPlayground /> },
  { path: '/dev/workshop', title: 'Workshop (fixtures + frame injector)', stage: 'Redesign slice 1', render: () => <WorkshopFixture /> },
];

function DevIndex(): ReactNode {
  const base = import.meta.env.VITE_API_BASE ?? '(unset)';
  const hub = import.meta.env.VITE_MERCURE_URL ?? '(unset)';
  return (
    <main style={{ fontFamily: 'monospace', padding: '2rem', lineHeight: 1.7 }}>
      <h1 style={{ fontSize: '1.1rem', letterSpacing: '0.04em' }}>ATELIER · dashboard — dev playgrounds</h1>
      <p>
        Fixture-driven reference harness. The live app is at <a href="#/">#/ (Le Parc)</a>.
      </p>
      <ul>
        {ROUTES.filter((r) => r.path !== '/dev').map((r) => (
          <li key={r.path}>
            <a href={`#${r.path}`}>{r.title}</a> <span style={{ opacity: 0.5 }}>— {r.stage}</span>
          </li>
        ))}
      </ul>
      <hr style={{ opacity: 0.2, margin: '1.5rem 0' }} />
      <p style={{ opacity: 0.6 }}>
        VITE_API_BASE = <code>{base}</code>
        <br />
        VITE_MERCURE_URL = <code>{hub}</code>
      </p>
    </main>
  );
}

export default function DevRoutes({ path }: { path: string }): ReactNode {
  if (path.startsWith('/dev/workshop')) return <WorkshopFixture />;
  const route = matchRoute(`#${path}`, ROUTES) as DevRouteEntry | undefined;
  return route ? route.render() : null;
}
