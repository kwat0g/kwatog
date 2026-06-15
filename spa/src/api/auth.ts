import axios from 'axios';
import { unwrappingClient as client } from './client';

export interface AuthRole {
  id: string;
  name: string;
  slug: string;
}

export interface AuthEmployee {
  id: string;
  employee_no: string;
  full_name: string;
  department_id: string | null;
}

export interface AuthUser {
  id: string;
  name: string;
  email: string;
  is_active: boolean;
  is_superuser?: boolean;
  must_change_password: boolean;
  theme_mode: 'light' | 'dark' | 'system';
  sidebar_collapsed: boolean;
  role: AuthRole;
  /** Linked HR employee, when this user account is wired to one. */
  employee: AuthEmployee | null;
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
    return (await client.post<AuthUser>('/auth/login', payload)).data;
  },

  logout: async () => {
    await client.post('/auth/logout');
  },

  me: async (): Promise<AuthUser> => {
    return (await client.get<AuthUser>('/auth/user')).data;
  },

  changePassword: async (payload: ChangePasswordPayload) => {
    const { data } = await client.post<{ message: string }>('/auth/change-password', payload);
    return data;
  },

  updatePreferences: async (payload: PreferencesPayload): Promise<AuthUser> => {
    return (await client.patch<AuthUser>('/auth/user/preferences', payload)).data;
  },
};
