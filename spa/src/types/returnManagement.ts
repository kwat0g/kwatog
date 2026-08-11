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
 disposition_label?: string;
 disposition_notes?: string;
 // 2026-08-08 — units actually moved in/out of stock at dispose time
 // (restocked for customer returns, shipped back for supplier returns).
 moved_quantity?: string | null;
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
 inspection_handoff?: {
  status: 'not_started' | 'generated' | 'manual_required' | 'not_required' | string;
  status_label?: string | null;
  message?: string | null;
  at?: string | null;
 };
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
 credit_note?: { id: string; credit_note_number: string | null; type: string; status: string; total_amount: string };
 replacement_purchase_order?: { id: string; po_number: string; status: string };
 credit_memo?: { id: string; invoice_number: string };
 inspection?: { id: string; inspection_number: string; status: string };
 // 2026-08-08 — dispose-time movement summary + last movement for the banner.
 moved_quantity?: string | null;
 stock_movement?: {
  id: string;
  quantity: string;
  movement_type?: string;
  to_location?: { id: string; code: string } | null;
  from_location?: { id: string; code: string } | null;
 } | null;
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
 unit_price: number;
 reason?: string;
 condition?: string;
 source_grn_item_id?: string;
 source_po_item_id?: string;
 }>;
}
