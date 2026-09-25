import { defineConfig, mergeConfig } from 'vite';
import base from './vite.config';
export default mergeConfig(base, defineConfig({
  build: { outDir: '/tmp/delivery-completion-spa-DCP0925A' },
  preview: {
    host: '0.0.0.0', port: 5222, strictPort: true,
    proxy: {
      '/api': { target: 'http://api:8126', changeOrigin: false },
      '/sanctum': { target: 'http://api:8126', changeOrigin: false },
    },
  },
}));
