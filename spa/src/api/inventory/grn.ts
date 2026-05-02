import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { GoodsReceiptNote, CreateGrnData } from '@/types/inventory';

export const grnApi = {
  list: (params?: ListParams & { status?: string; vendor_id?: string; purchase_order_id?: string; from?: string; to?: string }) =>
    client.get<PaginatedResponse<GoodsReceiptNote>>('/inventory/grn', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<GoodsReceiptNote>>(`/inventory/grn/${id}`).then((r) => r.data.data),
  create: (data: CreateGrnData) =>
    client.post<ApiSuccess<GoodsReceiptNote>>('/inventory/grn', data).then((r) => r.data.data),
  accept: (id: string, item_accepted_map?: Record<number, string>) =>
    client.patch<ApiSuccess<GoodsReceiptNote>>(`/inventory/grn/${id}/accept`, { item_accepted_map }).then((r) => r.data.data),
  reject: (id: string, reason: string) =>
    client.patch<ApiSuccess<GoodsReceiptNote>>(`/inventory/grn/${id}/reject`, { reason }).then((r) => r.data.data),
};
