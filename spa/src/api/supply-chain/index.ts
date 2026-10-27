import { client } from '../client';
import type { ReportDeliveryAttempt, ReceiveTruckReturn } from '@/types/deliveryAttempt';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { ContainerSize, ContainerType, Shipment, ShipmentContainer, ShipmentContainerData, ShipmentDocument, ShipmentDocumentType, Delivery, DeliveryProof, DeliveryProofType, Vehicle, ShipmentStatus, ShipmentPurchaseOrderOption, ShipmentPurchaseOrderOptionParams, DeliveryStatus, Incoterm, LandedCostAllocationMethod } from '@/types/supplyChain';

export interface ShipmentListParams extends ListParams {
 status?: ShipmentStatus | 'mid_pipe';
 purchase_order_id?: string;
}

export interface ShipmentStatusOption {
 value: ShipmentStatus;
 label: string;
 next_status: ShipmentStatus | null;
 is_terminal: boolean;
}

export interface ShipmentOptions {
 statuses: ShipmentStatusOption[];
 document_types: Array<{ value: ShipmentDocumentType; label: string }>;
 incoterms: Array<{ value: Incoterm; label: string }>;
 allocation_methods: Array<{ value: LandedCostAllocationMethod; label: string }>;
 container_sizes: Array<{ value: ContainerSize; label: string }>;
 container_types: Array<{ value: ContainerType; label: string }>;
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
 freight_cost?: string;
 insurance_cost?: string;
 duties_amount?: string;
 brokerage_fee?: string;
 other_charges?: string;
}

export interface UpdateShipmentMetaData {
 carrier?: string;
 vessel?: string;
 container_number?: string;
 bl_number?: string;
 etd?: string;
 eta?: string;
 notes?: string;
 freight_cost?: string;
 insurance_cost?: string;
 duties_amount?: string;
 brokerage_fee?: string;
 other_charges?: string;
}

export const shipmentsApi = {
 options: () => client.get<{ data: ShipmentOptions }>('/supply-chain/shipments/options').then((r) => r.data.data),
 purchaseOrderOptions: (params?: ShipmentPurchaseOrderOptionParams, signal?: AbortSignal) =>
 client.get<PaginatedResponse<ShipmentPurchaseOrderOption>>('/supply-chain/shipments/purchase-order-options', { params, signal }).then((r) => r.data),
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
 calculateLandedCost: (id: string, allocation_method: LandedCostAllocationMethod) =>
 client.post<ApiSuccess<Shipment>>(`/supply-chain/shipments/${id}/calculate-landed-cost`, { allocation_method }).then((r) => r.data.data),
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
 restoreDocument: (documentId: string) =>
  client.patch(`/supply-chain/shipment-documents/${documentId}/restore`),
};

export const shipmentContainersApi = {
 list: (shipmentId: string, params?: { page?: number; per_page?: number; search?: string; trashed?: 'with' | 'only' }) =>
  client.get<PaginatedResponse<ShipmentContainer>>(`/supply-chain/shipments/${shipmentId}/containers`, { params }).then((r) => r.data),
 create: (shipmentId: string, data: ShipmentContainerData) =>
  client.post<ApiSuccess<ShipmentContainer>>(`/supply-chain/shipments/${shipmentId}/containers`, data).then((r) => r.data.data),
 update: (containerId: string, data: Partial<ShipmentContainerData>) =>
  client.put<ApiSuccess<ShipmentContainer>>(`/supply-chain/containers/${containerId}`, data).then((r) => r.data.data),
 archive: (containerId: string) => client.delete(`/supply-chain/containers/${containerId}`),
 restore: (containerId: string) => client.patch(`/supply-chain/containers/${containerId}/restore`),
};

export interface DeliveryListParams extends ListParams {
 status?: DeliveryStatus | '';
 sales_order_id?: string;
 invoice_handoff_status?: 'not_started' | 'generated' | 'manual_required' | 'not_required' | '';
}

export interface CreateDeliveryItemData {
 sales_order_item_id: string;
 quantity: number;
 inspection_id: string;
}

export interface CreateDeliveryData {
 sales_order_id: string;
 vehicle_id?: string;
 driver_id?: string;
 scheduled_date: string;
 notes?: string;
 items: CreateDeliveryItemData[];
}

export interface DeliveryInspectionOption {
 id: string;
 inspection_number: string;
 sales_order_item_id: string | null;
 work_order_number: string | null;
 product: { part_number: string; name: string } | null;
 accepted_quantity: number;
 remaining_quantity: string;
 completed_at: string | null;
}

export interface DeliveryFormOrder {
 id: string;
 so_number: string;
 customer: { name: string } | null;
 items?: Array<{
  id: string;
  quantity: string;
  remaining_quantity: string;
  product: { part_number: string; name: string; unit_of_measure: string } | null;
 }>;
}

export interface DeliveryFormOptions {
 sales_orders: DeliveryFormOrder[];
 selected_sales_order: DeliveryFormOrder | null;
 has_more: boolean;
}

export interface DeliveryDriverOption {
 id: string;
 name: string;
}

export interface DeliveryAssignmentData {
 vehicle_id: string;
 driver_id: string;
 reason: string;
}

export interface DeliveryShipmentPlanData extends DeliveryAssignmentData {
 scheduled_date: string;
 expected_delivery_date: string;
 carrier: string;
 dispatch: boolean;
 loading_photo?: File;
}

export const deliveriesApi = {
 reserveStock: (id: string, requestKey: string) => client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/reserve-stock`, { request_key: requestKey }).then(r => r.data.data),
 retryCost: (id: string, kind: 'customer_cogs' | 'unaccounted_loss', requestKey: string) => client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/retry-cost-handoff`, { kind, request_key: requestKey }).then(r => r.data.data),
 amendAttempt: (id: string, data: ReportDeliveryAttempt) =>
 client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/amend-attempt`, data).then(r => r.data.data),
 receiveLateReturn: (id: string, data: ReceiveTruckReturn) =>
 client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/receive-late-return`, data).then(r => r.data.data),
 receiveTruckReturn: (id: string, data: ReceiveTruckReturn) =>
 client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/truck-return-receipt`, data).then(r => r.data.data),
 reportAttempt: (id: string, data: ReportDeliveryAttempt) =>
 client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/attempt-outcome`, data).then(r => r.data.data),
 formOptions: (params?: { search?: string; page?: number; sales_order_id?: string }) =>
  client.get<{ data: DeliveryFormOptions }>('/supply-chain/deliveries/form-options', { params }).then((r) => r.data.data),
 options: () => client.get<{ data: { statuses: Array<{ value: DeliveryStatus; label: string; next_status: DeliveryStatus | null; is_terminal: boolean }> } }>('/supply-chain/deliveries/options').then((r) => r.data.data),
 inspectionOptions: (salesOrderId: string) =>
  client.get<{ data: DeliveryInspectionOption[] }>('/supply-chain/deliveries/inspection-options', { params: { sales_order_id: salesOrderId } }).then((r) => r.data.data),
 driverOptions: () =>
  client.get<{ data: DeliveryDriverOption[] }>('/supply-chain/deliveries/driver-options').then((r) => r.data.data),
  create: (data: CreateDeliveryData, idempotencyKey?: string) =>
  client.post<ApiSuccess<Delivery>>('/supply-chain/deliveries', data, {
    headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : undefined,
  }).then((r) => r.data.data),
 list: (params?: DeliveryListParams) =>
 client.get<PaginatedResponse<Delivery>>('/supply-chain/deliveries', { params }).then((r) => r.data),
 show: (id: string) =>
  client.get<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}`).then((r) => r.data.data),
 assign: (id: string, data: DeliveryAssignmentData) =>
  client.patch<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/assignment`, data).then((r) => r.data.data),
 shipmentPlan: (id: string, data: DeliveryShipmentPlanData) => {
  const form = new FormData();
  form.append('scheduled_date', data.scheduled_date);
  form.append('expected_delivery_date', data.expected_delivery_date);
  form.append('carrier', data.carrier);
  form.append('vehicle_id', data.vehicle_id);
  form.append('driver_id', data.driver_id);
  form.append('reason', data.reason);
  form.append('dispatch', data.dispatch ? '1' : '0');
  if (data.loading_photo) form.append('loading_photo', data.loading_photo);
  return client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/shipment-plan`, form).then((r) => r.data.data);
 },
 updateStatus: (id: string, status: DeliveryStatus, note?: string) =>
 client.patch<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/status`, { status, note }).then((r) => r.data.data),
 uploadReceipt: (id: string, file: File) => {
 const fd = new FormData();
 fd.append('file', file);
 return client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/receipt`, fd).then((r) => r.data.data);
 },
  confirm: (id: string, data?: { receiver_name?: string; receiver_position?: string; delivery_remarks?: string }) =>
  client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/confirm`, data ?? {}).then((r) => r.data.data),
  retryCoc: (id: string) =>
  client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/retry-coc`).then((r) => r.data.data),
  retryInvoice: (id: string) =>
  client.post<ApiSuccess<Delivery>>(`/supply-chain/deliveries/${id}/retry-invoice`).then((r) => r.data.data),
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
 restore: (deliveryId: string, proofId: string) =>
  client.patch(`/supply-chain/deliveries/${deliveryId}/proofs/${proofId}/restore`).then((r) => r.data),
};

export interface VehicleListParams extends ListParams {
 status?: string;
 trashed?: 'only' | 'with';
}

export interface VehicleData {
 plate_number: string;
 name: string;
 vehicle_type: string;
 capacity_kg?: number | null;
 status?: string;
 notes?: string | null;
}

export const vehiclesApi = {
 options: () => client.get<{ data: {
 types: Array<{ value: string; label: string }>;
 statuses: Array<{ value: string; label: string }>;
 } }>('/supply-chain/vehicles/options').then((r) => r.data.data),
 list: (params?: VehicleListParams) =>
  client.get<PaginatedResponse<Vehicle>>('/supply-chain/vehicles', { params }).then((r) => r.data),
 create: (data: VehicleData) =>
  client.post<ApiSuccess<Vehicle>>('/supply-chain/vehicles', data).then((r) => r.data.data),
 update: (id: string, data: Partial<VehicleData>) =>
  client.patch<ApiSuccess<Vehicle>>(`/supply-chain/vehicles/${id}`, data).then((r) => r.data.data),
 destroy: (id: string) => client.delete(`/supply-chain/vehicles/${id}`),
 restore: (id: string) => client.patch(`/supply-chain/vehicles/${id}/restore`),
};
