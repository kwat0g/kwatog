import axios from 'axios';
import { client } from './client';

export interface AuthRole {
  id: string;
  name: string;
  slug: string;
}

export interface AuthUser {
  id: string;
  name: string;
  email: string;
  is_active: boolean;
  must_change_password: boolean;
  theme_mode: 'light' | 'dark' | 'system';
  sidebar_collapsed: boolean;
  role: AuthRole;
  permissions: string[];
  features: string[];
}

export interface LoginPayload {
  email: string;
  password: string;
}

export interface ChangePasswordPayload {
  current_password: string;
  new_password: string;
  new_password_confirmation: string;
}

export interface PreferencesPayload {
  theme_mode?: 'light' | 'dark' | 'system';
  sidebar_collapsed?: boolean;
}

/**
 * Pre-flight CSRF cookie request — Axios will send the resulting XSRF-TOKEN
 * as `X-XSRF-TOKEN` on the next mutating call.
 */
export const getCsrfCookie = () =>
  axios.get('/sanctum/csrf-cookie', { withCredentials: true });

export const authApi = {
  csrf: getCsrfCookie,

  login: async (payload: LoginPayload) => {
    await getCsrfCookie();
    const { data } = await client.post<{ data: AuthUser }>('/auth/login', payload);
    return data.data;
  },

  logout: async () => {
    await client.post('/auth/logout');
  },

  me: async (): Promise<AuthUser> => {
    const { data } = await client.get<{ data: AuthUser }>('/auth/user');
    return data.data;
  },

  changePassword: async (payload: ChangePasswordPayload) => {
    const { data } = await client.post<{ message: string }>('/auth/change-password', payload);
    return data;
  },

  updatePreferences: async (payload: PreferencesPayload): Promise<AuthUser> => {
    const { data } = await client.patch<{ data: AuthUser }>('/auth/user/preferences', payload);
    return data.data;
  },
};
