import { renameSync, rmdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';

const SCRATCH = '/tmp/claude-1000/-home-soyuka-projects-mon-parc-auto-fr-mon-parc-auto-fr/c4fae325-cef5-4a9e-bf57-2b8195821ad5/scratchpad/bonneref-meshes';

const htmlAsIndex = (): Plugin => {
  let outDir = '';
  return {
    name: 'meshes-html-as-index',
    apply: 'build',
    configResolved: (config) => {
      outDir = resolve(config.root, config.build.outDir);
    },
    closeBundle() {
      renameSync(join(outDir, 'dev/meshes.html'), join(outDir, 'index.html'));
      rmdirSync(join(outDir, 'dev'));
    },
  };
};

// Standalone static build of the dev contact sheet (dev/meshes.html) for hosting under /bonneref-meshes/.
export default defineConfig({
  base: '/bonneref-meshes/',
  plugins: [react(), htmlAsIndex()],
  build: {
    outDir: process.env.MESHES_OUT_DIR ?? SCRATCH,
    emptyOutDir: true,
    rollupOptions: { input: 'dev/meshes.html' },
  },
});
