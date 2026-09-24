import axios from 'axios';

export type PortalRealm = 'supplier' | 'customer';

export const createPortalClient = (realm: PortalRealm = 'supplier') => {
  const client = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  timeout: 30_000,
  headers: {
   Accept: 'application/json',
   'X-Requested-With': 'XMLHttpRequest',
  },
  });

  client.interceptors.response.use((response) => response, (error: unknown) => {
    if (axios.isAxiosError(error)
      && error.response?.status === 403
      && error.response.data?.code === 'password_expired'
      && typeof window !== 'undefined') {
      const changePasswordPath = `/portal/${realm}/change-password`;
      if (window.location.pathname !== changePasswordPath) {
        window.location.assign(changePasswordPath);
      }
    }

    return Promise.reject(error);
  });

  return { client };
};

export const getPortalCsrf = () =>
 axios.get('/sanctum/csrf-cookie', { withCredentials: true, timeout: 30_000 });
