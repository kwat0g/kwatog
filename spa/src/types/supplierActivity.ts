/** What a supplier reported through the portal, as read by OGAMI staff. */

export interface SupplierActivityShipment {
 id: string;
 shipped_date: string | null;
 carrier: string | null;
 tracking_number: string | null;
 estimated_arrival: string | null;
 notes: string | null;
 updated_at: string | null;
 updates_count?: number;
}

export interface SupplierActivityDocument {
 id: string;
 document_type: string;
 document_type_label: string;
 original_filename: string;
 file_size_bytes: number | null;
 uploaded_at: string | null;
}

export interface SupplierActivityScheduleLine {
 purchase_order_item_id: string | null;
 product_name: string;
 quantity: string;
 notes: string | null;
}

export interface SupplierActivitySchedule {
 id: string;
 month: string;
 status: string;
 status_label: string;
 lines: SupplierActivityScheduleLine[];
 cancel_reason: string | null;
 reject_reason: string | null;
}

export interface PurchaseOrderSupplierActivity {
 shipments: SupplierActivityShipment[];
 documents: SupplierActivityDocument[];
 delivery_schedules: SupplierActivitySchedule[];
}

export interface GrnSupplierDocuments {
 shipment: SupplierActivityShipment | null;
 documents: SupplierActivityDocument[];
}
