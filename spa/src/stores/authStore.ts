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
