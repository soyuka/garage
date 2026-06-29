import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

// The Atelier dashboard is served SAME-ORIGIN behind Caddy at https://localhost — it is Caddy's
// `@frontend` upstream (FRONTEND_UPSTREAM=dashboard:5173 in api/compose.override.yaml). The browser
// never talks to Vite directly, so there is no dev proxy and
// no CORS: every backend path (/garage, /agent, /.well-known/mercure, /casdoor) is same-origin, and
// the OAuth callback lands at https://localhost/callback (already a registered Casdoor redirect URI).
//
// HMR must phone home over wss on :443 (Caddy's TLS) on a dedicated path so the Caddyfile @frontend
// matcher forwards the upgrade instead of letting it fall through to PHP.
export default defineConfig({
  plugins: [react()],
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    allowedHosts: ['localhost', 'dashboard'],
    hmr: {
      protocol: 'wss',
      host: 'localhost',
      clientPort: 443,
      path: '/vite-hmr',
    },
  },
  test: {
    environment: 'node',
    globals: true,
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
  },
});
