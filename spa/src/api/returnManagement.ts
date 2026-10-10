import { client } from '@/api/client';
import type { ReturnRequest, ReturnRequestFormData, DispositionPayload, ReturnSourceOptions } from '@/types/returnManagement';

export const returnManagementApi = {
 options: (params?: { type?: string; finance_only?: boolean }) => client.get<{ data: {
 types: Array<{ value: string; label: string }>;
 statuses: Array<{ value: string; label: string }>;
 reasons: Array<{ value: string; label: string }>;
 resolutions: Array<{ value: string; label: string }>;
 conditions: Array<{ value: string; label: string }>;
 dispositions: Array<{ value: string; label: string }>;
 disposition_matrix: Record<string, Record<string, Array<{ value: string; label: string }>>>;
 } }>('/return-management/options', { params }).then((r) => r.data.data),
 sourceOptions: (params: { type: string; customer_id?: string; vendor_id?: string }) =>
  client.get<{ data: ReturnSourceOptions }>('/return-management/return-requests/source-options', { params }).then((r) => r.data.data),
 list: (params?: Record<string, string | number | undefined>) =>
 client.get('/return-management/return-requests', { params }).then((r) => r.data),

 get: (id: string) =>
 client.get(`/return-management/return-requests/${id}`).then((r) => r.data.data as ReturnRequest),

 create: (data: ReturnRequestFormData) =>
 client.post('/return-management/return-requests', data).then((r) => r.data.data as ReturnRequest),

 update: (id: string, data: ReturnRequestFormData) =>
  client.patch(`/return-management/return-requests/${id}`, data).then((r) => r.data.data as ReturnRequest),

 submit: (id: string, evidence?: FormData) =>
 client.post(`/return-management/return-requests/${id}/submit`, evidence).then((r) => r.data.data as ReturnRequest),

 downloadDefectPhoto: (id: string) =>
 client.get<Blob>(`/return-management/return-requests/${id}/defect-photo`, { responseType: 'blob' }).then((r) => r.data),

 approve: (id: string) =>
 client.post(`/return-management/return-requests/${id}/approve`).then((r) => r.data.data as ReturnRequest),

 receive: (
  id: string,
  receivedQuantities: Record<string, string>,
  options: { finalReceipt: boolean; requestKey: string },
 ) => client.post(`/return-management/return-requests/${id}/receive`, {
  received_quantities: receivedQuantities,
  final_receipt: options.finalReceipt,
  request_key: options.requestKey,
 }).then((r) => r.data.data as ReturnRequest),

 inspect: (id: string, internalNotes?: string) =>
 client.post(`/return-management/return-requests/${id}/inspect`, { internal_notes: internalNotes }).then((r) => r.data.data as ReturnRequest), // 2026-08-08 — restock lines are received back into stock at dispose time,
 splitItem: (id: string, itemId: string, splitQuantity: string) =>
  client.post<{ data: ReturnRequest }>(`/return-management/return-requests/${id}/items/${itemId}/split`, { split_quantity: splitQuantity }).then((r) => r.data.data),
 retryInspection: (id: string) =>
  client.post(`/return-management/return-requests/${id}/retry-inspection`).then((r) => r.data.data as ReturnRequest),
 // so customer-return disposals carry the destination warehouse location.
 dispose: (id: string, dispositions: DispositionPayload[], createReplacementPo?: boolean, locationId?: string, requiredDeliveryDate?: string) =>
  client.post<{ data: ReturnRequest }>(`/return-management/return-requests/${id}/dispose`, { dispositions, create_replacement_po: createReplacementPo, location_id: locationId, required_delivery_date: requiredDeliveryDate }).then((r) => r.data.data), // Customer-return restock lines already moved at dispose — complete() only
 // needs a location when a line still has to move (supplier returns).
 complete: (id: string, locationId?: string) =>
  client.post(`/return-management/return-requests/${id}/complete`, locationId ? { location_id: locationId } : {}).then((r) => r.data.data as ReturnRequest),

 reject: (id: string, reason?: string) =>
 client.post(`/return-management/return-requests/${id}/reject`, { reason }).then((r) => r.data.data as ReturnRequest),

 cancel: (id: string, reason?: string) =>
 client.post(`/return-management/return-requests/${id}/cancel`, { reason }).then((r) => r.data.data as ReturnRequest),
};
