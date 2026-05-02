/**
 * Sprint 6 — Task 55. Laravel Echo client.
 *
 * Talks to Reverb via the Pusher protocol. The `/broadcasting/auth` endpoint
 * uses cookie-based auth (Sanctum stateful — `withCredentials: true`), so
 * no token plumbing is needed in the browser.
 *
 * Imported for side-effect from `main.tsx` so the singleton is created
 * before any component subscribes via `useEcho`.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window { Pusher: typeof Pusher }
}

window.Pusher = Pusher;

const env = (k: string, fallback?: string): string | undefined => {
  // import.meta.env is injected by Vite at build time.
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const v = (import.meta as any).env?.[k];
  return v == null || v === '' ? fallback : String(v);
};

export const echo = new Echo({
  broadcaster: 'reverb',
  key: env('VITE_REVERB_APP_KEY', 'app-key'),
  wsHost: env('VITE_REVERB_HOST', window.location.hostname),
  wsPort: Number(env('VITE_REVERB_PORT', '8080')),
  wssPort: Number(env('VITE_REVERB_PORT', '443')),
  forceTLS: env('VITE_REVERB_SCHEME', 'http') === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: '/api/v1/broadcasting/auth',
  withCredentials: true, // MANDATORY for cookie-based Sanctum auth
});
