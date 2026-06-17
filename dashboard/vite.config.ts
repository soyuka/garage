import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: {
    // Listen on all interfaces inside the container so Caddy (the `php` service) reaches it over
    // the compose network as dashboard:5173. The whole app is served same-origin behind Caddy at
    // https://localhost, so the browser never talks to Vite directly — no dev proxy needed.
    host: true,
    port: 5173,
    strictPort: true,
    // Requests arrive via Caddy with Host: localhost; Vite 5 rejects unknown hosts otherwise.
    allowedHosts: ['localhost', 'dashboard'],
    // HMR must phone home over wss on :443 (Caddy's TLS). A dedicated path lets the Caddyfile
    // @frontend matcher forward the upgrade — the default HMR ws shares "/" and would miss the
    // path-based forward list and fall through to PHP.
    hmr: {
      protocol: 'wss',
      host: 'localhost',
      clientPort: 443,
      path: '/vite-hmr',
    },
  },
});
