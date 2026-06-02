import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Shipment, Delivery, DeliveryProof, DeliveryProofType, Vehicle, ShipmentStatus, DeliveryStatus } from '@/types/supplyChain';

export interface ShipmentListParams extends ListParams {
  status?: ShipmentStatus;
  purchase_order_id?: string;
}

export const shipmentsApi = {
  list: (params?: ShipmentListParams) =>
    client.get<PaginatedResponse<Shipment>>('/supply-chain/shipments', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Shipment>>(`/supply-chain/shipments/${id}`).then((r) => r.data.data),
  create: (data: { purchase_order_id: string; carrier?: string; eta?: string }) =>
    client.post<ApiSuccess<Shipment>>('/supply-chain/shipments', data).then((r) => r.data.data),
  updateStatus: (id: string, status: ShipmentStatus, note?: string) =>
    client.patch<ApiSuccess<Shipment>>(`/supply-chain/shipments/${id}/status`, { status, note }).then((r) => r.data.data),
};

export interface DeliveryListParams extends ListParams {
  status?: DeliveryStatus;
  sales_order_id?: string;
}

export const deliveriesApi = {
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
  list: (params?: ListParams) =>
    client.get<PaginatedResponse<Vehicle>>('/supply-chain/vehicles', { params }).then((r) => r.data),
};
