import { client } from '../client';

export interface TraceabilityWorkOrderRow {
  id: string;
  wo_number: string;
  batch_number: string | null;
  product: { id: string; part_number: string; name: string } | null;
  machine: { id: string; machine_code: string; name: string } | null;
  mold: { id: string; mold_code: string; name: string } | null;
  quantity_good: number;
  quantity_rejected: number;
  actual_start: string | null;
  actual_end: string | null;
  status: string;
}

export interface TraceabilityMaterialRef {
  item_id: string | null;
  item_code: string | null;
  item_name: string | null;
  grn_number: string | null;
  material_lot_number: string | null;
  supplier_lot_reference: string | null;
  quantity_used: string | null;
}

export interface TraceabilityInspection {
  id: string;
  inspection_number: string;
  stage: string;
  status: string;
  completed_at: string | null;
}

export interface TraceabilityLotRow {
  id: string;
  lot_number: string;
  lot_date: string | null;
  delivery: { id: string; delivery_number: string } | null;
  customer: { id: string; name: string | null } | null;
}

export type TraceabilityType = 'batch' | 'lot' | 'material_lot' | null;

export interface TraceabilityResult {
  found: boolean;
  term: string;
  type: TraceabilityType;
  trace: {
    work_order?: TraceabilityWorkOrderRow;
    lot?: { id: string; lot_number: string; quantity: number; lot_date: string | null;
            product: { id: string; part_number: string | null; name: string | null } | null };
    material_lot?: {
      grn_number: string | null;
      received_date: string | null;
      item_id: string | null;
      item_code: string | null;
      item_name: string | null;
      material_lot_number: string | null;
      supplier_lot_reference: string | null;
      quantity_received: string | null;
      quantity_accepted: string | null;
    };
    backward?: {
      materials?: TraceabilityMaterialRef[];
      grn?: { id: string; grn_number: string; received_date: string | null; vendor_id: number | null } | null;
      work_orders?: Array<{
        work_order: TraceabilityWorkOrderRow;
        materials: TraceabilityMaterialRef[];
        inspections: TraceabilityInspection[];
      }>;
    };
    forward?: {
      inspections?: TraceabilityInspection[];
      lots?: TraceabilityLotRow[];
      delivery?: { id: string; delivery_number: string; status: string;
                   delivered_at: string | null; confirmed_at: string | null } | null;
      customer?: { id: string; name: string | null } | null;
      work_orders?: TraceabilityWorkOrderRow[];
    };
  };
}

export const traceabilityApi = {
  search: (term: string) =>
    client
      .get<{ data: TraceabilityResult }>('/quality/traceability/search', { params: { term } })
      .then((r) => r.data.data),
};
