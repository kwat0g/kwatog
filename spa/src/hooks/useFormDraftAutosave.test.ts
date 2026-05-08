import { afterEach, describe, expect, it } from 'vitest';
import { __TEST_ONLY__ } from './useFormDraftAutosave';

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
      daily_rate: '650',
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
    expect(out).not.toHaveProperty('daily_rate');
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
