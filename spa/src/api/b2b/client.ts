import axios from 'axios';

export const createPortalClient = () => {
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
  };

  return { client, setToken };
};

export const getPortalCsrf = () =>
  axios.get('/sanctum/csrf-cookie', { withCredentials: true });
