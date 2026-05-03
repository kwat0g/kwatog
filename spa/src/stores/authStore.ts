import { create } from 'zustand';
import { authApi, type AuthUser, type LoginPayload } from '@/api/auth';
import { useThemeStore } from './themeStore';
import { useSidebarStore } from './sidebarStore';

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
    useSidebarStore.getState().init(user.sidebar_collapsed);
  },
}));
