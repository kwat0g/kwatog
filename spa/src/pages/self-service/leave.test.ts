import { describe, expect, it } from 'vitest';
import { businessDaysBetween } from './leave';

describe('businessDaysBetween', () => {
  it('excludes recurring or configured public holidays from the displayed estimate', () => {
    expect(
      businessDaysBetween('2027-04-08', '2027-04-11', new Set(['2027-04-09'])),
    ).toBe(2);
  });
});
