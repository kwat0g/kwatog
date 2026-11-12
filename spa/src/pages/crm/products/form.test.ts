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

  it('preserves structured physical specifications, materials, finish, and customization status', () => {
    const specifications = {
      dimensions: { length: '120.00', width: '80.00', height: '32.00', unit: 'mm' },
      material: 'Aluminum 6061',
      finish: 'Black anodized',
      tolerances: '±0.10 mm',
      notes: 'Mounting holes on the 80 mm face.',
    };
    const result = productFormSchema.safeParse({
      ...product,
      specifications,
      is_customizable: true,
    });

    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.specifications).toEqual(specifications);
      expect(result.data.is_customizable).toBe(true);
    }
  });

  it('rejects partial or non-positive dimensions instead of silently saving an incomplete size', () => {
    const result = productFormSchema.safeParse({
      ...product,
      specifications: {
        dimensions: { length: '120', width: '', height: '-2', unit: 'mm' },
        material: '',
        finish: '',
        tolerances: '',
        notes: '',
      },
      is_customizable: false,
    });

    expect(result.success).toBe(false);
  });
});
