import { client } from '../client';
import type { ApiSuccess } from '@/types';
import type { Warehouse, WarehouseZone, WarehouseLocation } from '@/types/inventory';

export interface CreateWarehouseData {
  name: string;
  code: string;
  address?: string | null;
  is_active?: boolean;
}

export interface CreateZoneData {
  warehouse_id: string;
  name: string;
  code: string;
  zone_type: string;
}

export interface CreateLocationData {
  zone_id: string;
  code: string;
  rack?: string | null;
  bin?: string | null;
  is_active?: boolean;
}

export const warehouseApi = {
  tree: () =>
    client.get<{ data: Warehouse[] }>('/inventory/warehouse').then((r) => r.data.data),
  listWarehouses: () =>
    client.get<{ data: Warehouse[] }>('/inventory/warehouses').then((r) => r.data.data),
  createWarehouse: (data: CreateWarehouseData) =>
    client.post<ApiSuccess<Warehouse>>('/inventory/warehouses', data).then((r) => r.data.data),
  updateWarehouse: (id: string, data: Partial<CreateWarehouseData>) =>
    client.put<ApiSuccess<Warehouse>>(`/inventory/warehouses/${id}`, data).then((r) => r.data.data),
  deleteWarehouse: (id: string) => client.delete(`/inventory/warehouses/${id}`),

  createZone: (data: CreateZoneData) =>
    client.post<ApiSuccess<WarehouseZone>>('/inventory/zones', data).then((r) => r.data.data),
  updateZone: (id: string, data: Partial<CreateZoneData>) =>
    client.put<ApiSuccess<WarehouseZone>>(`/inventory/zones/${id}`, data).then((r) => r.data.data),
  deleteZone: (id: string) => client.delete(`/inventory/zones/${id}`),

  createLocation: (data: CreateLocationData) =>
    client.post<ApiSuccess<WarehouseLocation>>('/inventory/locations', data).then((r) => r.data.data),
  updateLocation: (id: string, data: Partial<CreateLocationData>) =>
    client.put<ApiSuccess<WarehouseLocation>>(`/inventory/locations/${id}`, data).then((r) => r.data.data),
  deleteLocation: (id: string) => client.delete(`/inventory/locations/${id}`),
};
