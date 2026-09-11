import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const surfaces = [
  // The PO detail page now carries two tables: the order lines and the
  // supplier-response proposed lines (added with the negotiation feature).
  { path: './purchasing/purchase-orders/detail.tsx', wrappers: 2 },
 { path: './inventory/grn/detail.tsx', wrappers: 1 },
 { path: './accounting/bills/detail.tsx', wrappers: 2 },
 { path: './accounting/invoices/detail.tsx', wrappers: 1 },
 { path: './production/work-orders/detail.tsx', wrappers: 4 },
] as const;

describe('operational detail table responsiveness', () => {
 it('keeps cited detail tables scrollable with an explicit content width', () => {
  for (const surface of surfaces) {
   const source = readFileSync(fileURLToPath(new URL(surface.path, import.meta.url)), 'utf8');
   expect(source.match(/className="overflow-x-auto"/g)?.length, surface.path).toBe(surface.wrappers);
   expect(source, surface.path).toMatch(/min-w-\[\d+px\]/);
  }
 });
});
