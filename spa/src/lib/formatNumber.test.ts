import { afterEach, expect, it } from 'vitest';
import { formatCompactCurrency, formatPeso, formatQuantity, formatUnitCost } from './formatNumber';
import { setFunctionalCurrency } from './runtimeCurrency';

afterEach(() => setFunctionalCurrency(null));

it('keeps money at two grouped decimals and inventory quantities at three significant decimals', () => {
 setFunctionalCurrency('PHP');
 expect(formatPeso('999999999999.99')).toBe('₱999,999,999,999.99');
 expect(formatQuantity('123456789.125')).toBe('123,456,789.125');
});

it('groups compact currency and preserves four decimal places for unit cost', () => {
 setFunctionalCurrency('PHP');
 expect(formatCompactCurrency('1234567.89', 1_000, 'K')).toBe('PHP 1,234.57K');
 expect(formatUnitCost('1234567.89')).toBe('1,234,567.8900');
});
