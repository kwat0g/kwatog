const purchaseOrderRoot = ['purchasing', 'purchase-orders'] as const;

export const purchaseOrderQueryKeys = {
  all: purchaseOrderRoot,
  lists: [...purchaseOrderRoot, 'list'] as const,
  list: <TFilters extends object>(filters: TFilters) => [...purchaseOrderRoot, 'list', filters] as const,
  details: [...purchaseOrderRoot, 'detail'] as const,
  detail: (id: string) => [...purchaseOrderRoot, 'detail', id] as const,
  options: [...purchaseOrderRoot, 'options'] as const,
  billable: [...purchaseOrderRoot, 'billable'] as const,
  supplierActivity: (id: string) => [...purchaseOrderRoot, 'detail', id, 'supplier-activity'] as const,
};
