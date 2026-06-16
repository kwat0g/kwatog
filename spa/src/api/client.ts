import axios, { AxiosError } from 'axios';
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
 *  • `withCredentials: true` is MANDATORY — Sanctum SPA cookie auth
 *    relies on the browser sending the session cookie on every request.
 *  • Never store auth tokens in localStorage / sessionStorage.
 *  • The CSRF cookie must be fetched once before the first state-changing
 *    request via `getCsrfCookie()` (the login page does this).
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
const handleResponseError = (error: AxiosError<LaravelDebugError>) => {
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

  // Per-request opt-out — queries can pass `{ skipErrorToast: true }` in their
  // axios config to suppress the global 5xx / 403 toast (errors still flow
  // through react-query / axios for inline handling).
  const skipToast = (error.config as { skipErrorToast?: boolean } | undefined)?.skipErrorToast === true;

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
    if (!skipToast) {
      toast.error('Request timed out. Please try again.', { duration: 5000 });
    }
    return Promise.reject(error);
  }

  switch (status) {
    case 401:
      // Don't auto-redirect for:
      //   • the login attempt itself (form handles its own error UI)
      //   • the bootstrap call from AuthGuard (AuthGuard handles routing)
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
      } else if (!skipToast) {
        toast.error(data?.message ?? 'You do not have permission to perform this action.');
      }
      break;

    case 404:
      // Silenced — pages render their own 404 state.
      break;

    case 419:
      // CSRF token mismatch — refresh the page to fetch a new token.
      toast.error('Your session was refreshed. Please try again.');
      break;

    case 422:
      // Validation errors are surfaced inline by forms.
      break;

    case 423:
      toast.error(data?.message ?? 'Account locked. Try again later.');
      break;

    case 429:
      toast.error('Too many requests. Please wait a moment.');
      break;

    case 500:
    case 502:
    case 503:
    case 504:
      if (!skipToast) {
        const serverMsg = import.meta.env.DEV ? data?.message : null;
        toast.error(serverMsg ?? 'Something went wrong. Please try again.', {
          duration: 5000,
        });
      }
      break;

    default:
      if (!error.response && !skipToast) {
        toast.error('Network error. Please check your connection.');
      }
  }

  return Promise.reject(error);
};

// ─── Attach interceptors to plain client ──────────────────────
client.interceptors.response.use((response) => response, handleResponseError);

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
  handleResponseError,
);

/**
 * Pre-flight CSRF endpoint. Sets the XSRF-TOKEN cookie that Axios
 * automatically forwards as `X-XSRF-TOKEN` on subsequent requests.
 */
export const getCsrfCookie = () =>
  axios.get('/sanctum/csrf-cookie', { withCredentials: true });
