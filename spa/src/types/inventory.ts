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
export type GrnStatus = 'pending_qc' | 'accepted' | 'partial_accepted' | 'rejected';
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
  on_hand_quantity: string;
  reserved_quantity: string;
  available_quantity: string;
  stock_status: StockStatus;
  created_at?: string;
  updated_at?: string;
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
  item: { id: string; code: string; name: string } | null;
  from_location: { id: string; code: string } | null;
  to_location:   { id: string; code: string } | null;
  quantity: string;
  unit_cost: string;
  total_cost: string;
  reference_type: string | null;
  reference_id: number | null;
  remarks: string | null;
  creator: { id: string; name: string } | null;
}

export interface GrnItem {
  id: number;
  purchase_order_item_id: number;
  item?: { id: string; code: string; name: string; unit_of_measure: string };
  location?: { id: string; code: string; full_code: string };
  quantity_received: string;
  quantity_accepted: string;
  unit_cost: string;
  remarks: string | null;
}

export interface GoodsReceiptNote {
  id: string;
  grn_number: string;
  received_date: string;
  status: GrnStatus;
  rejected_reason: string | null;
  remarks: string | null;
  accepted_at: string | null;
  vendor: { id: string; name: string } | null;
  purchase_order: { id: string; po_number: string } | null;
  receiver: { id: string; name: string } | null;
  acceptor: { id: string; name: string } | null;
  items?: GrnItem[];
  created_at: string;
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
  total_value: string;
  reference_text: string | null;
  remarks: string | null;
  issuer: { id: string; name: string } | null;
  items?: MaterialIssueSlipItem[];
  created_at: string;
}

export interface InventoryDashboard {
  total_stock_value: string;
  items_below_reorder: number;
  items_critical: number;
  pending_grns: number;
  low_stock_alerts: Array<{
    item_id: number;
    code: string;
    name: string;
    available: string;
    reorder_point: string;
    safety_stock: string;
    lead_time_days: number;
    is_critical: boolean;
    severity: 'low' | 'critical';
    open_pr: { number: string; status: string } | null;
    open_po: { number: string; status: string } | null;
  }>;
  recent_movements: StockMovement[];
  top_consumed_materials: Array<{
    id: number; code: string; name: string; unit_of_measure: string;
    qty: string; total_value: string;
  }>;
}
