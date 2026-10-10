import { create } from 'zustand';
import { isAxiosError } from 'axios';
import { authApi, type AuthUser, type LoginPayload } from '@/api/auth';
import { queryClient } from '@/lib/queryClient';
import { useThemeStore } from './themeStore';
import { setFunctionalCurrency } from '@/lib/runtimeCurrency';

interface AuthState {
  user: AuthUser | null;
  permissions: Set<string>;
  features: Set<string>;
  isAuthenticated: boolean;
  isLoading: boolean;
  bootstrapError: string | null;
  retryAt: number;
  bootstrap: () => Promise<void>;
  login: (creds: LoginPayload) => Promise<AuthUser>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
  applyUser: (user: AuthUser) => void;
}

export const useAuthStore = create<AuthState>((set, get) => ({
  user: null,
  permissions: new Set(),
  features: new Set(),
  isAuthenticated: false,
  isLoading: true,
  bootstrapError: null,
  retryAt: 0,

  bootstrap: async () => {
    set({ isLoading: true, bootstrapError: null });
    try {
      const user = await authApi.me();
      get().applyUser(user);
    } catch (error) {
      const status = isAxiosError(error) ? error.response?.status : undefined;
      if (status === 401 || status === 419) {
        setFunctionalCurrency(null);
        queryClient.clear();
        set({
          user: null,
          permissions: new Set(),
          features: new Set(),
          isAuthenticated: false,
          bootstrapError: null,
          retryAt: 0,
        });
      } else {
        const raw = isAxiosError(error) ? error.response?.headers?.['retry-after'] : undefined;
        const seconds = Number(raw);
        const retryAt =
          raw && Number.isFinite(seconds)
            ? Date.now() + Math.max(0, seconds) * 1000
            : raw
              ? Date.parse(String(raw))
              : 0;
        set({
          bootstrapError:
            status === 429
              ? 'Session check temporarily limited. Wait before retrying.'
              : 'Could not verify your session. Check your connection and retry.',
          retryAt: Number.isFinite(retryAt) ? retryAt : 0,
        });
      }
    } finally {
      set({ isLoading: false });
    }
  },

  login: async (creds) => {
    // Clear stale identity data only after credentials are accepted. Clearing
    // during guest requests can race Sanctum session rotation.
    const user = await authApi.login(creds);
    queryClient.clear();
    get().applyUser(user);
    return user;
  },

  logout: async () => {
    try {
      await authApi.logout();
    } finally {
      setFunctionalCurrency(null);
      set({
        user: null,
        permissions: new Set(),
        features: new Set(),
        isAuthenticated: false,
        bootstrapError: null,
        retryAt: 0,
      });
      // Wipe all cached query data so the next user on this terminal can
      // never see the previous user's lists (payroll, employees, etc.).
      queryClient.clear();
      // Clear form drafts from localStorage to prevent data leaking to next user.
      Object.keys(localStorage)
        .filter((k) => k.startsWith('ogami:formdraft:'))
        .forEach((k) => localStorage.removeItem(k));
      // Drop the prior user's theme preference. Public/auth pages are light-only,
      // so reset <html data-theme> to light instead of leaking a dark session.
      useThemeStore.getState().init('light');
    }
  },

  refresh: async () => {
    const user = await authApi.me();
    get().applyUser(user);
  },

  applyUser: (user) => {
    set({
      user,
      permissions: new Set(user.permissions),
      features: new Set(user.features),
      isAuthenticated: true,
      isLoading: false,
      bootstrapError: null,
      retryAt: 0,
    });
    // Apply server-side preferences without triggering a reverse PATCH back to the API.
    useThemeStore.getState().init(user.theme_mode);
  },
}));
