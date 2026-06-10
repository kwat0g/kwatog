import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useAuthStore } from '@/stores/authStore';

// authStore mediates between the SPA and authApi. We don't hit the real
// HTTP layer; we mock @/api/auth and verify the store transitions state
// correctly on bootstrap success, login, logout, and bootstrap failure.

vi.mock('@/api/auth', () => ({
  authApi: {
    me: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
  },
}));
// Theme init is a side-effect we don't care about here.
vi.mock('@/stores/themeStore', () => ({
  useThemeStore: {
    getState: () => ({ init: vi.fn() }),
  },
}));
// Shared query client — logout must wipe the cache so the next user on a
// shared terminal never sees the previous user's cached data.
vi.mock('@/lib/queryClient', () => ({
  queryClient: {
    clear: vi.fn(),
  },
}));

import { authApi } from '@/api/auth';
import { queryClient } from '@/lib/queryClient';

const fakeUser = {
  id: 'h_abc',
  email: 'u@t.test',
  name: 'Tester',
  role: { id: 'r_1', slug: 'system_admin', name: 'System Admin' },
  permissions: ['hr.employees.view', 'admin.users.manage'],
  features: ['hr', 'production'],
  theme_mode: 'light',
} as unknown as Parameters<ReturnType<typeof useAuthStore.getState>['applyUser']>[0];

describe('stores/authStore', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useAuthStore.setState({
      user: null,
      permissions: new Set(),
      features: new Set(),
      isAuthenticated: false,
      isLoading: true,
    });
  });

  it('starts unauthenticated and loading', () => {
    const s = useAuthStore.getState();
    expect(s.user).toBeNull();
    expect(s.isAuthenticated).toBe(false);
    expect(s.isLoading).toBe(true);
  });

  it('applyUser hydrates user + permissions + features as Sets', () => {
    useAuthStore.getState().applyUser(fakeUser);
    const s = useAuthStore.getState();
    expect(s.isAuthenticated).toBe(true);
    expect(s.isLoading).toBe(false);
    expect(s.user?.email).toBe('u@t.test');
    expect(s.permissions.has('hr.employees.view')).toBe(true);
    expect(s.permissions.has('does.not.exist')).toBe(false);
    expect(s.features.has('hr')).toBe(true);
  });

  it('bootstrap success populates state from authApi.me()', async () => {
    vi.mocked(authApi.me).mockResolvedValueOnce(fakeUser);
    await useAuthStore.getState().bootstrap();
    const s = useAuthStore.getState();
    expect(s.isAuthenticated).toBe(true);
    expect(s.isLoading).toBe(false);
    expect(s.permissions.has('admin.users.manage')).toBe(true);
  });

  it('bootstrap failure clears state and exits loading', async () => {
    vi.mocked(authApi.me).mockRejectedValueOnce(new Error('401'));
    await useAuthStore.getState().bootstrap();
    const s = useAuthStore.getState();
    expect(s.user).toBeNull();
    expect(s.isAuthenticated).toBe(false);
    expect(s.permissions.size).toBe(0);
    expect(s.isLoading).toBe(false);
  });

  it('logout clears state even if the network call fails', async () => {
    useAuthStore.getState().applyUser(fakeUser);
    vi.mocked(authApi.logout).mockRejectedValueOnce(new Error('boom'));
    // Store rethrows after running the finally block — we only care that
    // local state is cleared regardless of the network outcome.
    await expect(useAuthStore.getState().logout()).rejects.toThrow('boom');
    const s = useAuthStore.getState();
    expect(s.user).toBeNull();
    expect(s.isAuthenticated).toBe(false);
    expect(s.permissions.size).toBe(0);
  });

  it('logout clears the query cache', async () => {
    useAuthStore.getState().applyUser(fakeUser);
    vi.mocked(authApi.logout).mockResolvedValueOnce(undefined);
    await useAuthStore.getState().logout();
    expect(queryClient.clear).toHaveBeenCalledTimes(1);
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
  });

  it('logout clears the query cache even if the network call fails', async () => {
    useAuthStore.getState().applyUser(fakeUser);
    vi.mocked(authApi.logout).mockRejectedValueOnce(new Error('boom'));
    await expect(useAuthStore.getState().logout()).rejects.toThrow('boom');
    expect(queryClient.clear).toHaveBeenCalledTimes(1);
  });

  it('login clears the query cache before authenticating', async () => {
    vi.mocked(authApi.login).mockResolvedValueOnce(fakeUser);
    await useAuthStore.getState().login({ email: 'u@t.test', password: 'x' });
    expect(queryClient.clear).toHaveBeenCalledTimes(1);
    expect(vi.mocked(queryClient.clear).mock.invocationCallOrder[0]).toBeLessThan(
      vi.mocked(authApi.login).mock.invocationCallOrder[0],
    );
    expect(useAuthStore.getState().isAuthenticated).toBe(true);
  });
});
