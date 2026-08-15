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
    // Vite validates the Host header and 403s anything it does not recognise,
    // as a DNS-rebinding defence. Empty by default so local dev keeps its
    // localhost-only behaviour; remote dev sets VITE_ALLOWED_HOSTS.
    allowedHosts: (process.env.VITE_ALLOWED_HOSTS ?? '')
      .split(',')
      .map((h) => h.trim())
      .filter(Boolean),
    // Direct proxy for local dev without docker (kept for convenience).
    proxy: {
      '/api': { target: 'http://localhost', changeOrigin: false, secure: false },
      '/sanctum': { target: 'http://localhost', changeOrigin: false, secure: false },
    },
    hmr: {
      // When accessed through the Nginx proxy on port 80, the browser
      // must connect its HMR WebSocket to the same origin — not Vite's
      // internal port 5173. Override clientPort so it matches NGINX_PORT.
      // protocol follows the proxy's scheme: ws behind plain HTTP locally,
      // wss when the proxy terminates TLS. A page served over HTTPS refuses a
      // plaintext ws:// connection as mixed content, and the failure is silent
      // — HMR just never connects.
      protocol: (process.env.VITE_HMR_PROTOCOL as 'ws' | 'wss') || 'ws',
      host: process.env.VITE_HMR_HOST || 'localhost',
      clientPort: Number(process.env.VITE_HMR_PORT) || Number(process.env.NGINX_PORT) || 80,
    },
  },
  build: {
    chunkSizeWarningLimit: 600,
    sourcemap: false,
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (
            id.includes('node_modules/react/') ||
            id.includes('node_modules/react-dom/') ||
            id.includes('node_modules/react-router-dom/')
          ) {
            return 'vendor-react';
          }
          if (
            id.includes('node_modules/@tanstack/react-query') ||
            id.includes('node_modules/@tanstack/react-table')
          ) {
            return 'vendor-query';
          }
          if (
            id.includes('node_modules/react-hook-form/') ||
            id.includes('node_modules/@hookform/') ||
            id.includes('node_modules/zod/')
          ) {
            return 'vendor-forms';
          }
          if (id.includes('node_modules/recharts/')) {
            return 'vendor-charts';
          }
          // Landing-only heavy deps — split so they stream in parallel and
          // only ever load on the lazy `/` route, never in the ERP bundle.
          if (id.includes('node_modules/three/')) {
            return 'vendor-three';
          }
          if (
            id.includes('node_modules/gsap/') ||
            id.includes('node_modules/lenis/')
          ) {
            return 'vendor-motion';
          }
          if (
            id.includes('node_modules/lucide-react/') ||
            id.includes('node_modules/date-fns/') ||
            id.includes('node_modules/clsx/')
          ) {
            return 'vendor-ui';
          }
          if (
            id.includes('node_modules/laravel-echo/') ||
            id.includes('node_modules/pusher-js/')
          ) {
            return 'vendor-realtime';
          }
        },
      },
    },
  },
});
