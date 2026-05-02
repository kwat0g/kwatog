import axios, { AxiosError } from 'axios';
import toast from 'react-hot-toast';

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
  (error: AxiosError<{ message?: string; code?: string; module?: string }>) => {
    const status = error.response?.status;
    const data = error.response?.data;

    const requestUrl = error.config?.url ?? '';
    const isBootstrap = requestUrl.endsWith('/auth/user');
    const isLoginAttempt = requestUrl.endsWith('/auth/login');

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
        } else {
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
        toast.error('Something went wrong. Please try again.');
        break;

      default:
        if (!error.response) {
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
