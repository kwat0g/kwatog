import { describe, expect, it } from 'vitest';
import { fromCentavos, toCentavos } from '@/lib/money';

/**
 * These cases are the reason the helper exists. Adding two `decimal(15,2)`
 * strings as JS doubles is lossy for values a payroll deduction produces
 * routinely, so the loan progress bar summed `total_paid + balance` wrongly.
 */
describe('toCentavos', () => {
  it('parses two-decimal money strings exactly', () => {
    expect(toCentavos('1234.56')).toBe(123456);
    expect(toCentavos('0.01')).toBe(1);
    expect(toCentavos('20000.00')).toBe(2000000);
  });

  it('pads a short or absent fraction', () => {
    expect(toCentavos('1234.5')).toBe(123450);
    expect(toCentavos('1234')).toBe(123400);
  });

  it('treats null, undefined and empty as zero', () => {
    expect(toCentavos(null)).toBe(0);
    expect(toCentavos(undefined)).toBe(0);
    expect(toCentavos('')).toBe(0);
  });

  it('handles negative amounts', () => {
    expect(toCentavos('-0.01')).toBe(-1);
    expect(toCentavos('-1234.56')).toBe(-123456);
  });

  it('sums exactly where float addition does not', () => {
    // 0.1 + 0.2 !== 0.3 in binary floating point; in centavos it is exact.
    expect(Number('0.1') + Number('0.2')).not.toBe(0.3);
    expect(toCentavos('0.10') + toCentavos('0.20')).toBe(30);

    // A 3-period split of 1000.00 must add back up to exactly 1000.00.
    const instalments = ['333.33', '333.33', '333.34'];
    const total = instalments.reduce((sum, v) => sum + toCentavos(v), 0);
    expect(total).toBe(100000);
    expect(fromCentavos(total)).toBe('1000.00');
  });

  it('reaches 100% only when the balance is truly zero', () => {
    // The loan detail progress bar: paid / (paid + balance).
    const paid = toCentavos('666.66');
    const due = paid + toCentavos('333.34');
    expect(due).toBe(100000);
    expect((paid / due) * 100).toBeCloseTo(66.666, 3);

    const settledPaid = toCentavos('1000.00');
    const settledDue = settledPaid + toCentavos('0.00');
    expect((settledPaid / settledDue) * 100).toBe(100);
  });
});

describe('fromCentavos', () => {
  it('round-trips through toCentavos', () => {
    for (const value of ['0.00', '0.01', '9999.99', '12345.67', '-42.50']) {
      expect(fromCentavos(toCentavos(value))).toBe(value);
    }
  });
});
