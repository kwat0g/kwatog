import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useAuthStore } from '@/stores/authStore';
import { formDraftScopeFingerprint } from '@/lib/formDraftStorage';

// authStore mediates between the SPA and authApi. We don't hit the real
// HTTP layer; we mock @/api/auth and verify the store transitions state
// correctly on bootstrap success, login, logout, and bootstrap failure.

vi.mock('@/api/auth', () => ({
 authApi: {
 me: vi.fn(),
 login: vi.fn(),
 logout: vi.fn(),
 updatePreferences: vi.fn(),
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
vi.mock('@/sw-register', () => ({
 clearPwaCaches: vi.fn().mockResolvedValue(true),
}));

import { authApi } from '@/api/auth';
import { queryClient } from '@/lib/queryClient';
import { clearPwaCaches } from '@/sw-register';
import { useTablePrefsStore } from '@/stores/tablePrefsStore';

const fakeUser = {
 id: 'h_abc',
 email: 'u@t.test',
 name: 'Tester',
 role: { id: 'r_1', slug: 'system_admin', name: 'System Admin' },
 permissions: ['hr.employees.view', 'admin.users.manage'],
 features: ['hr', 'production'],
 theme_mode: 'light',
 table_preferences: { 'inventory.items': { density: 'compact' } },
} as unknown as Parameters<ReturnType<typeof useAuthStore.getState>['applyUser']>[0];

describe('stores/authStore', () => {
 beforeEach(() => {
 vi.clearAllMocks();
  localStorage.clear();
  sessionStorage.clear();
 useAuthStore.setState({
 user: null,
 permissions: new Set(),
 features: new Set(),
 isAuthenticated: false,
 isLoading: true,
 bootstrapError: null,
 retryAt: 0,
 });
 useTablePrefsStore.getState().clear();
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
 expect(useTablePrefsStore.getState().byTable['inventory.items']?.density).toBe('compact');
 });

 it('clears persisted retries on cold bootstrap when the access scope changed while the app was closed', () => {
  localStorage.setItem('ogami:auth-cache-scope:v1', JSON.stringify({ userId: fakeUser.id, scopeKey: 'previous-scope', writtenAt: Date.now() }));
  localStorage.setItem('ogami:delivery-attempt:delivery-1', JSON.stringify({ outcome: 'failed', reason: 'old scope' }));
  sessionStorage.setItem('ogami:pending-production:user-1:work-order-1', JSON.stringify({ key: 'retry-1', data: { quantity: 3 } }));

  useAuthStore.getState().applyUser({
   ...fakeUser,
   permissions: [...fakeUser.permissions, 'production.work_orders.manage'],
  });

  expect(localStorage.getItem('ogami:delivery-attempt:delivery-1')).toBeNull();
  expect(sessionStorage.getItem('ogami:pending-production:user-1:work-order-1')).toBeNull();
  const marker = JSON.parse(localStorage.getItem('ogami:auth-cache-scope:v1') ?? 'null');
  expect(marker.userId).toBe(fakeUser.id);
  expect(marker.scopeKey).not.toBe('previous-scope');
 });

 it('purges legacy persisted retries when no access-scope marker exists yet', () => {
  localStorage.setItem('ogami:delivery-attempt:delivery-legacy', JSON.stringify({ outcome: 'failed' }));
  localStorage.setItem('ogami:formdraft:delivery-submission:user-1', JSON.stringify({ key: 'retry-legacy', data: { notes: 'old scope' } }));

  useAuthStore.getState().applyUser(fakeUser);

  expect(localStorage.getItem('ogami:delivery-attempt:delivery-legacy')).toBeNull();
  expect(localStorage.getItem('ogami:formdraft:delivery-submission:user-1')).toBeNull();
  expect(localStorage.getItem('ogami:auth-cache-scope:v1')).not.toBeNull();
 });

 it('purges persisted retries when the auth-scope marker has expired', () => {
  localStorage.setItem('ogami:auth-cache-scope:v1', JSON.stringify({
   userId: fakeUser.id,
   scopeKey: formDraftScopeFingerprint(fakeUser),
   writtenAt: Date.now() - 31 * 24 * 60 * 60 * 1000,
  }));
  localStorage.setItem('ogami:delivery-attempt:delivery-expired', JSON.stringify({ outcome: 'failed' }));

  useAuthStore.getState().applyUser(fakeUser);

  expect(localStorage.getItem('ogami:delivery-attempt:delivery-expired')).toBeNull();
 });

 it('preserves same-scope retry state across a cold bootstrap', () => {
  const scopeKey = formDraftScopeFingerprint(fakeUser);
  localStorage.setItem('ogami:auth-cache-scope:v1', JSON.stringify({ userId: fakeUser.id, scopeKey, writtenAt: Date.now() }));
  localStorage.setItem('ogami:delivery-attempt:delivery-1', JSON.stringify({ outcome: 'failed' }));

  useAuthStore.getState().applyUser(fakeUser);

  expect(localStorage.getItem('ogami:delivery-attempt:delivery-1')).not.toBeNull();
 });

 it('clears permission-dependent query data before applying a same-user access change', () => {
 useAuthStore.getState().applyUser(fakeUser);
 vi.mocked(queryClient.clear).mockClear();

 useAuthStore.getState().applyUser({
 ...fakeUser,
 permissions: [...fakeUser.permissions, 'payroll.periods.view'],
 });

 expect(queryClient.clear).toHaveBeenCalledTimes(1);
 expect(useAuthStore.getState().permissions.has('payroll.periods.view')).toBe(true);
 });

 it('clears the prior account cache before exposing a different user after login', async () => {
  useAuthStore.getState().applyUser(fakeUser);
  vi.mocked(queryClient.clear).mockClear();
  const nextUser = {
   ...fakeUser,
   id: 'h_next',
   email: 'next@t.test',
  };
  let loginAccepted = false;
  vi.mocked(authApi.login).mockImplementationOnce(async () => {
   loginAccepted = true;
   return nextUser;
  });
  vi.mocked(queryClient.clear).mockImplementationOnce(() => {
   expect(loginAccepted).toBe(true);
   expect(useAuthStore.getState().user?.id).toBe(fakeUser.id);
  });

  await useAuthStore.getState().login({ email: nextUser.email, password: 'x' });

  expect(queryClient.clear).toHaveBeenCalledTimes(1);
  expect(useAuthStore.getState().user?.id).toBe(nextUser.id);
 });

 it('clears cached rows before exposing a same-user role change', () => {
  useAuthStore.getState().applyUser(fakeUser);
  vi.mocked(queryClient.clear).mockClear();
  const nextRoleUser = {
   ...fakeUser,
   role: { id: 'r_2', slug: 'finance_officer', name: 'Finance Officer' },
  };
  vi.mocked(queryClient.clear).mockImplementationOnce(() => {
   expect(useAuthStore.getState().user?.role?.slug).toBe(fakeUser.role.slug);
  });

  useAuthStore.getState().applyUser(nextRoleUser);

  expect(queryClient.clear).toHaveBeenCalledTimes(1);
  expect(useAuthStore.getState().user?.role?.slug).toBe('finance_officer');
 });

 it('bootstrap success populates state from authApi.me()', async () => {
 vi.mocked(authApi.me).mockResolvedValueOnce(fakeUser);
 await useAuthStore.getState().bootstrap();
 const s = useAuthStore.getState();
 expect(s.isAuthenticated).toBe(true);
 expect(s.isLoading).toBe(false);
 expect(useTablePrefsStore.getState().userId).toBe('h_abc');
 expect(s.permissions.has('admin.users.manage')).toBe(true);
 });

 it('bootstrap failure clears state and exits loading', async () => {
 vi.mocked(authApi.me).mockRejectedValueOnce({ isAxiosError: true, response: { status: 401 } });
 await useAuthStore.getState().bootstrap();
 const s = useAuthStore.getState();
 expect(s.user).toBeNull();
 expect(s.isAuthenticated).toBe(false);
 expect(s.permissions.size).toBe(0);
 expect(s.isLoading).toBe(false);
 expect(useTablePrefsStore.getState().userId).toBeNull();
 });

 it('retains identity but blocks protected content when bootstrap is throttled', async () => {
 useAuthStore.getState().applyUser(fakeUser);
 vi.mocked(queryClient.clear).mockClear();
 vi.mocked(authApi.me).mockRejectedValueOnce({ isAxiosError: true, response: { status: 429, headers: { 'retry-after': '30' } } });
 await useAuthStore.getState().bootstrap();
 expect(useAuthStore.getState().user).toBe(fakeUser);
 expect(useAuthStore.getState().bootstrapError).toMatch(/temporarily limited/);
 expect(useAuthStore.getState().retryAt).toBeGreaterThan(Date.now());
 expect(queryClient.clear).not.toHaveBeenCalled();
 });

 it('does not authenticate a guest after a temporary session-check failure', async () => {
 vi.mocked(authApi.me).mockRejectedValueOnce(new Error('Network Error'));
 await useAuthStore.getState().bootstrap();
 expect(useAuthStore.getState().isAuthenticated).toBe(false);
 expect(useAuthStore.getState().bootstrapError).toMatch(/Could not verify/);
 });

 it('logout clears state even if the network call fails', async () => {
 useAuthStore.getState().applyUser(fakeUser);
 expect(localStorage.getItem('ogami:auth-cache-scope:v1')).not.toBeNull();
 vi.mocked(authApi.logout).mockRejectedValueOnce(new Error('boom'));
 // Store rethrows after running the finally block — we only care that
 // local state is cleared regardless of the network outcome.
 await expect(useAuthStore.getState().logout()).rejects.toThrow('boom');
 const s = useAuthStore.getState();
 expect(s.user).toBeNull();
 expect(s.isAuthenticated).toBe(false);
 expect(s.permissions.size).toBe(0);
 expect(localStorage.getItem('ogami:auth-cache-scope:v1')).toBeNull();
 });

 it('logout clears the query cache', async () => {
 useAuthStore.getState().applyUser(fakeUser);
 vi.mocked(queryClient.clear).mockClear();
 vi.mocked(authApi.logout).mockResolvedValueOnce(undefined);
 await useAuthStore.getState().logout();
 expect(queryClient.clear).toHaveBeenCalledTimes(1);
 expect(useAuthStore.getState().isAuthenticated).toBe(false);
 });

 it('logout clears the persisted recovery operation ID from the shared tab session', async () => {
  const storageKey = 'ogami.recovery.operation_id';
  sessionStorage.setItem(storageKey, 'operation-from-prior-session');
  try {
   useAuthStore.getState().applyUser(fakeUser);
   vi.mocked(authApi.logout).mockResolvedValueOnce(undefined);

   await useAuthStore.getState().logout();

   expect(sessionStorage.getItem(storageKey)).toBeNull();
  } finally {
   sessionStorage.removeItem(storageKey);
  }
 });

 it('logout clears the app-owned service-worker caches', async () => {
 useAuthStore.getState().applyUser(fakeUser);
 vi.mocked(authApi.logout).mockResolvedValueOnce(undefined);

 await useAuthStore.getState().logout();

 expect(clearPwaCaches).toHaveBeenCalledTimes(1);
 });

 it('logout clears the query cache even if the network call fails', async () => {
 useAuthStore.getState().applyUser(fakeUser);
 vi.mocked(queryClient.clear).mockClear();
 vi.mocked(authApi.logout).mockRejectedValueOnce(new Error('boom'));
 await expect(useAuthStore.getState().logout()).rejects.toThrow('boom');
 expect(queryClient.clear).toHaveBeenCalledTimes(1);
 });

 it('login clears guest query data only after authentication succeeds', async () => {
 let authenticated = false;
 vi.mocked(authApi.login).mockImplementationOnce(async () => {
 await Promise.resolve();
 authenticated = true;
 return fakeUser;
 });
 vi.mocked(queryClient.clear).mockImplementationOnce(() => {
 expect(authenticated).toBe(true);
 });

 await useAuthStore.getState().login({ email: 'u@t.test', password: 'x' });
 expect(queryClient.clear).toHaveBeenCalledTimes(1);
 expect(useAuthStore.getState().isAuthenticated).toBe(true);
 });
});
