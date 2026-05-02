import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { StockLevel, StockMovement } from '@/types/inventory';

export const stockLevelsApi = {
  list: (params?: ListParams & { item_id?: string; warehouse_id?: string; item_type?: string; low_only?: boolean }) =>
    client.get<PaginatedResponse<StockLevel>>('/inventory/stock-levels', { params }).then((r) => r.data),
};

export const stockMovementsApi = {
  list: (params?: ListParams & { item_id?: string; movement_type?: string; from?: string; to?: string; reference_type?: string }) =>
    client.get<PaginatedResponse<StockMovement>>('/inventory/stock-movements', { params }).then((r) => r.data),
};

export const stockAdjustmentsApi = {
  create: (data: { item_id: string; location_id: string; direction: 'in' | 'out'; quantity: string; unit_cost?: string; reason: string }) =>
    client.post<ApiSuccess<StockMovement>>('/inventory/stock-adjustments', data).then((r) => r.data.data),
};

export const stockTransfersApi = {
  create: (data: { item_id: string; from_location_id: string; to_location_id: string; quantity: string; remarks?: string }) =>
    client.post<ApiSuccess<StockMovement>>('/inventory/stock-transfers', data).then((r) => r.data.data),
};
