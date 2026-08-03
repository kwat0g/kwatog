import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Shipment, ShipmentDocument, ShipmentDocumentType, Delivery, DeliveryProof, DeliveryProofType, Vehicle, ShipmentStatus, DeliveryStatus, Incoterm } from '@/types/supplyChain';

export interface ShipmentListParams extends ListParams {
  status?: ShipmentStatus;
  purchase_order_id?: string;
}

export interface ShipmentStatusOption {
  value: ShipmentStatus;
  label: string;
  next_status: ShipmentStatus | null;
  is_terminal: boolean;
}

export interface CreateShipmentData {
  purchase_order_id: string;
  carrier?: string;
  vessel?: string;
  container_number?: string;
  bl_number?: string;
  etd?: string;
  eta?: string;
  notes?: string;
  incoterm?: Incoterm;
}

export interface UpdateShipmentMetaData {
  carrier?: string;
  vessel?: string;
  container_number?: string;
  bl_number?: string;
  etd?: string;
  eta?: string;
  notes?: string;
}

export const shipmentsApi = {
  options: () => client.get<{ data: {
    statuses: ShipmentStatusOption[];
    document_types: Array<{ value: ShipmentDocumentType; label: string }>;
    incoterms: Array<{ value: Incoterm; label: string }>;
    allocation_methods: Array<{ value: string; label: string }>;
  } }>('/supply-chain/shipments/options').then((r) => r.data.data),
  list: (params?: ShipmentListParams) =>
    client.get<PaginatedResponse<Shipment>>('/supply-chain/shipments', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Shipment>>(`/supply-chain/shipments/${id}`).then((r) => r.data.data),
  create: (data: CreateShipmentData) =>
    client.post<ApiSuccess<Shipment>>('/supply-chain/shipments', data).then((r) => r.data.data),
  updateStatus: (id: string, status: ShipmentStatus, note?: string) =>
    client.patch<ApiSuccess<Shipment>>(`/supply-chain/shipments/${id}/status`, { status, note }).then((r) => r.data.data),
  updateMeta: (id: string, data: UpdateShipmentMetaData) =>
    client.patch<ApiSuccess<Shipment>>(`/supply-chain/shipments/${id}`, data).then((r) => r.data.data),
  uploadDocument: (id: string, file: File, document_type: ShipmentDocumentType, notes?: string) => {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('document_type', document_type);
    if (notes) fd.append('notes', notes);
    return client.post<ApiSuccess<ShipmentDocument>>(`/supply-chain/shipments/${id}/documents`, fd)
      .then((r) => r.data.data);
  },
  destroyDocument: (documentId: string) =>
    client.delete(`/supply-chain/shipment-documents/${documentId}`),
};

export interface DeliveryListParams extends ListParams {
  status?: DeliveryStatus;
  sales_order_id?: string;
}

export interface CreateDeliveryItemData {
  sales_order_item_id: string;
  quantity: number;
  inspection_id?: string;
}

export interface CreateDeliveryData {
  sales_order_id: string;
  vehicle_id?: string;
  driver_id?: string;
  scheduled_date: string;
  notes?: string;
  items: CreateDeliveryItemData[];
}

export const deliveriesApi = {
  options: () => client.get<{ data: { statuses: Array<{ value: DeliveryStatus; label: string; next_status: DeliveryStatus | null; is_terminal: boolean }> } }>('/supply-chain/deliveries/options').then((r) => r.data.data),
  create: (data: CreateDeliveryData) =>
    client.post<ApiSuccess<Delivery>>('/supply-chain/deliveries', data).then((r) => r.data.data),
  list: (params?: DeliveryListParams) =>
    client.get<PaginatedResponse<Delivery>>('/supply-chain/deliveries', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}`).then((r) => r.data.data),
  updateStatus: (id: string, status: DeliveryStatus, note?: string) =>
    client.patch<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/status`, { status, note }).then((r) => r.data.data),
  uploadReceipt: (id: string, file: File) => {
    const fd = new FormData();
    fd.append('file', file);
    return client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/receipt`, fd).then((r) => r.data.data);
  },
  confirm: (id: string, data?: { receiver_name?: string; receiver_position?: string; delivery_remarks?: string }) =>
    client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/confirm`, data ?? {}).then((r) => r.data.data),
};

/** ADV7 — Proof of Delivery file management. */
export const deliveryProofsApi = {
  options: () => client.get<{ data: { proof_types: Array<{ value: DeliveryProofType; label: string }> } }>('/supply-chain/deliveries/proofs/options').then((r) => r.data.data),
  list: (deliveryId: string) =>
    client.get<ApiSuccess<DeliveryProof[]>>(`/supply-chain/deliveries/${deliveryId}/proofs`).then((r) => r.data.data),
  upload: (deliveryId: string, file: File, proof_type: DeliveryProofType, notes?: string) => {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('proof_type', proof_type);
    if (notes) fd.append('notes', notes);
    return client.post<ApiSuccess<DeliveryProof>>(`/supply-chain/deliveries/${deliveryId}/proofs`, fd).then((r) => r.data.data);
  },
  destroy: (deliveryId: string, proofId: string) =>
    client.delete(`/supply-chain/deliveries/${deliveryId}/proofs/${proofId}`).then((r) => r.data),
};

export const vehiclesApi = {
  options: () => client.get<{ data: {
    types: Array<{ value: string; label: string }>;
    statuses: Array<{ value: string; label: string }>;
  } }>('/supply-chain/vehicles/options').then((r) => r.data.data),
  list: (params?: ListParams) =>
    client.get<PaginatedResponse<Vehicle>>('/supply-chain/vehicles', { params }).then((r) => r.data),
};
