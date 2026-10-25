import { z } from 'zod';

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
});

export type ProductFormValues = z.infer<typeof productFormSchema>;
