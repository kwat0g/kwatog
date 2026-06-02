// Sprint 7 — Supply Chain types. IDs are hash strings.

export type ShipmentStatus =
  | 'ordered' | 'shipped' | 'in_transit' | 'customs' | 'cleared' | 'received' | 'cancelled';

export type ShipmentDocumentType =
  | 'proforma_invoice' | 'commercial_invoice' | 'packing_list' | 'bill_of_lading'
  | 'import_entry' | 'certificate_of_origin' | 'msds' | 'boc_release' | 'insurance_certificate';

export interface ShipmentDocument {
  id: string;
  document_type: ShipmentDocumentType;
  original_filename: string | null;
  file_size_bytes: number | null;
  mime_type: string | null;
  notes: string | null;
  url: string | null;
  uploaded_at: string | null;
  uploader?: { id: string; name: string } | null;
}

export interface Shipment {
  id: string;
  shipment_number: string;
  status: ShipmentStatus;
  carrier: string | null;
  vessel: string | null;
  container_number: string | null;
  bl_number: string | null;
  etd: string | null;
  atd: string | null;
  eta: string | null;
  ata: string | null;
  customs_clearance_date: string | null;
  notes: string | null;
  purchase_order?: { id: string; po_number: string } | null;
  documents?: ShipmentDocument[];
  created_at: string;
  updated_at: string;
}

export type DeliveryStatus =
  | 'scheduled' | 'loading' | 'in_transit' | 'delivered' | 'confirmed' | 'cancelled';

/** ADV7 — Proof type for delivery proof files. */
export type DeliveryProofType =
  | 'signed_dr' | 'photo' | 'customer_po_confirmation' | 'other';

export interface DeliveryProof {
  id: string;
  proof_type: DeliveryProofType;
  file_name: string;
  file_size: number | null;
  mime_type: string | null;
  is_image: boolean;
  notes: string | null;
  view_url: string | null;
  uploader: { id: string; name: string } | null;
  uploaded_at: string | null;
}

export interface Delivery {
  id: string;
  delivery_number: string;
  status: DeliveryStatus;
  scheduled_date: string | null;
  departed_at: string | null;
  delivered_at: string | null;
  confirmed_at: string | null;
  receipt_photo_url: string | null;
  notes: string | null;
  /** ADV7 — Proof of Delivery receiver fields. */
  receiver_name: string | null;
  receiver_position: string | null;
  received_at: string | null;
  delivery_remarks: string | null;
  proofs?: DeliveryProof[];
  proof_count?: number;
  sales_order?: { id: string; so_number: string } | null;
  vehicle?: { id: string; plate_number: string; name: string } | null;
  driver?: { id: string; name: string } | null;
  invoice?: { id: string; invoice_number: string; total_amount: string; status: string } | null;
  items?: Array<{
    id: string;
    sales_order_item_id: string | null;
    inspection: { id: string; inspection_number: string; status: string } | null;
    quantity: number;
    unit_price: string;
  }>;
  /** ADV3 — outgoing shipment lot for IATF 16949 traceability. */
  shipment_lot?: {
    id: string;
    lot_number: string;
    lot_date: string | null;
    quantity: number;
    product: { id: string; part_number: string | null; name: string | null } | null;
    customer: { id: string; name: string | null } | null;
    work_order_count: number;
  } | null;
  created_at: string;
  updated_at: string;
}

export interface Vehicle {
  id: string;
  plate_number: string;
  name: string;
  vehicle_type: string;
  capacity_kg: number | null;
  status: string;
  notes: string | null;
}

/** ADV3 — IATF 16949 outgoing shipment lot. One Delivery → one Lot → N WO batches. */
export interface ShipmentLot {
  id: string;
  lot_number: string;
  delivery: { id: string; delivery_number: string; status: string } | null;
  customer: { id: string; name: string | null } | null;
  product: { id: string; part_number: string | null; name: string | null } | null;
  batches: Array<{
    id: string;
    wo_number: string;
    batch_number: string | null;
    quantity_good: number;
  }>;
  quantity: number;
  lot_date: string | null;
  coc_path: string | null;
  created_at: string | null;
}
