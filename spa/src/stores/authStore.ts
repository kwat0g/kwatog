import { create } from 'zustand';
import { isAxiosError } from 'axios';
import { authApi, type AuthUser, type LoginPayload } from '@/api/auth';
import { queryClient } from '@/lib/queryClient';
import { useThemeStore } from './themeStore';
import { useTablePrefsStore } from './tablePrefsStore';
import { setFunctionalCurrency } from '@/lib/runtimeCurrency';
import { clearPwaCaches } from '@/sw-register';
import { clearStaleFormDraftScopes, formDraftScopeFingerprint } from '@/lib/formDraftStorage';
import {
  clearAuthSessionDrafts,
  publishAuthSessionSignal,
  reconcileAuthSessionCacheScope,
  serializeAuthSessionMutation,
  subscribeAuthSessionSignal,
} from '@/lib/authSessionSync';

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

function sameValues(left: readonly string[], right: readonly string[]): boolean {
  return left.length === right.length && left.every((value) => right.includes(value));
}

let authRefreshInFlight: Promise<void> | null = null;
let authRefreshInFlightGeneration: number | null = null;
let authRefreshQueued = false;
let authSessionGeneration = 0;
let unsubscribeAuthSessionSignal: (() => void) | null = null;

export const useAuthStore = create<AuthState>((set, get) => ({
  user: null,
  permissions: new Set(),
  features: new Set(),
  isAuthenticated: false,
  isLoading: true,
  bootstrapError: null,
  retryAt: 0,

  bootstrap: async () => {
    installAuthSessionSync();
    authSessionGeneration += 1;
    authRefreshQueued = false;
    const requestGeneration = authSessionGeneration;
    set({ isLoading: true, bootstrapError: null });
    try {
      const user = await authApi.me();
      if (requestGeneration !== authSessionGeneration) return;
      get().applyUser(user);
    } catch (error) {
      if (requestGeneration !== authSessionGeneration) return;
      const status = isAxiosError(error) ? error.response?.status : undefined;
      if (status === 401 || status === 419) {
        authSessionGeneration += 1;
        authRefreshQueued = false;
        setFunctionalCurrency(null);
        queryClient.clear();
        clearAuthSessionDrafts();
        void clearPwaCaches();
        useThemeStore.getState().init('light');
        useTablePrefsStore.getState().clear();
        set({
          user: null,
          permissions: new Set(),
          features: new Set(),
          isAuthenticated: false,
          isLoading: false,
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
      if (requestGeneration === authSessionGeneration) set({ isLoading: false });
    }
  },

  login: async (creds) => {
    installAuthSessionSync();
    const loginGeneration = ++authSessionGeneration;
    authRefreshQueued = false;
    publishAuthSessionSignal('internal', 'changing');
    // A fresh login must not restore app-owned data from an earlier terminal session.
    clearAuthSessionDrafts();
    // applyUser clears the old identity's query cache after credentials are
    // accepted and before the new identity is exposed to the UI.
    try {
      const user = await serializeAuthSessionMutation(() => {
        if (loginGeneration !== authSessionGeneration) {
          throw new Error('This sign-in was superseded by a newer session change.');
        }
        return authApi.login(creds);
      });
      if (loginGeneration !== authSessionGeneration) {
        throw new Error('This sign-in was superseded by a newer session change.');
      }
      get().applyUser(user);
      return user;
    } finally {
      publishAuthSessionSignal('internal', 'changed');
    }
  },

  logout: async () => {
    installAuthSessionSync();

    // Clear the old identity synchronously. On shared shop-floor terminals,
    // waiting for the logout request would leave its scoped records visible.
    clearLocalAuthSession();
    // Laravel's logout endpoint invalidates the shared session container, so
    // sibling portal guards must also discard their local caches.
    publishAuthSessionSignal('all', 'changing');

    // This cache contains only the app shell. Let cleanup finish independently
    // so it cannot delay session clearing or a subsequent sign-in.
    const pwaCacheCleanup = clearPwaCaches();
    try {
      await serializeAuthSessionMutation(() => authApi.logout());
    } finally {
      // Let peer guards revalidate as soon as the server mutation settles;
      // app-shell cache deletion must not hold up API freshness.
      publishAuthSessionSignal('all', 'changed');
      const clearedPwaCaches = await pwaCacheCleanup;
      if (!clearedPwaCaches) {
        console.warn('Could not clear all Ogami service-worker caches during logout.');
      }
    }
  },

  refresh: async () => {
    const currentGeneration = authSessionGeneration;
    if (authRefreshInFlight) {
      if (authRefreshInFlightGeneration !== currentGeneration) {
        const previousRefresh = authRefreshInFlight;

        return previousRefresh.catch(() => undefined).then(() => {
          if (currentGeneration !== authSessionGeneration || !get().isAuthenticated) return;
          return get().refresh();
        });
      }

      // A newer event/focus request means the current response may have been
      // fetched before the state change. Discard it and fetch once more.
      authRefreshQueued = true;

      return authRefreshInFlight;
    }

    const requestGeneration = currentGeneration;
    const refreshTask = (async () => {
      do {
        authRefreshQueued = false;

        try {
          const user = await authApi.me();
          if (requestGeneration !== authSessionGeneration || !get().isAuthenticated) {
            authRefreshQueued = false;
            return;
          }
          if (authRefreshQueued) continue;

          get().applyUser(user);
        } catch (error) {
          const status = isAxiosError(error) ? error.response?.status : undefined;
          if ((status === 401 || status === 419) && requestGeneration === authSessionGeneration) {
            // A revoked/expired session must not keep the previous user's role,
            // permissions or cached records visible on a shared terminal.
            await get().logout().catch(() => undefined);
          }
          throw error;
        }
      } while (authRefreshQueued && requestGeneration === authSessionGeneration && get().isAuthenticated);
    })();
    authRefreshInFlight = refreshTask;
    authRefreshInFlightGeneration = requestGeneration;

    try {
      await refreshTask;
    } finally {
      if (authRefreshInFlight === refreshTask) {
        authRefreshInFlight = null;
        authRefreshInFlightGeneration = null;
      }
    }
  },

  applyUser: (user) => {
    installAuthSessionSync();
    // Invalidates any older /me response, including bootstrap/refresh races.
    authSessionGeneration += 1;
    authRefreshQueued = false;

    const current = get().user;
    const accessChanged =
      current?.id !== user.id ||
      current?.role?.id !== user.role?.id ||
      current?.role?.slug !== user.role?.slug ||
      current?.employee?.id !== user.employee?.id ||
      current?.employee?.department_id !== user.employee?.department_id ||
      current?.is_active !== user.is_active ||
      !sameValues(current?.permissions ?? [], user.permissions) ||
      !sameValues(current?.delegated_approvals ?? [], user.delegated_approvals) ||
      !sameValues(current?.features ?? [], user.features);

    // Query keys in older screens do not all carry the authorization scope.
    // Clear before publishing a changed user/role/employee/delegation set so
    // terminals and same-user scope changes cannot display prior API rows.
    if (accessChanged) {
      queryClient.clear();
      // Preserve same-user drafts on first bootstrap after a page reload, but
      // discard them when an already-resolved account or access scope changes.
      if (current !== null) clearAuthSessionDrafts();
    }
    const accessScope = formDraftScopeFingerprint(user);
    reconcileAuthSessionCacheScope(user.id, accessScope);
    clearStaleFormDraftScopes(user.id, accessScope);

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
    useTablePrefsStore.getState().hydrate(user.id, user.table_preferences ?? {});
  },
}));

/** Clear internal guard state after an API path invalidates the shared session. */
export function clearLocalAuthSession(): void {
  // A response started under the old session must not restore it after a
  // logout/password-change signal clears this tab's identity.
  authSessionGeneration += 1;
  authRefreshQueued = false;
  setFunctionalCurrency(null);
  useAuthStore.setState({
    user: null,
    permissions: new Set(),
    features: new Set(),
    isAuthenticated: false,
    isLoading: false,
    bootstrapError: null,
    retryAt: 0,
  });
  queryClient.clear();
  clearAuthSessionDrafts();
  useThemeStore.getState().init('light');
  useTablePrefsStore.getState().clear();
}

function installAuthSessionSync(): void {
  if (unsubscribeAuthSessionSignal || typeof window === 'undefined') return;
  unsubscribeAuthSessionSignal = subscribeAuthSessionSignal((signal) => {
    if (signal.realm !== 'internal' && signal.realm !== 'all') return;

    // Internal query data is tab-local, but the signal carries no identity.
    queryClient.clear();
    void clearPwaCaches();

    if (signal.stage === 'changing') {
      clearLocalAuthSession();
      // An unknown sign-in realm can change this tab's cookie-backed identity.
      if (signal.realm === 'all') useAuthStore.setState({ isLoading: true });
      return;
    }

    if (signal.stage === 'changed') {
      clearLocalAuthSession();
      void useAuthStore.getState().bootstrap();
    }
  });
}
