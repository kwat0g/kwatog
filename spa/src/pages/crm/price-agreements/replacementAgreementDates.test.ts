import { describe, expect, it } from 'vitest';
import { nextReplacementStartDate, replacementEndDate } from './replacementAgreementDates';

describe('replacement agreement dates', () => {
  it('starts after a future agreement start date', () => {
    expect(nextReplacementStartDate('2026-11-12', '2026-11-11')).toBe('2026-11-13');
  });

  it('starts after today when the agreement is already active', () => {
    expect(nextReplacementStartDate('2026-01-01', '2026-11-11')).toBe('2026-11-12');
  });

  it('keeps a valid existing end date and clears an expired one', () => {
    expect(replacementEndDate('2027-11-12', '2026-11-13')).toBe('2027-11-12');
    expect(replacementEndDate('2026-11-12', '2026-11-13')).toBe('');
  });
});
