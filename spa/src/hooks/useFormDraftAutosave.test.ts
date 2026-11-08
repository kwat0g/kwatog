import { renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { AuthUser } from '@/api/auth';
import { pruneExpiredFormDrafts } from '@/lib/formDraftStorage';
import { useAuthStore } from '@/stores/authStore';
import { __TEST_ONLY__, useFormDraftAutosave } from './useFormDraftAutosave';

const { stripSensitive, STORAGE_PREFIX } = __TEST_ONLY__;

afterEach(() => {
 if (typeof window !== 'undefined') {
 Object.keys(window.localStorage)
 .filter((k) => k.startsWith('ogami:formdraft:'))
 .forEach((k) => window.localStorage.removeItem(k));
 }
 useAuthStore.setState({
  user: null,
  permissions: new Set(),
  features: new Set(),
  isAuthenticated: false,
  isLoading: true,
  bootstrapError: null,
  retryAt: 0,
 });
});

function makeDraftUser(roleId: string, slug: string, permission: string): AuthUser {
 return {
  id: 'user-1',
  name: 'Draft User',
  email: 'draft@example.test',
  is_active: true,
  must_change_password: false,
  theme_mode: 'light',
  sidebar_collapsed: false,
  role: { id: roleId, name: slug, slug },
  employee: null,
  permissions: [permission],
  delegated_approvals: [],
  features: ['quality'],
 };
}

describe('stripSensitive', () => {
 it('drops fields matching default sensitive patterns', () => {
 const out = stripSensitive({
 first_name: 'Juan',
 last_name: 'Cruz',
 sss_no: '12-3456789-0',
 tin: '123-456-789',
 philhealth_no: '00-000000000-0',
 pagibig_no: '0000-0000-0000',
 bank_account_no: '0001234567',
 basic_monthly_salary: '486500',
 semi_monthly_rate: '7150',
 password: 'secret',
 email: 'juan@ogami.ph',
 });

 expect(out.first_name).toBe('Juan');
 expect(out.last_name).toBe('Cruz');
 expect(out.email).toBe('juan@ogami.ph');

 // None of these may ever appear in the persisted draft.
 expect(out).not.toHaveProperty('sss_no');
 expect(out).not.toHaveProperty('tin');
 expect(out).not.toHaveProperty('philhealth_no');
 expect(out).not.toHaveProperty('pagibig_no');
 expect(out).not.toHaveProperty('bank_account_no');
 expect(out).not.toHaveProperty('basic_monthly_salary');
 expect(out).not.toHaveProperty('semi_monthly_rate');
 expect(out).not.toHaveProperty('password');
 });

 it('drops nested sensitive fields', () => {
 const out = stripSensitive({
 employee: {
 first_name: 'Juan',
 sss_no: '12-3456789-0',
 },
 });
 expect((out.employee as Record<string, unknown>).first_name).toBe('Juan');
 expect(out.employee).not.toHaveProperty('sss_no');
 });

 it('respects extra blocklist entries', () => {
 const out = stripSensitive(
 { first_name: 'Juan', custom_secret: 'shh' },
 ['custom_secret'],
 );
 expect(out.first_name).toBe('Juan');
 expect(out).not.toHaveProperty('custom_secret');
 });
});

it('never recreates a draft on unmount after a successful save disables recovery', () => {
 useAuthStore.getState().applyUser(makeDraftUser('role-qc', 'quality_manager', 'quality.inspections.manage'));
 const options = { formKey: 'inspection:1', userId: 'user-1', getValues: () => ({ value: '10.2' }), setValues: () => {}, enabled: true };
 const hook = renderHook(props => useFormDraftAutosave(props), { initialProps: options });
 hook.result.current.saveNow();
 const persistedKey = Object.keys(localStorage).find((key) => key.startsWith(`${STORAGE_PREFIX}user-1:scope-`));
 expect(persistedKey).toBeDefined();
 expect(localStorage.getItem(persistedKey!)).not.toBeNull();
 hook.result.current.discard();
 hook.rerender({ ...options, enabled: false });
 hook.unmount();
 expect(localStorage.getItem(persistedKey!)).toBeNull();
});

it('removes scoped drafts and retry payloads when the same user returns under a different access scope', () => {
 const originalUser = makeDraftUser('role-qc', 'quality_manager', 'quality.inspections.manage');
 useAuthStore.getState().applyUser(originalUser);

 const original = renderHook(() => useFormDraftAutosave({
  formKey: 'inspection:1',
  userId: originalUser.id,
  getValues: () => ({ remarks: 'Restricted draft from old access' }),
  setValues: () => {},
 }));
 original.result.current.saveNow();
 original.unmount();

 const persistedKey = Object.keys(localStorage).find((key) => key.startsWith(STORAGE_PREFIX));
 expect(persistedKey).toBeDefined();
 const pendingJournalKey = 'ogami:formdraft:delivery-submission:user-1';
 localStorage.setItem(pendingJournalKey, JSON.stringify({ key: 'delivery-once', data: { notes: 'ambiguous request' } }));

 // A full reload starts with no prior in-memory identity, so applyUser must
 // still retire this account's old-scope draft before the form can read it.
 useAuthStore.setState({
  user: null,
  permissions: new Set(),
  features: new Set(),
  isAuthenticated: false,
 });
 useAuthStore.getState().applyUser(
  makeDraftUser('role-production', 'production_operator', 'production.work_orders.view'),
 );

 expect(localStorage.getItem(persistedKey!)).toBeNull();
 expect(localStorage.getItem(pendingJournalKey)).toBeNull();

 const current = renderHook(() => useFormDraftAutosave({
  formKey: 'inspection:1',
  userId: 'user-1',
  getValues: () => ({}),
  setValues: () => {},
 }));
 expect(current.result.current.hasDraft).toBe(false);
 current.unmount();
});

it('purges recognized drafts from the legacy unscoped cache namespace at startup', () => {
 const legacyKey = 'ogami:formdraft:route:purchase-orders:create';
 const pendingJournalKey = 'ogami:formdraft:delivery-submission:user-1';
 localStorage.setItem(legacyKey, JSON.stringify({ ts: Date.now(), values: { supplier: 'old draft' } }));
 localStorage.setItem(pendingJournalKey, JSON.stringify({ key: 'delivery-once', data: { notes: 'ambiguous request' } }));

 pruneExpiredFormDrafts();

 expect(localStorage.getItem(legacyKey)).toBeNull();
 expect(localStorage.getItem(pendingJournalKey)).not.toBeNull();
});
