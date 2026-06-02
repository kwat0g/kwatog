import { client } from '@/api/client';
import type { ReturnRequest, ReturnRequestFormData } from '@/types/returnManagement';

export const returnManagementApi = {
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
    client.post(`/return-management/return-requests/${id}/inspect`, { internal_notes: internalNotes }).then((r) => r.data.data as ReturnRequest),

  complete: (id: string, locationId?: string) =>
    client.post(`/return-management/return-requests/${id}/complete`, { location_id: locationId }).then((r) => r.data.data as ReturnRequest),

  reject: (id: string, reason?: string) =>
    client.post(`/return-management/return-requests/${id}/reject`, { reason }).then((r) => r.data.data as ReturnRequest),

  cancel: (id: string, reason?: string) =>
    client.post(`/return-management/return-requests/${id}/cancel`, { reason }).then((r) => r.data.data as ReturnRequest),
};
