import { create } from 'zustand';
import { authApi, type AuthUser, type LoginPayload } from '@/api/auth';
import { queryClient } from '@/lib/queryClient';
import { useThemeStore } from './themeStore';

interface AuthState {
  user: AuthUser | null;
  permissions: Set<string>;
  features: Set<string>;
  isAuthenticated: boolean;
  isLoading: boolean;
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

  bootstrap: async () => {
    set({ isLoading: true });
    try {
      const user = await authApi.me();
      get().applyUser(user);
    } catch {
      set({ user: null, permissions: new Set(), features: new Set(), isAuthenticated: false });
    } finally {
      set({ isLoading: false });
    }
  },

  login: async (creds) => {
    // A new identity may take over without a page reload (e.g. previous
    // session expired and AuthGuard soft-navigated to /login) — drop any
    // cache left behind by the prior user before authenticating.
    queryClient.clear();
    const user = await authApi.login(creds);
    get().applyUser(user);
    return user;
  },

  logout: async () => {
    try {
      await authApi.logout();
    } finally {
      set({ user: null, permissions: new Set(), features: new Set(), isAuthenticated: false });
      // Wipe all cached query data so the next user on this terminal can
      // never see the previous user's lists (payroll, employees, etc.).
      queryClient.clear();
      // Clear form drafts from localStorage to prevent data leaking to next user.
      Object.keys(localStorage)
        .filter(k => k.startsWith('ogami:formdraft:'))
        .forEach(k => localStorage.removeItem(k));
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
    });
    // Apply server-side preferences without triggering a reverse PATCH back to the API.
    useThemeStore.getState().init(user.theme_mode);
  },
}));
