import axios from 'axios';

/**
 * Separate Axios instance for B2B portal API calls.
 * Uses cookie-based auth (Sanctum) just like the main app,
 * but hits a separate guard so B2B sessions don't collide
 * with internal ERP sessions on the same browser.
 */
export const portalClient = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

export const getPortalCsrf = () =>
  axios.get('/sanctum/csrf-cookie', { withCredentials: true });
