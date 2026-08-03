import { getCsrfCookie, unwrappingClient as client } from './client';

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
  remember?: boolean;
}

export interface ChangePasswordPayload {
  current_password: string;
  new_password: string;
  new_password_confirmation: string;
}
export interface PasswordPolicy { minimum_length: number; requires_uppercase: boolean; requires_lowercase: boolean; requires_digit: boolean; requires_special: boolean }

export interface PreferencesPayload {
  theme_mode?: 'light' | 'dark' | 'system';
  sidebar_collapsed?: boolean;
}

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
  passwordPolicy: async (): Promise<PasswordPolicy> => (await client.get<PasswordPolicy>('/auth/password-policy')).data,

  changePassword: async (payload: ChangePasswordPayload) => {
    const { data } = await client.post<{ message: string }>('/auth/change-password', payload);
    return data;
  },

  /**
   * Request a password reset email.
   */
  requestPasswordReset: async (email: string): Promise<{ message: string }> => {
    await getCsrfCookie();
    const { data } = await client.post<{ message: string }>('/auth/forgot-password', { email });
    return data;
  },

  /**
   * Reset password with a token from the email link.
   */
  resetPassword: async (payload: {
    token: string;
    password: string;
    password_confirmation: string;
  }): Promise<{ message: string }> => {
    await getCsrfCookie();
    const { data } = await client.post<{ message: string }>('/auth/reset-password', payload);
    return data;
  },

  updatePreferences: async (payload: PreferencesPayload): Promise<AuthUser> => {
    return (await client.patch<AuthUser>('/auth/user/preferences', payload)).data;
  },
};
