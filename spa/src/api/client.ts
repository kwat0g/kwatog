import axios, { AxiosError } from 'axios';
import toast from 'react-hot-toast';
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
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

// ─── Response interceptor ──────────────────────────────────────
client.interceptors.response.use(
  (response) => response,
  (error: AxiosError<LaravelDebugError>) => {
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

    switch (status) {
      case 401:
        // Don't auto-redirect for:
        //   • the login attempt itself (form handles its own error UI)
        //   • the bootstrap call from AuthGuard (AuthGuard handles routing)
        if (!isLoginAttempt && !isBootstrap) {
          if (typeof window !== 'undefined' && window.location.pathname !== '/login') {
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
          // In dev, surface the actual Laravel exception class + message in
          // the toast so the user doesn't need to open the dev panel for
          // every error. Production stays generic.
          const dev = import.meta.env.DEV;
          const detail = dev && data?.exception
            ? `${data.exception}: ${data.message ?? ''}`.trim()
            : null;
          toast.error(detail ?? data?.message ?? 'Something went wrong. Please try again.', {
            duration: dev ? 8000 : 4000,
          });
        }
        break;

      default:
        if (!error.response && !skipToast) {
          toast.error('Network error. Please check your connection.');
        }
    }

    return Promise.reject(error);
  },
);

/**
 * Pre-flight CSRF endpoint. Sets the XSRF-TOKEN cookie that Axios
 * automatically forwards as `X-XSRF-TOKEN` on subsequent requests.
 */
export const getCsrfCookie = () =>
  axios.get('/sanctum/csrf-cookie', { withCredentials: true });
