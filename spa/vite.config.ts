import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    // Direct proxy for local dev without docker (kept for convenience).
    proxy: {
      '/api': { target: 'http://localhost', changeOrigin: false, secure: false },
      '/sanctum': { target: 'http://localhost', changeOrigin: false, secure: false },
    },
    hmr: {
      // When accessed through Nginx proxy, HMR connects to the same host.
      clientPort: Number(process.env.VITE_HMR_PORT) || 5173,
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/setupTests.ts'],
    globals: true,
  },
});
