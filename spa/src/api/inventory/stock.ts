import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { StockAdjustment, StockLevel, StockMovement } from '@/types/inventory';

export const stockLevelsApi = {
 list: (params?: ListParams & { item_id?: string; warehouse_id?: string; item_type?: string; low_only?: boolean }) =>
 client.get<PaginatedResponse<StockLevel>>('/inventory/stock-levels', { params }).then((r) => r.data),
};

export const stockMovementsApi = {
 options: () => client.get<{ data: { movement_types: Array<{ value: string; label: string }> } }>('/inventory/stock-movements/options').then((r) => r.data.data),
 list: (params?: ListParams & { item_id?: string; movement_type?: string; from?: string; to?: string; reference_type?: string }) =>
 client.get<PaginatedResponse<StockMovement>>('/inventory/stock-movements', { params }).then((r) => r.data),
};

// `direction` here is the adjustment direction, not a sort order — the backend
// validates it as in|out and exposes no sort-direction param on this endpoint,
// so the base ListParams meaning is deliberately omitted rather than widened.
export interface StockAdjustmentListParams extends Omit<ListParams, 'direction'> {
 status?: 'pending' | 'approved';
 direction?: 'in' | 'out';
}

export const stockAdjustmentsApi = {
 list: (params?: StockAdjustmentListParams) =>
 client.get<PaginatedResponse<StockAdjustment>>('/inventory/stock-adjustments', { params }).then((r) => r.data),
 create: (data: { item_id: string; location_id: string; direction: 'in' | 'out'; quantity: string; unit_cost?: string; reason: string }) =>
 client.post<ApiSuccess<StockMovement>>('/inventory/stock-adjustments', data).then((r) => r.data.data),
 approve: (id: string) =>
 client.patch<ApiSuccess<StockAdjustment>>(`/inventory/stock-adjustments/${id}/approve`).then((r) => r.data.data),
};

export const stockTransfersApi = {
 create: (data: { item_id: string; from_location_id: string; to_location_id: string; quantity: string; remarks?: string }) =>
 client.post<ApiSuccess<StockMovement>>('/inventory/stock-transfers', data).then((r) => r.data.data),
};
