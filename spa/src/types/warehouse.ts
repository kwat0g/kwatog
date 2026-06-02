// ─── Warehouse Map ───

export type BinStockStatus = 'empty' | 'ok' | 'low' | 'full' | 'blocked';

export interface WarehouseMapLocation {
  id: string;
  code: string;
  full_code: string;
  rack: string | null;
  bin: string | null;
  is_blocked: boolean;
  blocked_reason: string | null;
  capacity_kg: string | null;
  current_item: { id: string; code: string; name: string } | null;
  current_quantity: string;
  current_lot_number: string | null;
  stock_status: BinStockStatus;
  stock_quantity: string;
  last_movement_at: string | null;
}

export interface WarehouseMapZone {
  id: string;
  code: string;
  name: string;
  zone_type: string;
  type_label: string;
  locations: WarehouseMapLocation[];
}

export interface WarehouseMap {
  id: string;
  code: string;
  name: string;
  address: string | null;
  zones: WarehouseMapZone[];
}

// ─── Stock Count ───

export type StockCountStatus = 'draft' | 'in_progress' | 'frozen' | 'completed' | 'cancelled';
export type StockCountItemStatus = 'pending' | 'counted' | 'verified' | 'adjusted';

export interface StockCountItem {
  id: string;
  session_id: number;
  location: {
    id: string;
    code: string;
    full_code: string;
  } | null;
  item: {
    id: string;
    code: string;
    name: string;
    unit_of_measure: string;
  } | null;
  system_quantity: string;
  counted_quantity: string | null;
  variance: string;
  variance_percent: string;
  lot_number: string | null;
  status: StockCountItemStatus;
  counted_by: { id: string; name: string } | null;
  counted_at: string | null;
  notes: string | null;
}

export interface StockCountSession {
  id: string;
  session_number: string;
  title: string;
  scope: string;
  warehouse: { id: string; name: string; code: string } | null;
  zone: { id: string; name: string; code: string } | null;
  status: StockCountStatus;
  total_locations: number;
  counted_locations: number;
  variance_count: number;
  variance_value: string;
  created_by: { id: string; name: string } | null;
  approved_by: { id: string; name: string } | null;
  frozen_at: string | null;
  completed_at: string | null;
  notes: string | null;
  items?: StockCountItem[];
  created_at: string;
  updated_at: string;
}

// ─── Transfer Orders ───

export type TransferOrderStatus = 'pending' | 'transferred' | 'cancelled';

export interface TransferOrder {
  id: string;
  transfer_number: string;
  from_location: {
    id: string;
    code: string;
    full_code: string;
  } | null;
  to_location: {
    id: string;
    code: string;
    full_code: string;
  } | null;
  item: {
    id: string;
    code: string;
    name: string;
    unit_of_measure: string;
  } | null;
  quantity: string;
  reason: string | null;
  status: TransferOrderStatus;
  created_by: { id: string; name: string } | null;
  transferred_by: { id: string; name: string } | null;
  transferred_at: string | null;
  created_at: string;
}

// ─── Picking List ───

export interface PickingSuggestion {
  location: {
    id: string;
    code: string;
    full_code: string;
    zone: string;
    warehouse: string;
    rack: string | null;
    bin: string | null;
  };
  quantity_available: string;
  quantity_to_pick: string;
  lot_number: string | null;
}

export interface PickingLine {
  item_id: number;
  item_code: string | null;
  item_name: string | null;
  unit_of_measure: string;
  quantity_required: string;
  preferred_location: PickingSuggestion['location'] | null;
  suggestions: PickingSuggestion[];
}

export interface PickingList {
  slip_number: string;
  work_order: string;
  issued_date: string;
  lines: PickingLine[];
  total_lines: number;
  total_items: number;
}
