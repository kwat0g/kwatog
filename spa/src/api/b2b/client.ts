import axios, { type InternalAxiosRequestConfig } from 'axios';
import { queryClient } from '@/lib/queryClient';

export type PortalRealm = 'supplier' | 'customer';

type PortalRequestConfig = InternalAxiosRequestConfig & { _sessionRetried?: boolean };

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

  client.interceptors.response.use((response) => response, async (error: unknown) => {
    if (axios.isAxiosError(error) && error.response?.status === 401) {
      const request = error.config as PortalRequestConfig | undefined;
      const url = request?.url ?? '';
      const isLogin = url.endsWith(`/b2b/${realm}/login`);
      const isSessionBootstrap = url.endsWith(`/b2b/${realm}/me`);

      if (!isLogin && !isSessionBootstrap) {
        if (request && !request._sessionRetried) {
          request._sessionRetried = true;
          try {
            await client.get(`/b2b/${realm}/me`);
            return client.request(request);
          } catch (probeError) {
            if (axios.isAxiosError(probeError) && probeError.response?.status !== 401) {
              return Promise.reject(error);
            }
          }
        }

        if (typeof window !== 'undefined' && window.location.pathname !== `/portal/${realm}/login`) {
          queryClient.clear();
          const portalPath = `/portal/${realm}`;
          const from = window.location.pathname === portalPath || window.location.pathname.startsWith(`${portalPath}/`)
            ? window.location.pathname
            : portalPath;
          window.location.assign(`/portal/${realm}/login?from=${encodeURIComponent(from)}`);
        }
      }
    }

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
