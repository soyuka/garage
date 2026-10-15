import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

// Served same-origin behind Caddy at https://localhost/finder/ (`handle /finder*` in
// api/frankenphp/Caddyfile). Every Vite path, HMR included, stays under /finder so Caddy needs a
// single matcher and the Atelier keeps `/`.
export default defineConfig({
  base: '/finder/',
  plugins: [react()],
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    allowedHosts: ['localhost', 'finder'],
    hmr: {
      protocol: 'wss',
      host: 'localhost',
      clientPort: 443,
      path: '/finder/vite-hmr',
    },
  },
  test: {
    environment: 'node',
    globals: true,
    include: ['src/**/*.test.ts'],
  },
});
