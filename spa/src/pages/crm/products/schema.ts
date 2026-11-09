import { z } from 'zod';

const dimensionValue = z.string().refine((value) => {
  if (value.trim() === '') return true;
  if (!/^\d+(\.\d{1,3})?$/.test(value)) return false;
  return Number(value) > 0;
}, 'Enter a positive dimension with up to 3 decimal places.');

const specificationsSchema = z.object({
  dimensions: z.object({
    length: dimensionValue,
    width: dimensionValue,
    height: dimensionValue,
    unit: z.enum(['mm', 'cm', 'in']),
  }).nullable(),
  material: z.string().max(160),
  finish: z.string().max(160),
  tolerances: z.string().max(255),
  notes: z.string().max(2000),
}).superRefine((specifications, context) => {
  const dimensions = specifications.dimensions;
  if (!dimensions) return;
  const values = [dimensions.length, dimensions.width, dimensions.height];
  const hasAnyDimension = values.some((value) => value.trim() !== '');
  if (hasAnyDimension && values.some((value) => value.trim() === '')) {
    context.addIssue({ code: 'custom', path: ['dimensions'], message: 'Enter length, width, and height together.' });
  }
});

export const productFormSchema = z.object({
  part_number: z
    .string()
    .regex(/^[A-Z0-9-]{2,30}$/, 'Use 2–30 uppercase letters, digits, or hyphens.'),
  name: z.string().min(1, 'Name is required').max(200),
  description: z.string().max(1000).optional().or(z.literal('')),
  unit_of_measure: z.string().min(1, 'UOM is required').max(20),
  standard_cost: z
    .string()
    .regex(/^\d+(\.\d{1,2})?$/, 'Use a non-negative decimal with up to 2 places'),
  revenue_account_id: z.string().optional().or(z.literal('')),
  is_active: z.boolean().optional(),
  specifications: specificationsSchema.optional(),
  is_customizable: z.boolean().optional(),
});

export type ProductFormValues = z.infer<typeof productFormSchema>;
