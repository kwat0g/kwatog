export type DispositionType = 'scrap' | 'rework' | 'restock' | 'return_to_supplier';

export interface DispositionPayload {
  item_id: string;
  disposition: DispositionType;
  notes?: string;
}

export interface ReturnRequestItem {
  id: string;
  product_id?: string;
  item_id?: string;
  quantity: string;
  returned_quantity: string;
  unit_price: string;
  total: string;
  reason?: string;
  condition?: string;
  disposition?: DispositionType;
  disposition_notes?: string;
  ncr?: { id: string; ncr_number: string };
  product?: { id: string; part_number: string; name: string };
  item?: { id: string; code: string; name: string };
}

export interface ReturnRequest {
  id: string;
  rma_number: string;
  type: 'customer_return' | 'supplier_return';
  type_label: string;
  status: string;
  status_label: string;
  is_editable: boolean;
  disposition_status?: string;
  reason_code?: string;
  reason_description?: string;
  customer_notes?: string;
  internal_notes?: string;
  resolution?: string;
  refund_amount?: string;
  return_date?: string;
  source_label?: string;
  sales_order?: { id: string; so_number: string };
  invoice?: { id: string; invoice_number: string };
  purchase_order?: { id: string; po_number: string };
  bill?: { id: string; bill_number: string };
  customer?: { id: string; name: string };
  vendor?: { id: string; name: string };
  credit_note?: { id: string; invoice_number: string };
  credit_memo?: { id: string; invoice_number: string };
  items?: ReturnRequestItem[];
  item_count: number;
  creator?: { id: string; name: string };
  approved_by?: { id: string; name: string };
  approved_at?: string;
  received_at?: string;
  inspected_at?: string;
  completed_at?: string;
  rejected_at?: string;
  cancelled_at?: string;
  created_at?: string;
  updated_at?: string;
}

export interface ReturnRequestFormData {
  type: 'customer_return' | 'supplier_return';
  sales_order_id?: string;
  invoice_id?: string;
  purchase_order_id?: string;
  bill_id?: string;
  customer_id?: string;
  vendor_id?: string;
  reason_code?: string;
  reason_description?: string;
  customer_notes?: string;
  resolution?: string;
  return_date?: string;
  items?: Array<{
    product_id?: string;
    item_id?: string;
    quantity: number;
    unit_price?: number;
    reason?: string;
    condition?: string;
  }>;
}
