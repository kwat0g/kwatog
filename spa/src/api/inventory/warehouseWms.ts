import { client } from '../client';
import type { ApiSuccess } from '@/types';
import type {
  WarehouseMap,
  WarehouseMapLocation,
  StockCountSession,
  StockCountItem,
  TransferOrder,
  PickingList,
} from '@/types/warehouse';

export const warehouseMapApi = {
  map: () =>
    client.get<{ data: WarehouseMap[] }>('/inventory/warehouse-map').then((r) => r.data.data),
  binDetail: (id: string) =>
    client.get<{ data: { location: WarehouseMapLocation; stock_levels: any[]; last_movement: any } }>(`/inventory/warehouse-map/bins/${id}`).then((r) => r.data.data),
};

export const stockCountApi = {
  list: (params?: { page?: number; per_page?: number }) =>
    client.get<{ data: StockCountSession[] }>('/inventory/stock-counts', { params }).then((r) => r.data.data),
  get: (id: string) =>
    client.get<{ data: StockCountSession }>(`/inventory/stock-counts/${id}`).then((r) => r.data.data),
  create: (data: { title: string; scope: string; warehouse_id?: string; zone_id?: string }) =>
    client.post<ApiSuccess<StockCountSession>>('/inventory/stock-counts', data).then((r) => r.data.data),
  start: (id: string) =>
    client.post<ApiSuccess<StockCountSession>>(`/inventory/stock-counts/${id}/start`).then((r) => r.data.data),
  recordCount: (itemId: string, data: { counted_quantity: string; lot_number?: string; notes?: string }) =>
    client.post<ApiSuccess<StockCountItem>>(`/inventory/stock-counts/items/${itemId}/count`, data).then((r) => r.data.data),
  approveVariance: (itemId: string) =>
    client.post<ApiSuccess<StockCountItem>>(`/inventory/stock-counts/items/${itemId}/approve`).then((r) => r.data.data),
  complete: (id: string) =>
    client.post<ApiSuccess<StockCountSession>>(`/inventory/stock-counts/${id}/complete`).then((r) => r.data.data),
  cancel: (id: string) => client.delete(`/inventory/stock-counts/${id}`),
};

export const transferOrderApi = {
  list: (params?: { page?: number; per_page?: number }) =>
    client.get<{ data: TransferOrder[] }>('/inventory/transfer-orders', { params }).then((r) => r.data.data),
  get: (id: string) =>
    client.get<{ data: TransferOrder }>(`/inventory/transfer-orders/${id}`).then((r) => r.data.data),
  create: (data: { from_location_id: string; to_location_id: string; item_id: string; quantity: string; reason?: string }) =>
    client.post<ApiSuccess<TransferOrder>>('/inventory/transfer-orders', data).then((r) => r.data.data),
  execute: (id: string) =>
    client.post<ApiSuccess<TransferOrder>>(`/inventory/transfer-orders/${id}/execute`).then((r) => r.data.data),
  cancel: (id: string) => client.delete(`/inventory/transfer-orders/${id}`),
};

export const pickingListApi = {
  forMis: (misId: string) =>
    client.get<{ data: PickingList }>(`/inventory/picking-lists/mis/${misId}`).then((r) => r.data.data),
};
