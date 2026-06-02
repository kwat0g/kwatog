import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { GoodsReceiptNote, CreateGrnData } from '@/types/inventory';

export interface ReceiveGoodsData {
  purchase_order_id: string;
  received_date?: string;
  remarks?: string;
  items: Array<{
    purchase_order_item_id: string;
    item_id: string;
    location_id: string;
    quantity_received: string;
    unit_cost?: string;
    remarks?: string;
  }>;
  qc: {
    result: 'passed' | 'failed' | 'passed_with_remarks' | 'pending';
    inspector_id?: string;
    checks?: Array<{ label: string; passed: boolean }>;
    remarks?: string;
    failure_reason?: string;
    disposition?: string;
  };
}

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
  receiveGoods: (data: ReceiveGoodsData) =>
    client.post<{ data: GoodsReceiptNote; qc_result: string; disposition: string | null; stock_updated: boolean }>('/inventory/receive-goods', data).then((r) => r.data),
};
