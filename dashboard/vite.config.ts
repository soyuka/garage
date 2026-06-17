import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: {
    // The Mercure hub (Caddy) has no CORS for the dev origin; proxy it same-origin instead.
    // The agent endpoint is reached directly (NelmioCors already allows localhost).
    proxy: {
      '/.well-known/mercure': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },
});
