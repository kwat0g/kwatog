import { describe, expect, it } from 'vitest';
import { centavos, salvageExceedsCost, salvageIsUnchanged } from './salvageBound';

describe('asset salvage-value bound', () => {
  it('parses decimal strings as exact centavos', () => {
    expect(centavos('12000.00')).toBe(1200000n);
    expect(centavos('12000')).toBe(1200000n);
    expect(centavos('0.05')).toBe(5n);
    expect(centavos('0.5')).toBe(50n);
  });

  it('treats an absent or malformed amount as not comparable', () => {
    expect(centavos(undefined)).toBeNull();
    expect(centavos('')).toBeNull();
    expect(centavos('abc')).toBeNull();
    expect(centavos('1.234')).toBeNull();
  });

  it('rejects salvage strictly above cost and allows equal or below', () => {
    expect(salvageExceedsCost('12000.01', '12000.00')).toBe(true);
    expect(salvageExceedsCost('20000.00', '12000.00')).toBe(true);
    expect(salvageExceedsCost('12000.00', '12000.00')).toBe(false);
    expect(salvageExceedsCost('2400.00', '12000.00')).toBe(false);
    expect(salvageExceedsCost('0.00', '0.00')).toBe(false);
  });

  it('raises no issue when either side is missing so one field owns one error', () => {
    expect(salvageExceedsCost('', '12000.00')).toBe(false);
    expect(salvageExceedsCost(undefined, '12000.00')).toBe(false);
    // The edit form builds the bound from the loaded asset, which is undefined
    // on the first render before the query resolves.
    expect(salvageExceedsCost('20000.00', undefined)).toBe(false);
  });

  it('recognises a resubmitted stored value regardless of trailing zeros', () => {
    expect(salvageIsUnchanged('20000.00', '20000.00')).toBe(true);
    expect(salvageIsUnchanged('20000', '20000.00')).toBe(true);
    expect(salvageIsUnchanged('20000.01', '20000.00')).toBe(false);
    expect(salvageIsUnchanged('20000.00', undefined)).toBe(false);
  });
});
