import { client } from '@/api/client';
import type { ReturnRequest, ReturnRequestFormData, DispositionPayload } from '@/types/returnManagement';

export const returnManagementApi = {
 options: () => client.get<{ data: {
 types: Array<{ value: string; label: string }>;
 statuses: Array<{ value: string; label: string }>;
 reasons: Array<{ value: string; label: string }>;
 resolutions: Array<{ value: string; label: string }>;
 conditions: Array<{ value: string; label: string }>;
 dispositions: Array<{ value: string; label: string }>;
 } }>('/return-management/options').then((r) => r.data.data),
 list: (params?: Record<string, string | number | undefined>) =>
 client.get('/return-management/return-requests', { params }).then((r) => r.data),

 get: (id: string) =>
 client.get(`/return-management/return-requests/${id}`).then((r) => r.data.data as ReturnRequest),

 create: (data: ReturnRequestFormData) =>
 client.post('/return-management/return-requests', data).then((r) => r.data.data as ReturnRequest),

 submit: (id: string) =>
 client.post(`/return-management/return-requests/${id}/submit`).then((r) => r.data.data as ReturnRequest),

 approve: (id: string) =>
 client.post(`/return-management/return-requests/${id}/approve`).then((r) => r.data.data as ReturnRequest),

 receive: (id: string, receivedQuantities?: Record<string, number>) =>
 client.post(`/return-management/return-requests/${id}/receive`, { received_quantities: receivedQuantities }).then((r) => r.data.data as ReturnRequest),

 inspect: (id: string, internalNotes?: string) =>
 client.post(`/return-management/return-requests/${id}/inspect`, { internal_notes: internalNotes }).then((r) => r.data.data as ReturnRequest), // 2026-08-08 — restock lines are received back into stock at dispose time,
 retryInspection: (id: string) =>
  client.post(`/return-management/return-requests/${id}/retry-inspection`).then((r) => r.data.data as ReturnRequest),
 // so customer-return disposals carry the destination warehouse location.
 dispose: (id: string, dispositions: DispositionPayload[], createReplacementPo?: boolean, locationId?: string) =>
  client.post<{ data: ReturnRequest }>(`/return-management/return-requests/${id}/dispose`, { dispositions, create_replacement_po: createReplacementPo, location_id: locationId }).then((r) => r.data.data), // Customer-return restock lines already moved at dispose — complete() only
 // needs a location when a line still has to move (supplier returns).
 complete: (id: string, locationId?: string) =>
  client.post(`/return-management/return-requests/${id}/complete`, locationId ? { location_id: locationId } : {}).then((r) => r.data.data as ReturnRequest),

 reject: (id: string, reason?: string) =>
 client.post(`/return-management/return-requests/${id}/reject`, { reason }).then((r) => r.data.data as ReturnRequest),

 cancel: (id: string, reason?: string) =>
 client.post(`/return-management/return-requests/${id}/cancel`, { reason }).then((r) => r.data.data as ReturnRequest),
};
