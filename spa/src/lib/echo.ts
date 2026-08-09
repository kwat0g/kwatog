/**
 * Sprint 6 — Task 55. Laravel Echo client.
 *
 * Talks to Reverb via the Pusher protocol. The `/broadcasting/auth` endpoint
 * uses cookie-based auth (Sanctum stateful — `withCredentials: true`), so
 * no token plumbing is needed in the browser.
 *
 * ── Why this is lazy ──────────────────────────────────────────────────────
 * The singleton used to be built at module scope, and this module is reachable
 * statically from `App.tsx` (AppLayout → usePermissionSync → here). Two costs
 * fell out of that: `pusher-js` + `laravel-echo` (~21 KB gzipped) sat in the
 * entry chunk on every route, and `new Echo()` opened a WebSocket to Reverb
 * the moment the bundle evaluated — including for anonymous visitors on the
 * public landing page and /login, whose private-channel subscribes can only
 * ever be rejected by the Sanctum-gated auth endpoint.
 *
 * `getEcho()` defers both: the libraries download on first call and the
 * connection opens then. Every consumer hook runs inside `AuthGuard`, so in
 * practice that is "once a session exists".
 */
import axios from 'axios';

const env = (k: string, fallback?: string): string | undefined => {
  // import.meta.env is injected by Vite at build time.
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const v = (import.meta as any).env?.[k];
  return v == null || v === '' ? fallback : String(v);
};

async function buildEcho() {
  const [{ default: Echo }, { default: Pusher }] = await Promise.all([
    import('laravel-echo'),
    import('pusher-js'),
  ]);

  (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

  // pusher-js does not support a URL path prefix, so the Reverb port must
  // be reachable directly from the browser. docker-compose.yml exposes
  // the reverb container on host port 8080.
  const isHttps = window.location.protocol === 'https:';

  return new Echo({
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
        axios
          .post(
            '/api/v1/broadcasting/auth',
            { socket_id: socketId, channel_name: channel.name },
            {
              withCredentials: true,
              headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
            },
          )
          .then((response) => callback(false, response.data))
          .catch((error: unknown) => callback(true, error));
      },
    }),
  });
}

/** The resolved Echo singleton's type, inferred from the constructor call. */
export type EchoClient = Awaited<ReturnType<typeof buildEcho>>;

let instance: Promise<EchoClient> | null = null;

/**
 * Resolve the shared Echo singleton, downloading `laravel-echo` + `pusher-js`
 * and opening the connection on first call. Concurrent callers share one
 * promise, so only one connection is ever opened.
 */
export function getEcho(): Promise<EchoClient> {
  if (!instance) instance = buildEcho();
  return instance;
}
