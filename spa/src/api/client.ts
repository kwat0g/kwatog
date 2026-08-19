import axios, { AxiosError, type AxiosInstance, type InternalAxiosRequestConfig } from 'axios';
import toast from 'react-hot-toast';
import { queryClient } from '@/lib/queryClient';
import { useErrorLogStore } from '@/stores/errorLogStore';

interface LaravelDebugError {
 message?: string;
 exception?: string;
 file?: string;
 line?: number;
 trace?: Array<{ file?: string; line?: number; function?: string; class?: string }>;
 code?: string;
 module?: string;
}

/**
 * Axios client for the SPA.
 *
 * • `withCredentials: true` is MANDATORY — Sanctum SPA cookie auth
 * relies on the browser sending the session cookie on every request.
 * • Never store auth tokens in localStorage / sessionStorage.
 * • The CSRF cookie must be fetched once before the first state-changing
 * request via `getCsrfCookie()` (the login page does this).
 */
export const client = axios.create({
 baseURL: '/api/v1',
 withCredentials: true,
 timeout: 30_000,
 headers: {
 Accept: 'application/json',
 'X-Requested-With': 'XMLHttpRequest',
 },
});

// ─── Shared error handler ─────────────────────────────────────
// Extracted as a named function so it can be attached to both `client`
// and `unwrappingClient` without duplicating logic.
interface OgamiRequestConfig extends InternalAxiosRequestConfig {
 /** Internal loop guard for the one-time CSRF recovery retry. */
 _csrfRetried?: boolean;
 /**
  * Force a global toast for a status the interceptor would otherwise leave to
  * the page (5xx on a mutation). Rarely needed — see `interceptorOwnsToast`.
  */
 showErrorToast?: boolean;
 /** Silence the interceptor entirely for this request. */
 skipErrorToast?: boolean;
}

/**
 * Marker set on an error the interceptor has already reported. Page-level
 * handlers use `wasReportedGlobally()` so one failure never produces two
 * toasts saying different things.
 */
const REPORTED = Symbol.for('ogami.errorReported');

export function wasReportedGlobally(error: unknown): boolean {
 return Boolean(error && typeof error === 'object' && (error as Record<symbol, unknown>)[REPORTED]);
}

function markReported(error: AxiosError): void {
 (error as unknown as Record<symbol, unknown>)[REPORTED] = true;
}

/**
 * Stable toast ids per failure class.
 *
 * A dashboard fires a dozen queries at once. When the API is down they all
 * reject, and without an id react-hot-toast stacks a dozen identical banners
 * that push each other off screen. Reusing the id replaces instead.
 */
const TOAST_ID = {
 timeout: 'net-timeout',
 offline: 'net-offline',
 throttled: 'http-429',
 locked: 'http-423',
 server: 'http-5xx',
} as const;

/**
 * Which failures the interceptor reports itself rather than leaving to the page.
 *
 * This used to be opt-in via `showErrorToast`, and nothing in the app ever
 * opted in — so a rate-limited, locked-out, timed-out or offline user got
 * silence from here and a misleading "Failed to create PO." from the page's
 * own `onError`. The page cannot know the difference; the interceptor can.
 *
 * • Infrastructure failures (timeout / offline / 429 / 423) always report,
 *   because the page's generic message is actively wrong about the cause.
 * • 5xx reports only for reads. Mutations nearly always have an `onError`
 *   with useful context ("Failed to post journal entry"); queries usually
 *   have nothing at all.
 * • 401 / 403 / 404 / 419 / 422 stay page- and guard-owned (see the switch).
 */
function interceptorOwnsToast(config: OgamiRequestConfig | undefined, status: number | undefined): boolean {
 if (config?.skipErrorToast === true) return false;
 if (config?.showErrorToast === true) return true;
 if (status === undefined || status >= 500) {
  const method = (config?.method ?? 'get').toLowerCase();
  return method === 'get' || method === 'head';
 }
 return true;
}

/**
 * Laravel's throttle middleware answers 429 with a `Retry-After` header in
 * whole seconds. Quoting it turns "Too many requests" — which reads as a bug —
 * into an instruction the user can act on.
 */
function throttleMessage(error: AxiosError<LaravelDebugError>): string {
 const raw = error.response?.headers?.['retry-after'];
 const seconds = Number(Array.isArray(raw) ? raw[0] : raw);
 if (!Number.isFinite(seconds) || seconds <= 0) {
  return 'Too many requests in a short time. Wait a moment and try again.';
 }
 if (seconds < 60) {
  return `Too many requests. Try again in ${Math.ceil(seconds)} second${Math.ceil(seconds) === 1 ? '' : 's'}.`;
 }
 const minutes = Math.ceil(seconds / 60);
 return `Too many requests. Try again in ${minutes} minute${minutes === 1 ? '' : 's'}.`;
}

const createResponseErrorHandler = (retryClient: AxiosInstance) => async (error: AxiosError<LaravelDebugError>) => {
 const status = error.response?.status;
 const data = error.response?.data;

 // Push every 4xx/5xx into the in-memory dev log so the floating
 // DevErrorPanel can show Laravel's exception details inline.
 if (status && status >= 400) {
 useErrorLogStore.getState().push({
 method: (error.config?.method ?? 'get').toUpperCase(),
 url: error.config?.url ?? '(unknown)',
 status,
 message: data?.message ?? error.message ?? 'Unknown error',
 exception: data?.exception,
 file: data?.file,
 line: data?.line,
 trace: data?.trace,
 raw: data,
 });
 }

 const requestUrl = error.config?.url ?? '';
 const isBootstrap = requestUrl.endsWith('/auth/user');
 const isLoginAttempt = requestUrl.endsWith('/auth/login');

 // Pages and mutations own their *validation* UI; the interceptor owns the
 // failures a page cannot diagnose. See `interceptorOwnsToast`.
 const requestConfig = error.config as OgamiRequestConfig | undefined;
 const showToast = interceptorOwnsToast(requestConfig, status);

 // Timeout — axios sets error.code = 'ECONNABORTED' when the request
 // exceeds the configured `timeout`. Check before the HTTP status switch
 // so timed-out requests get a clear message rather than the generic
 // network-error fallback in the `default` branch.
 if (error.code === 'ECONNABORTED') {
 useErrorLogStore.getState().push({
 method: (error.config?.method ?? 'get').toUpperCase(),
 url: error.config?.url ?? '(unknown)',
 status: 0,
 message: `Timeout after ${error.config?.timeout ?? 30_000}ms`,
 });
 if (requestConfig?.skipErrorToast !== true) {
 markReported(error);
 toast.error('The server took too long to respond. Nothing was saved — please try again.', {
 id: TOAST_ID.timeout,
 duration: 6000,
 });
 }
 return Promise.reject(error);
 }

 switch (status) {
 case 401:
 // Don't auto-redirect for:
 // • the login attempt itself (form handles its own error UI)
 // • the bootstrap call from AuthGuard (AuthGuard handles routing)
 if (!isLoginAttempt && !isBootstrap) {
 if (typeof window !== 'undefined' && window.location.pathname !== '/login') {
 // Defense in depth: the hard navigation below normally wipes
 // in-memory state, but clearing the query cache here guarantees no
 // stale cross-user data survives (e.g. a future soft-nav refactor).
 queryClient.clear();
 window.location.href = '/login';
 }
 }
 break;

 case 403:
 if (data?.code === 'password_expired') {
 if (window.location.pathname !== '/change-password') {
 window.location.href = '/change-password';
 }
 } else if (data?.code === 'feature_disabled') {
 // ModuleGuard handles UI; suppress toast here.
 } else if (showToast) {
 markReported(error);
 toast.error(data?.message ?? 'You do not have permission to perform this action.');
 }
 break;

 case 404:
 // Silenced — pages render their own 404 state.
 break;

 case 419:
 // A tab left open longer than the Laravel session can retain an obsolete
 // XSRF cookie. Refresh it and replay the failed mutation exactly once so
 // login and normal forms recover without a hard browser refresh.
 if (requestConfig && !requestConfig._csrfRetried) {
 requestConfig._csrfRetried = true;
 try {
 await getCsrfCookie();
 } catch {
 return Promise.reject(error);
 }
 // Keep this outside the try/catch: if the replay receives a validation
 // error, the caller must see that response rather than the original 419.
 return retryClient.request(requestConfig);
 }
 break;

 case 422:
 // Validation errors are surfaced inline by forms.
 break;

 case 423:
 if (showToast) {
 markReported(error);
 toast.error(data?.message ?? 'This account is locked. Try again later or contact IT.', {
 id: TOAST_ID.locked,
 duration: 6000,
 });
 }
 break;

 case 429:
 if (showToast) {
 markReported(error);
 toast.error(throttleMessage(error), { id: TOAST_ID.throttled, duration: 6000 });
 }
 break;

 case 500:
 case 502:
 case 503:
 case 504:
 if (showToast) {
 markReported(error);
 const serverMsg = import.meta.env.DEV ? data?.message : null;
 toast.error(serverMsg ?? 'The server had a problem loading this. Try again in a moment.', {
 id: TOAST_ID.server,
 duration: 6000,
 });
 }
 break;

 default:
 // No `error.response` means the request never reached the API — DNS,
 // dropped Wi-Fi, or a proxy refusing the connection. Distinguish it from
 // a server error so the user knows to check their own connection, and
 // from OfflineBanner's queue message, which only covers mutations.
 if (!error.response && showToast) {
 markReported(error);
 toast.error(
 navigator.onLine === false
 ? 'You are offline. This will work again once the connection returns.'
 : 'Could not reach the server. Check your connection and try again.',
 { id: TOAST_ID.offline, duration: 6000 },
 );
 }
 }

 return Promise.reject(error);
};

// ─── Attach interceptors to plain client ──────────────────────
client.interceptors.response.use((response) => response, createResponseErrorHandler(client));

// ─── Unwrapping client ────────────────────────────────────────
// Use this instance ONLY for files that have been migrated away from the
// `.then(r => r.data.data)` double-unwrap pattern. It strips Laravel's
// single-key { data: <payload> } envelope so callers receive the payload
// via `r.data`. Guard: only fires when 'data' is the ONLY key — paginated
// responses ({ data, meta, links }) and message-only responses pass through
// untouched.
//
// Files still using `.then(r => r.data.data)` MUST continue using the plain
// `client` export — switching them to `unwrappingClient` before removing the
// double-unwrap will silently break them.
export const unwrappingClient = axios.create({
 baseURL: '/api/v1',
 withCredentials: true,
 timeout: 30_000,
 headers: {
 Accept: 'application/json',
 'X-Requested-With': 'XMLHttpRequest',
 },
});

unwrappingClient.interceptors.response.use(
 (response) => {
 if (
 response.data !== null &&
 typeof response.data === 'object' &&
 'data' in response.data &&
 Object.keys(response.data).length === 1
 ) {
 response.data = (response.data as { data: unknown }).data;
 }
 return response;
 },
 createResponseErrorHandler(unwrappingClient),
);

/**
 * Pre-flight CSRF endpoint. Sets the XSRF-TOKEN cookie that Axios
 * automatically forwards as `X-XSRF-TOKEN` on subsequent requests.
 */
export const getCsrfCookie = () =>
 axios.get('/sanctum/csrf-cookie', { withCredentials: true });
