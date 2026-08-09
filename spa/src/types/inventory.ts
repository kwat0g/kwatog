// Sprint 5 — Inventory types. IDs are hash strings; decimals are strings.

export type ItemType = 'raw_material' | 'finished_good' | 'packaging' | 'spare_part';
export type ReorderMethod = 'fixed_quantity' | 'days_of_supply';
export type StockStatus = 'ok' | 'low' | 'critical';
export type WarehouseZoneType =
 | 'raw_materials' | 'staging' | 'finished_goods' | 'spare_parts' | 'quarantine' | 'scrap';
export type StockMovementType =
 | 'grn_receipt' | 'material_issue' | 'production_receipt' | 'delivery'
 | 'transfer' | 'adjustment_in' | 'adjustment_out' | 'scrap'
 | 'return_to_vendor' | 'cycle_count';
export type GrnStatus = 'draft' | 'pending_qc' | 'accepted' | 'partial_accepted' | 'rejected';
export type MaterialIssueStatus = 'draft' | 'issued' | 'cancelled';

export interface ItemCategory {
 id: string;
 name: string;
 parent_id: string | null;
 parent_name: string | null;
 children?: ItemCategory[];
}

export interface Item {
 id: string;
 code: string;
 name: string;
 description: string | null;
 category: { id: string; name: string } | null;
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
 stock_status: StockStatus;
 created_at?: string;
 updated_at?: string;
}

export interface QualityPlanParameter {
 parameter_name: string;
 parameter_type: 'dimensional' | 'visual' | 'functional';
 unit_of_measure?: string | null;
 nominal_value?: number | null;
 tolerance_min?: number | null;
 tolerance_max?: number | null;
 is_critical?: boolean;
 notes?: string | null;
}

export interface ItemQualityPlan {
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
 item_type: ItemType;
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
 full_code: string;
 zone?: {
 id: string; name: string; code: string; zone_type: WarehouseZoneType;
 warehouse?: { id: string; name: string; code: string } | null;
 };
}

export interface StockLevel {
 item: { id: string; code: string; name: string; unit_of_measure: string } | null;
 location: { id: string; code: string; full_code: string } | null;
 quantity: string;
 reserved_quantity: string;
 available: string;
 weighted_avg_cost: string;
 total_value: string;
 last_counted_at: string | null;
}

export interface StockMovement {
 id: string;
 created_at: string;
 movement_type: StockMovementType;
 movement_type_label?: string;
 item: { id: string; code: string; name: string } | null;
 from_location: { id: string; code: string } | null;
 to_location: { id: string; code: string } | null;
 quantity: string;
 unit_cost: string;
 total_cost: string;
 reference_type: string | null;
 reference_id: number | null;
 remarks: string | null;
 creator: { id: string; name: string } | null;
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
 status: 'pending' | 'approved';
 status_label?: string;
 item: { id: string; code: string; name: string } | null;
 location: { id: string; code: string } | null;
 stock_movement: StockMovement | null;
 requested_by: { id: string; name: string } | null;
 approved_by: { id: string; name: string } | null;
 approved_at: string | null;
 created_at: string;
}

export interface GrnItem {
 id: string;
 purchase_order_item_id: string;
 item?: { id: string; code: string; name: string; unit_of_measure: string; quality_plan_ready: boolean };
 location?: { id: string; code: string; full_code: string };
 quantity_received: string;
 quantity_accepted: string;
 unit_cost: string;
 remarks: string | null;
}

export interface GoodsReceiptNote {
 id: string;
 grn_number: string;
 received_date: string | null;
 status: GrnStatus;
 status_label?: string;
 rejected_reason: string | null;
 remarks: string | null;
 accepted_at: string | null;
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
 bill?: { id: string; bill_number: string; status: string; status_label?: string; total_amount: string } | null;
}

export interface FinalizeGrnData {
 items: Array<{
 purchase_order_item_id: string;
 location_id: string;
 quantity_received: string;
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
 unit_cost?: string;
 remarks?: string;
 }>;
}

export interface MaterialIssueSlipItem {
 id: number;
 item?: { id: string; code: string; name: string; unit_of_measure: string };
 location?: { id: string; code: string };
 quantity_issued: string;
 unit_cost: string;
 total_cost: string;
 remarks: string | null;
}

export interface MaterialIssueSlip {
 id: string;
 slip_number: string;
 work_order_id: number | null;
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
 item: { id: string; code: string; name: string; unit_of_measure: string } | null;
 ncr: { id: string; ncr_number: string } | null;
 inspection: { id: string } | null;
 source_location: MrbLocation | null;
 quarantine_location: MrbLocation | null;
 release_location: MrbLocation | null;
 hold_movement_id: string | null;
 release_movement_id: string | null;
 held_by: string | null;
 held_at: string | null;
 released_by: string | null;
 released_at: string | null;
 notes: string | null;
 created_at: string | null;
}

export interface CreateMrbData {
 item_id: string;
 quantity: string;
 source_location_id: string;
 quarantine_location_id?: string;
 ncr_id?: string;
 inspection_id?: string;
 notes?: string;
}

export interface ReleaseMrbData {
 disposition: MrbDisposition;
 target_location_id?: string;
 notes?: string;
}

export interface InventoryDashboard {
 consumption_history_days: number;
 total_stock_value: string;
 items_below_reorder: number;
 items_critical: number;
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
 id: string; code: string; name: string; unit_of_measure: string;
 qty: string; total_value: string;
 }>;
}
