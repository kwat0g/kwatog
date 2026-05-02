import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

/**
 * frappe-gantt 0.6.x ships its dist file with a bare `import './gantt.scss'`
 * statement still in place. Vite then tries to compile that SCSS — which
 * forces a Sass implementation as a dependency just to display a Gantt
 * chart. We don't actually need Sass anywhere else in the SPA.
 *
 * This plugin intercepts that one specific import and replaces it with an
 * empty module. The Gantt's own visual styles are pulled in once via
 * styles/globals.css (which imports the precompiled frappe-gantt dist CSS).
 */
function suppressFrappeGanttScss(): Plugin {
  return {
    name: 'suppress-frappe-gantt-scss',
    enforce: 'pre',
    resolveId(source) {
      if (source.endsWith('frappe-gantt/src/gantt.scss') || source.endsWith('gantt.scss')) {
        return '\0frappe-gantt-scss-shim';
      }
      return null;
    },
    load(id) {
      if (id === '\0frappe-gantt-scss-shim') return 'export default {};';
      return null;
    },
  };
}

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [suppressFrappeGanttScss(), react()],
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
