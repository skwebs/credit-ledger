import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ command }) => ({
  // Local dev serves at localhost root; production is deployed at /credit-card/.
  base: command === 'build' ? '/credit-card/' : '/',
  plugins: [react()],
  server: {
    proxy: {
      '/api': { target: 'http://127.0.0.1:8080', changeOrigin: true },
    },
  },
  build: { outDir: 'dist', emptyOutDir: true },
}));
