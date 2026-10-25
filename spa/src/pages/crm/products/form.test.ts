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
