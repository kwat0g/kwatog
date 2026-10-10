import { describe, expect, it } from 'vitest';
import { overDispatchedLines, remainingDispatchQuantities } from './supplierDispatch';

describe('overDispatchedLines', () => {
  it('flags cumulative dispatch above the PO quantity while leaving legacy unknowns alone', () => {
    const ordered = [{ id: 'bags', quantity: '20000.00' }];
    expect(overDispatchedLines([{ items: [{ purchase_order_item_id: 'bags', quantity_shipped: '12000.00' }] },
      { items: [{ purchase_order_item_id: 'bags', quantity_shipped: '8000.00' }] }, {}], ordered)).toEqual([]);
    expect(overDispatchedLines([{ items: [{ purchase_order_item_id: 'bags', quantity_shipped: '12000.00' }] },
      { items: [{ purchase_order_item_id: 'bags', quantity_shipped: '9000.00' }] }], ordered)).toEqual(ordered);
  });
});

describe('remainingDispatchQuantities', () => {
  it('allows successive partial shipments only until ordered quantity is fully dispatched', () => {
    const ordered = [{ id: 'bags', quantity: '20000.00' }];
    expect(remainingDispatchQuantities([], ordered).get('bags')).toBe(20000);
    expect(remainingDispatchQuantities([{ items: [{ purchase_order_item_id: 'bags', quantity_shipped: '12000.00' }] }], ordered).get('bags')).toBe(8000);
    expect(remainingDispatchQuantities([{ items: [{ purchase_order_item_id: 'bags', quantity_shipped: '12000.00' }] },
      { items: [{ purchase_order_item_id: 'bags', quantity_shipped: '8000.00' }] }], ordered).get('bags')).toBe(0);
  });
});
