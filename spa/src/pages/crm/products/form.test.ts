import { describe, expect, it } from 'vitest';
import { productFormSchema } from './schema';

const product = {
  part_number: 'P-100',
  name: 'Widget',
  description: '',
  unit_of_measure: 'PCS',
  standard_cost: '10.00',
  is_active: true,
};

describe('CRM product form revenue account', () => {
  it('accepts a HashID revenue-account override', () => {
    expect(productFormSchema.safeParse({ ...product, revenue_account_id: 'rev-1' }).success).toBe(true);
  });

  it('accepts an empty revenue-account override', () => {
    expect(productFormSchema.safeParse({ ...product, revenue_account_id: '' }).success).toBe(true);
  });
});

it('rejects zero selling prices and a base price below the minimum', () => {
  expect(productFormSchema.safeParse({ ...product, base_selling_price: '0.00' }).success).toBe(false);
  expect(productFormSchema.safeParse({ ...product, base_selling_price: '20.00', minimum_selling_price: '22.00' }).success).toBe(false);
  expect(productFormSchema.safeParse({ ...product, base_selling_price: '25.00', minimum_selling_price: '22.00', portal_visibility: 'standard' }).success).toBe(true);
});
