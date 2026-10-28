// Sprint 5 — Inventory types. IDs are hash strings; decimals are strings.

export type ItemType = 'raw_material' | 'finished_good' | 'packaging' | 'spare_part';
export type ReorderMethod = 'fixed_quantity' | 'days_of_supply';
export type StockStatus = 'ok' | 'low' | 'critical';
export type WarehouseZoneType =
  | 'raw_materials'
  | 'staging'
  | 'finished_goods'
  | 'spare_parts'
  | 'quarantine'
  | 'scrap';
export type StockMovementType =
  | 'grn_receipt'
  | 'material_issue'
  | 'material_return'
  | 'production_receipt'
  | 'delivery'
  | 'transfer'
  | 'adjustment_in'
  | 'adjustment_out'
  | 'scrap'
  | 'return_to_vendor'
  | 'cycle_count';
export type GrnStatus = 'draft' | 'pending_qc' | 'accepted' | 'partial_accepted' | 'rejected';
export type IncomingQcHandoffStatus =
  | 'not_started'
  | 'generated'
  | 'manual_required'
  | 'not_required';
export type MaterialIssueStatus = 'draft' | 'issued' | 'cancelled';

export interface ItemCategory {
  id: string;
  name: string;
  parent_id: string | null;
  parent_name: string | null;
  item_type?: ItemType | null;
  item_type_label?: string | null;
  children?: ItemCategory[];
}

export interface Item {
  id: string;
  code: string;
  name: string;
  description: string | null;
  category: { id: string; name: string; item_type?: ItemType | null } | null;
  classification_needs_review?: boolean;
  item_type: ItemType;
  item_type_label: string;
  unit_of_measure: string;
  standard_cost: string;
  reorder_method: ReorderMethod;
  reorder_point: string;
  safety_stock: string;
  minimum_order_quantity: string;
  lead_time_days: number;
  is_critical: boolean;
  is_active: boolean;
  quality_plan_ready: boolean;
  on_hand_quantity: string;
  reserved_quantity: string;
  available_quantity: string;
  approved_free_stock?: string;
  incoming_stock_quantity?: string;
  quality_held_quantity?: string;
  unusable_zone_quantity?: string;
  usable_quantity?: string;
  stock_status: StockStatus;
  created_at?: string;
  updated_at?: string;
}

export type ItemOption = Pick<Item, 'id' | 'code' | 'name' | 'unit_of_measure'>;

export interface QualityPlanParameter {
  parameter_name: string;
  parameter_type: 'dimensional' | 'visual' | 'functional' | null;
  acceptance_criterion?: string;
  unit_of_measure?: string | null;
  nominal_value?: number | null;
  tolerance_min?: number | null;
  tolerance_max?: number | null;
  is_critical?: boolean;
  notes?: string | null;
}

export interface ItemQualityPlan {
  capture_method?: 'measurement' | 'checklist';
  id: string;
  version: number;
  stage: 'incoming';
  sampling_method: 'aql' | 'fixed' | 'full';
  fixed_sample_size: number | null;
  aql_level: string | null;
  parameters: QualityPlanParameter[];
  effective_from: string;
  effective_to: string | null;
  is_active: boolean;
  notes: string | null;
  vendor: { id: string; name: string } | null;
  creator: { id: string; name: string } | null;
  created_at: string;
}

export interface CreateItemData {
  code: string;
  name: string;
  description?: string;
  category_id: string;
  item_type?: ItemType;
  unit_of_measure: string;
  standard_cost: string;
  reorder_method: ReorderMethod;
  reorder_point: string;
  safety_stock: string;
  minimum_order_quantity?: string;
  lead_time_days: number;
  is_critical?: boolean;
  is_active?: boolean;
}
export type UpdateItemData = Partial<CreateItemData>;

export interface Warehouse {
  id: string;
  name: string;
  code: string;
  address: string | null;
  is_active: boolean;
  zones?: WarehouseZone[];
}

export interface WarehouseZone {
  id: string;
  warehouse_id?: string;
  name: string;
  code: string;
  zone_type: WarehouseZoneType;
  zone_type_label?: string;
  locations?: WarehouseLocation[];
}

export interface WarehouseLocation {
  id: string;
  zone_id: string | null;
  code: string;
  rack: string | null;
  bin: string | null;
  is_active: boolean;
  is_blocked: boolean;
  full_code: string;
  zone?: {
    id: string;
    name: string;
    code: string;
    zone_type: WarehouseZoneType;
    warehouse?: { id: string; name: string; code: string } | null;
  };
}

export interface StockLevel {
  item: { id: string; code: string; name: string; unit_of_measure: string } | null;
  location: { id: string; code: string; full_code: string } | null;
  quantity: string;
  reserved_quantity: string;
  available: string;
  usable_quantity?: string;
  quality_held_quantity?: string;
  expired_quantity?: string;
  unusable_zone_quantity?: string;
  weighted_avg_cost: string;
  total_value: string;
  lock_version: number;
  last_counted_at: string | null;
}

export interface StockMovement {
  handover?: MaterialHandover | null;
  handover_remaining_quantity?: string | null;
  id: string;
  created_at: string;
  movement_type: StockMovementType;
  movement_type_label?: string;
  item: { id: string; code: string; name: string; unit_of_measure?: string } | null;
  from_location: { id: string; code: string } | null;
  to_location: { id: string; code: string } | null;
  quantity: string;
  unit_cost: string;
  total_cost: string;
  lot_number: string | null;
  expiry_date: string | null;
  gl_handoff: {
    status: 'not_started' | 'generated' | 'manual_required' | 'not_required';
    status_label: string | null;
    message: string | null;
    at: string | null;
    journal_entry?: { id: string; entry_number: string } | null;
  };
  reference_type: string | null;
  reference_id: string | null;
  reference_label?: string | null;
  reference_work_order_id?: string | null;
  reference_url?: string | null;
  remarks: string | null;
  creator: { id: string; name: string } | null;
}

export interface MaterialHandover {
  recipient_name: string;
  quantity: string;
  handed_over_at: string;
  recorded_by: string | null;
}

export interface MaterialHandoverTask {
  maintenance_work_order_id?: string | null;
  id: string;
  work_order_id: string | null;
  material_issue_id: string | null;
  label: string;
  status: 'pending' | 'handed_over' | 'needs_review';
  total_lines: number;
  handed_over_lines: number;
  pending_lines: number;
  latest_issue_at: string | null;
}

export interface MaterialHandoverSource {
  request_id?: string | null;
  requested_quantity?: string;
  movement_id: string;
  item: { id: string; code: string; name: string; unit_of_measure: string };
  location: { id: string; code: string } | null;
  lot_number: string | null;
  issued_quantity: string;
  returned_quantity: string;
  remaining_quantity: string;
  eligible: boolean;
  handover: MaterialHandover | null;
}

export interface MaterialHandoverTaskDetail extends MaterialHandoverTask {
  sources: MaterialHandoverSource[];
  recipients: Array<{ value: string; label: string }>;
  default_recipient_id: string | null;
}

export interface MaterialReservation {
  id: string;
  work_order: { id: string; wo_number: string } | null;
  item: { id: string; code: string; name: string; unit_of_measure: string } | null;
  location: { id: string; full_code: string } | null;
  quantity: string;
  status: 'reserved' | 'issued' | 'released';
  reserved_at: string | null;
  ended_at: string | null;
}

export interface WorkOrderReservations {
  id: string;
  work_order: MaterialReservation['work_order'];
  materials: MaterialReservation[];
}

export interface MaterialReturnOptions {
  eligible: boolean;
  source_movement_id: string;
  issued_quantity: string;
  returned_quantity: string;
  returned_cost: string;
  returnable_quantity: string;
  unit_cost?: string;
  unit_of_measure?: string | null;
  total_returned_at_original_cost?: string;
  location?: string | null;
  lot_number?: string | null;
  gross_production_units?: string;
  consumption_floor?: string;
  consumption_basis?:
    | 'unlinked_issue'
    | 'preproduction'
    | 'saved_bom_norm'
    | 'fixed_saved_plan'
    | 'no_recipe';
  message?: string | null;
}

export interface StockAdjustment {
  id: string;
  direction: 'in' | 'out';
  quantity: string;
  unit_cost: string;
  value: string;
  reason_code: string | null;
  reason_label?: string | null;
  reason: string;
  status: 'pending' | 'approved' | 'rejected' | 'withdrawn';
  status_label?: string;
  item: { id: string; code: string; name: string; unit_of_measure?: string } | null;
  location: { id: string; code: string } | null;
  stock_movement: StockMovement | null;
  requested_by: { id: string; name: string } | null;
  approved_by: { id: string; name: string } | null;
  approved_at: string | null;
  resolved_by: { id: string; name: string } | null;
  resolved_at: string | null;
  resolution_reason: string | null;
  can_approve: boolean;
  can_reject: boolean;
  can_withdraw: boolean;
  created_at: string;
}

export interface GrnItem {
  active_quality_holds?: Array<{ id: string; ncr_number: string; quantity: string | null }>;
  original_received_quantity?: string;
  returned_quantity?: string;
  net_received_quantity?: string;
  id: string;
  purchase_order_item_id: string;
  purchase_order_unit?: string | null;
  item?: {
    id: string;
    code: string;
    name: string;
    unit_of_measure: string;
    quality_plan_ready: boolean;
    stored_bins?: Array<{ id: string; full_code: string; quantity: string }>;
  };
  location?: { id: string; code: string; full_code: string };
  quantity_received: string;
  quantity_received_in_uom: string | null;
  received_uom_conversion_factor: string | null;
  quantity_accepted: string;
  quantity_remaining?: string;
  quantity_outstanding?: string | null;
  unit_cost: string;
  received_uom_code: string | null;
  lot_number: string | null;
  supplier_lot_reference: string | null;
  expiry_date: string | null;
  moisture_percentage: string | null;
  coa_document_path: string | null;
  coa_verified: boolean;
  remarks: string | null;
  inspection?: {
    id: string;
    inspection_number: string;
    status: string | null;
    status_label?: string | null;
  } | null;
}

export interface GoodsReceiptNote {
  id: string;
  supplier_shipment?: {
    id: string;
    tracking_number: string | null;
    items: Array<{
      purchase_order_item_id: string | null;
      quantity_shipped: string;
      quantity_received: string;
      difference: string;
      unit: string | null;
    }>;
  } | null;
  grn_number: string;
  received_date: string | null;
  status: GrnStatus;
  status_label?: string;
  rejected_reason: string | null;
  remarks: string | null;
  accepted_at: string | null;
  remainder_rejected_at?: string | null;
  remainder_rejected_reason?: string | null;
  remainder_rejected_by?: { id: string; name: string } | null;
  can_reject_remainder?: boolean;
  incoming_qc_handoff?: {
    status: IncomingQcHandoffStatus | null;
    status_label?: string | null;
    message: string | null;
    at: string | null;
  };
  qc_inspection?: {
    id: string;
    inspection_number: string;
    stage: string | null;
    stage_label?: string | null;
    status: string | null;
    status_label?: string | null;
  } | null;
  journal_entry?: {
    id: string;
    entry_number: string;
    status: string | null;
    status_label?: string | null;
  } | null;
  vendor: { id: string; name: string } | null;
  purchase_order: {
    id: string;
    po_number: string;
    /** 2026-08-08 — P2P stepper: the PR behind this PO. */
    purchase_request?: { id: string; pr_number: string } | null;
  } | null;
  receiver: { id: string; name: string } | null;
  acceptor: { id: string; name: string } | null;
  items?: GrnItem[];
  created_at: string;
  /** 2026-08-08 — draft supplier bill auto-created from this accepted GRN. */
  bill?: {
    id: string;
    bill_number: string;
    status: string;
    status_label?: string;
    total_amount: string;
  } | null;
}

export interface FinalizeGrnData {
  supplier_shipment_id?: string | null;
  items: Array<{
    purchase_order_item_id: string;
    location_id: string;
    quantity_received: string;
    received_uom_code?: string;
    lot_number?: string;
    material_lot_number?: string;
    supplier_lot_reference?: string;
    expiry_date?: string;
    moisture_percentage?: string;
    coa_document_path?: string;
    remarks?: string;
  }>;
}

export interface CreateGrnData {
  purchase_order_id: string;
  received_date?: string;
  remarks?: string;
  items: Array<{
    purchase_order_item_id: string;
    item_id: string;
    location_id: string;
    quantity_received: string;
    received_uom_code?: string;
    lot_number?: string;
    material_lot_number?: string;
    supplier_lot_reference?: string;
    expiry_date?: string;
    moisture_percentage?: string;
    coa_document_path?: string;
    unit_cost?: string;
    remarks?: string;
  }>;
}

export interface MaterialIssueSlipItem {
  id: number;
  stock_movement_id?: string | null;
  item?: { id: string; code: string; name: string; unit_of_measure: string };
  location?: { id: string; code: string };
  quantity_issued: string;
  quantity_issued_in_uom: string | null;
  issued_uom_conversion_factor: string | null;
  unit_cost: string;
  total_cost: string;
  issued_uom_code: string | null;
  lot_number: string | null;
  remarks: string | null;
}

export interface MaterialIssueSlip {
  id: string;
  slip_number: string;
  work_order_id: string | null;
  work_order?: { id: string; wo_number: string } | null;
  issued_date: string;
  status: MaterialIssueStatus;
  status_label?: string;
  total_value: string;
  reference_text: string | null;
  remarks: string | null;
  issuer: { id: string; name: string } | null;
  items?: MaterialIssueSlipItem[];
  created_at: string;
}

// REC-08 — Material Review Board (MRB) / quarantine workflow.
export type MrbStatus = 'held' | 'released' | 'scrapped' | 'returned';
export type MrbDisposition = 'scrap' | 'rework' | 'use_as_is' | 'return_to_supplier';

export interface MrbLocation {
  id: string;
  code: string;
  full_code: string;
  warehouse_id: string | null;
  warehouse_code: string | null;
  warehouse_name: string | null;
  is_active: boolean;
  zone: string | null;
  zone_type: string | null;
}

export interface MrbRecord {
  id: string;
  mrb_number: string;
  status: MrbStatus;
  status_label: string;
  disposition: string | null;
  disposition_label?: string | null;
  quantity: string;
  concession_approval_reference?: string | null;
  requires_concession_approval?: boolean;
  item: { id: string; code: string; name: string; unit_of_measure: string } | null;
  ncr: {
    id: string;
    ncr_number: string;
    status?: string | null;
    status_label?: string | null;
    affected_quantity?: number;
    disposition?: string | null;
    disposition_label?: string | null;
    inspection?: { id: string; inspection_number: string; stage: string; status: string } | null;
  } | null;
  inspection: {
    id: string;
    inspection_number?: string;
    stage?: string;
    stage_label?: string | null;
    status?: string;
    status_label?: string | null;
    batch_quantity?: number;
  } | null;
  return_request: {
    id: string;
    rma_number: string;
    line_id: string;
    disposition: string | null;
  } | null;
  actions?: {
    can_release: boolean;
    can_release_good_stock: boolean;
  };
  source_location: MrbLocation | null;
  quarantine_location: MrbLocation | null;
  release_location: MrbLocation | null;
  hold_movement_id: string | null;
  release_movement_id: string | null;
  held_by_id: string | null;
  held_by: string | null;
  held_at: string | null;
  released_by: string | null;
  released_at: string | null;
  notes: string | null;
  created_at: string | null;
}

export interface CreateMrbData {
  lot_number?: string;
  item_id: string;
  quantity: string;
  source_location_id: string;
  quarantine_location_id?: string;
  ncr_id?: string;
  inspection_id?: string;
  notes?: string;
}

export interface ReleaseMrbData {
  concession_approval_reference?: string;
  inspected_quantity?: string;
  evidence_reference?: string;
  recovery_passed?: boolean;
  disposition: MrbDisposition;
  target_location_id?: string;
  notes?: string;
}

export interface InventoryDashboard {
  consumption_history_days: number;
  total_stock_value: string;
  items_below_reorder: number;
  items_critical: number;
  items_low_stock: number;
  items_out_of_stock: number;
  pending_grns: number;
  low_stock_alerts: Array<{
    item_id: string;
    code: string;
    name: string;
    available: string;
    reorder_point: string;
    safety_stock: string;
    lead_time_days: number;
    is_critical: boolean;
    severity: 'low' | 'critical';
    open_pr: { number: string; status: string; status_label?: string } | null;
    open_po: { number: string; status: string; status_label?: string } | null;
  }>;
  recent_movements: StockMovement[];
  top_consumed_materials: Array<{
    id: string;
    code: string;
    name: string;
    unit_of_measure: string;
    qty: string;
    total_value: string;
  }>;
}
