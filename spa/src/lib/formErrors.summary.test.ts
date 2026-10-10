import { describe, expect, it } from 'vitest';
import { summarizeServerErrors } from './formErrors';

describe('summarizeServerErrors', () => {
  it('turns "is prohibited" into plain words and names the field', () => {
    expect(
      summarizeServerErrors(
        { bank_name: ['The bank name field is prohibited.'] },
        { bank_name: 'Bank name' },
      ),
    ).toBe("Bank name can't be changed here.");
  });

  it('lists every error up to three, then counts the rest, instead of "(and 1 more error)"', () => {
    const text = summarizeServerErrors(
      {
        bank_name: ['The bank name field is prohibited.'],
        bank_account_no: ['The bank account no field is prohibited.'],
        email: ['The email has already been taken.'],
        city: ['The city field is required.'],
      },
      { bank_name: 'Bank name', bank_account_no: 'Account number' },
    );

    expect(text).toContain("• Bank name can't be changed here.");
    expect(text).toContain("• Account number can't be changed here.");
    expect(text).toContain('The email has already been taken.');
    expect(text).toContain('…and 1 more');
    expect(text).not.toMatch(/and 1 more error/);
  });

  it('falls back to a clear sentence for an empty error map', () => {
    expect(summarizeServerErrors({})).toMatch(/highlighted fields/i);
  });
});
