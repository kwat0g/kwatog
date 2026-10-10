type Dispatch = { items?: Array<{ purchase_order_item_id: string; quantity_shipped: string }> };
type OrderedLine = { id: string; quantity: string };

/** Compare recorded dispatches with the PO in purchase units; legacy unquantified rows stay unknown. */
export function remainingDispatchQuantities(shipments: Dispatch[], ordered: OrderedLine[]) {
  const remaining = new Map(ordered.map((line) => [line.id, Math.round(Number(line.quantity) * 100)]));
  for (const shipment of shipments) {
    for (const line of shipment.items ?? []) {
      if (remaining.has(line.purchase_order_item_id)) {
        remaining.set(line.purchase_order_item_id,
          remaining.get(line.purchase_order_item_id)! - Math.round(Number(line.quantity_shipped) * 100));
      }
    }
  }
  return new Map([...remaining].map(([id, cents]) => [id, cents / 100]));
}

export function overDispatchedLines(shipments: Dispatch[], ordered: OrderedLine[]) {
  const remaining = remainingDispatchQuantities(shipments, ordered);
  return ordered.filter((line) => (remaining.get(line.id) ?? 0) < 0);
}
