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
      // When accessed through the Nginx proxy on port 80, the browser
      // must connect its HMR WebSocket to the same origin — not Vite's
      // internal port 5173. Override clientPort so it matches NGINX_PORT.
      protocol: 'ws',
      host: process.env.VITE_HMR_HOST || 'localhost',
      clientPort: Number(process.env.VITE_HMR_PORT) || Number(process.env.NGINX_PORT) || 80,
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/setupTests.ts'],
    globals: true,
  },
});
