import { client } from '../client';
import type { ApiSuccess } from '@/types';
import type { Warehouse, WarehouseZone, WarehouseLocation } from '@/types/inventory';

export const warehouseApi = {
  tree: () =>
    client.get<{ data: Warehouse[] }>('/inventory/warehouse').then((r) => r.data.data),
  listWarehouses: () =>
    client.get<{ data: Warehouse[] }>('/inventory/warehouses').then((r) => r.data.data),
  createWarehouse: (data: { name: string; code: string; address?: string; is_active?: boolean }) =>
    client.post<ApiSuccess<Warehouse>>('/inventory/warehouses', data).then((r) => r.data.data),
  updateWarehouse: (id: string, data: Partial<{ name: string; code: string; address: string | null; is_active: boolean }>) =>
    client.put<ApiSuccess<Warehouse>>(`/inventory/warehouses/${id}`, data).then((r) => r.data.data),
  deleteWarehouse: (id: string) => client.delete(`/inventory/warehouses/${id}`),

  createZone: (data: { warehouse_id: number; name: string; code: string; zone_type: string }) =>
    client.post<ApiSuccess<WarehouseZone>>('/inventory/zones', data).then((r) => r.data.data),
  updateZone: (id: string, data: Partial<{ warehouse_id: number; name: string; code: string; zone_type: string }>) =>
    client.put<ApiSuccess<WarehouseZone>>(`/inventory/zones/${id}`, data).then((r) => r.data.data),
  deleteZone: (id: string) => client.delete(`/inventory/zones/${id}`),

  createLocation: (data: { zone_id: number; code: string; rack?: string; bin?: string; is_active?: boolean }) =>
    client.post<ApiSuccess<WarehouseLocation>>('/inventory/locations', data).then((r) => r.data.data),
  updateLocation: (id: string, data: Partial<{ zone_id: number; code: string; rack: string | null; bin: string | null; is_active: boolean }>) =>
    client.put<ApiSuccess<WarehouseLocation>>(`/inventory/locations/${id}`, data).then((r) => r.data.data),
  deleteLocation: (id: string) => client.delete(`/inventory/locations/${id}`),
};
