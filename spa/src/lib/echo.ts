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
import axios from 'axios';

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

// pusher-js does not support a URL path prefix, so the Reverb port must
// be reachable directly from the browser. docker-compose.yml exposes
// the reverb container on host port 8080.
const isHttps = window.location.protocol === 'https:';

export const echo = new Echo({
  broadcaster: 'reverb',
  key: env('VITE_REVERB_APP_KEY', 'ogami_reverb'),
  wsHost: env('VITE_REVERB_HOST', window.location.hostname),
  wsPort: Number(env('VITE_REVERB_PORT', '8080')),
  wssPort: Number(env('VITE_REVERB_PORT', '443')),
  forceTLS: env('VITE_REVERB_SCHEME', isHttps ? 'https' : 'http') === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: '/api/v1/broadcasting/auth',
  // Pusher's default XHR authorizer does not copy Laravel's XSRF cookie into
  // the X-XSRF-TOKEN header. Use Axios so private-channel authorization
  // carries both the session cookie and the current CSRF token.
  authorizer: (channel: { name: string }) => ({
    authorize: (socketId: string, callback: (error: boolean, data: unknown) => void) => {
      axios.post(
        '/api/v1/broadcasting/auth',
        { socket_id: socketId, channel_name: channel.name },
        {
          withCredentials: true,
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        },
      ).then((response) => callback(false, response.data))
        .catch((error: unknown) => callback(true, error));
    },
  }),
});
