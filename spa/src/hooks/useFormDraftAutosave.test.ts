import { renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { __TEST_ONLY__, useFormDraftAutosave } from './useFormDraftAutosave';

const { stripSensitive, STORAGE_PREFIX } = __TEST_ONLY__;

afterEach(() => {
 if (typeof window !== 'undefined') {
 Object.keys(window.localStorage)
 .filter((k) => k.startsWith(STORAGE_PREFIX))
 .forEach((k) => window.localStorage.removeItem(k));
 }
});

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
 const options = { formKey: 'inspection:1', userId: 'user-1', getValues: () => ({ value: '10.2' }), setValues: () => {}, enabled: true };
 const hook = renderHook(props => useFormDraftAutosave(props), { initialProps: options });
 hook.result.current.saveNow();
 expect(localStorage.getItem(`${STORAGE_PREFIX}user-1:inspection:1`)).not.toBeNull();
 hook.result.current.discard();
 hook.rerender({ ...options, enabled: false });
 hook.unmount();
 expect(localStorage.getItem(`${STORAGE_PREFIX}user-1:inspection:1`)).toBeNull();
});
