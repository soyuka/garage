import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { viteBase } from './src/build/base';

// Every Vite path, HMR included, stays under the base so the dev Caddy needs a single matcher and the
// Atelier keeps `/`. Declared locally: the project ships no @types/node.
declare const process: { env: Record<string, string | undefined> };

export default defineConfig(({ command }) => {
  const base = viteBase(command, process.env.BONNEREF_BASE);

  return {
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
      // formatWhen and friends print Paris wall-clock times; pin it so CI (UTC) agrees.
      env: { TZ: 'Europe/Paris' },
      globals: true,
      include: ['src/**/*.test.ts'],
    },
  };
});
