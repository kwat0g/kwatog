import { describe, expect, it } from 'vitest';
import { implicitOpenCurrentPeriod } from './implicitOpenCurrentPeriod';

describe('accounting period bootstrap action', () => {
 it('builds a bounded current-month open period for the existing close API', () => {
  const period = implicitOpenCurrentPeriod(new Date(2026, 7, 13));

  expect(period).toMatchObject({
   id: 'implicit-2026-8',
   year: 2026,
   month: 8,
   status: 'open',
  });
  expect(period.month).toBeGreaterThanOrEqual(1);
  expect(period.month).toBeLessThanOrEqual(12);
  expect(period.year).toBeGreaterThanOrEqual(2000);
  expect(period.year).toBeLessThanOrEqual(2100);
 });
});
