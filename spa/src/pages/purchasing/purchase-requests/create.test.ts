import { describe, expect, it } from 'vitest';
import { lineSchema } from './create';

describe('purchase request line quantity', () => {
  it('accepts the inventory three-decimal precision', () => {
    expect(
      lineSchema.safeParse({
        description: 'Resin',
        quantity: '0.001',
        unit: 'kg',
        estimated_unit_price: '10.00',
      }).success,
    ).toBe(true);
  });

  it('rejects quantity precision beyond three decimal places', () => {
    expect(
      lineSchema.safeParse({
        description: 'Resin',
        quantity: '0.0001',
        unit: 'kg',
        estimated_unit_price: '10.00',
      }).success,
    ).toBe(false);
  });
});

describe('purchase request header required_delivery_date', () => {
  it('allows empty required delivery date', () => {
    expect(
      lineSchema.safeParse({
        description: 'Test item',
        quantity: '1.0',
        unit: 'pcs',
        estimated_unit_price: '100.00',
      }).success,
    ).toBe(true);
  });
});
