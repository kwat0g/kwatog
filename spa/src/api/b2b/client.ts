import axios from 'axios';

export const createPortalClient = (storageKey?: string) => {
  const client = axios.create({
    baseURL: '/api/v1',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
  });

  const setToken = (token: string | null) => {
    if (token) {
      client.defaults.headers.common.Authorization = `Bearer ${token}`;
    } else {
      delete client.defaults.headers.common.Authorization;
    }

    if (storageKey && typeof window !== 'undefined') {
      try {
        if (token) window.sessionStorage.setItem(storageKey, token);
        else window.sessionStorage.removeItem(storageKey);
      } catch {
        // Storage can be unavailable in privacy-restricted contexts. The
        // in-memory token still supports the current portal session.
      }
    }
  };

  if (storageKey && typeof window !== 'undefined') {
    try {
      setToken(window.sessionStorage.getItem(storageKey));
    } catch {
      // Continue without persistence when sessionStorage is unavailable.
    }
  }

  return { client, setToken };
};

export const getPortalCsrf = () =>
  axios.get('/sanctum/csrf-cookie', { withCredentials: true });
