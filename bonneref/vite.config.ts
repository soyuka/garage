import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

// Served same-origin behind Caddy at https://localhost/bonneref/ (`handle /bonneref*` in
// api/frankenphp/Caddyfile). Every Vite path, HMR included, stays under the base so Caddy needs a
// single matcher and the Atelier keeps `/`. A production build sets BONNEREF_BASE=/ .
// Declared locally: the project ships no @types/node.
declare const process: { env: Record<string, string | undefined> };

const base = process.env.BONNEREF_BASE ?? '/bonneref/';

export default defineConfig({
  base,
  plugins: [react()],
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    allowedHosts: ['localhost', 'bonneref'],
    hmr: {
      protocol: 'wss',
      host: 'localhost',
      clientPort: 443,
      path: `${base}vite-hmr`,
    },
  },
  test: {
    environment: 'node',
    globals: true,
    include: ['src/**/*.test.ts'],
  },
});
