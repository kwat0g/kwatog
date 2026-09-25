export type ReturnCaseRealm = 'internal' | 'customer' | 'supplier';
export type ReturnCaseType = 'customer' | 'supplier';
export type ReturnCaseStatus =
  | 'submitted'
  | 'under_review'
  | 'information_needed'
  | 'action_agreed'
  | 'in_progress'
  | 'resolved'
  | 'rejected'
  | 'withdrawn';
export type ReturnCaseSourceKind = 'delivery' | 'grn' | 'purchase_order';
export type ReturnCasePreferredResolution = 'redelivery' | 'credit' | 'advice';
export type ReturnCaseResolution = 'return_goods' | 'redelivery' | 'credit' | 'no_action';

export interface ReturnCaseParty {
  id: string;
  name: string;
  kind?: 'customer' | 'supplier';
}

export interface ReturnCaseSource {
  id: string;
  kind: ReturnCaseSourceKind;
  label: string;
}

export interface ReturnCaseLine {
  id: string;
  source_delivery_item_id: string | null;
  source_po_item_id: string | null;
  source_grn_item_id: string | null;
  product_id: string | null;
  item_id: string | null;
  product_label: string | null;
  item_label: string | null;
  description: string;
  unit: string | null;
  expected_quantity: string;
  received_quantity: string;
  missing_quantity: string;
  defective_quantity: string;
  returned_quantity?: string | null;
  redelivered_quantity?: string | null;
  remaining_redelivery_quantity?: string | null;
  verified_missing_quantity: string | null;
  verified_defective_quantity: string | null;
  lot_number: string | null;
  serial_number: string | null;
  reason: string | null;
}

export interface ReturnCaseEvent {
  id: string;
  action: string;
  message: string | null;
  actor_type: 'internal' | 'customer' | 'supplier' | 'system';
  actor_name: string;
  is_public: boolean;
  created_at: string | null;
}

export interface ReturnCaseAttachment {
  id: string;
  event_id: string | null;
  file_name: string;
  mime_type: string;
  size: number;
  created_at: string | null;
}

export interface ReturnCaseOrderLink {
  id: string;
  number: string;
  status: string | null;
  type: 'sales_order' | 'purchase_order';
}

export interface ReturnCaseDocumentLink {
  id: string;
  delivery_number?: string;
  grn_number?: string;
  credit_note_number?: string | null;
  rma_number?: string;
  status: string | null;
  total_amount?: string;
}

export interface ReturnCaseRecord {
  intake_kind?: 'discrepancy' | 'delivery_trace';
  can_resolve_trace?: boolean;
  id: string;
  case_number: string;
  type: ReturnCaseType;
  status: ReturnCaseStatus;
  status_label: string;
  description: string;
  preferred_resolution: ReturnCasePreferredResolution;
  resolution: ReturnCaseResolution | null;
  resolution_notes: string | null;
  expected_date: string | null;
  created_at: string | null;
  resolved_at: string | null;
  party: ReturnCaseParty | null;
  owner: { id: string; name: string } | null;
  source: ReturnCaseSource | null;
  can_revise_agreement?: boolean;
  lines?: ReturnCaseLine[];
  events?: ReturnCaseEvent[];
  attachments?: ReturnCaseAttachment[];
  return_request?: { id: string; rma_number: string; status: string } | null;
  credit_note?: {
    id: string;
    credit_note_number: string | null;
    status: string;
    total_amount: string;
  } | null;
  return_credit_note?: {
    id: string;
    credit_note_number: string | null;
    status: string;
    total_amount: string;
  } | null;
  replacement_order?: ReturnCaseOrderLink | null;
  replacement_delivery?: ReturnCaseDocumentLink | null;
  resolution_goods_receipt_note?: ReturnCaseDocumentLink | null;
  resolution_receipts?: Array<{
    id: string;
    grn_number: string;
    status: string;
    lines: Array<{ case_line_id: string; description: string; unit: string | null; quantity: string }>;
  }>;
}

export interface ReturnCaseSourceResult {
  id: string;
  kind: ReturnCaseSourceKind;
  label: string;
  party_name: string | null;
}

export interface ReturnCaseSourceLineOption {
  id: string;
  part_number: string | null;
  description: string;
  unit: string | null;
  expected_quantity: string;
  received_quantity: string;
  maximum_quantity: string;
  lot_number: string | null;
  can_return: boolean;
}

export interface ReturnCaseSourceOptions {
  source: ReturnCaseSource;
  type: ReturnCaseType;
  party: { id: string; name: string } | null;
  lines: ReturnCaseSourceLineOption[];
}

export interface ReturnCaseLineSubmission {
  source_line_id: string;
  expected_quantity?: string;
  received_quantity: string;
  defective_quantity: string;
  lot_number?: string;
  serial_number?: string;
  reason?: string;
}

export interface CreateReturnCasePayload {
  source_kind: ReturnCaseSourceKind;
  source_id: string;
  description: string;
  preferred_resolution: ReturnCasePreferredResolution;
  request_key: string;
  lines: ReturnCaseLineSubmission[];
}

export interface ReturnCasePage {
  data: ReturnCaseRecord[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface ReturnCaseActionPayload {
  action: ReturnCaseAction;
  message?: string;
  resolution?: ReturnCaseResolution;
  expected_date?: string;
  lines?: Array<{
    id: string;
    verified_missing_quantity: string;
    verified_defective_quantity: string;
  }>;
  credit_note_id?: string;
  replacement_delivery_id?: string;
  resolution_goods_receipt_note_id?: string;
  resolution_goods_receipt_note_ids?: string[];
  replacement_sales_order_id?: string;
  replacement_purchase_order_id?: string;
}

export type ReturnCaseAction =
  | 'reply' | 'acknowledge' | 'start_review' | 'request_info' | 'assign' | 'agree'
  | 'create_return' | 'create_credit' | 'create_replacement' | 'link_resolution'
  | 'resolve' | 'resolve_trace' | 'reject' | 'withdraw' | 'reopen';

export interface ReturnCaseResolutionOptions {
  credit_notes: Array<{ id: string; label: string }>;
  deliveries: Array<{ id: string; label: string }>;
  goods_receipts: Array<{ id: string; label: string }>;
  sales_orders: Array<{ id: string; label: string }>;
  purchase_orders: Array<{ id: string; label: string }>;
}
