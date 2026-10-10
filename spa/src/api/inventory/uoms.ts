import { client } from '../client';
import type { ApiSuccess } from '@/types';

export interface UomOption {
 id: string;
 code: string;
 name: string;
 deleted_at: string | null;
}

export interface ItemUomConversion {
 id: string;
 item_id: string;
 from_uom: UomOption;
 to_uom: UomOption;
 factor: string;
 locked_by_history: boolean;
 created_at: string | null;
 updated_at: string | null;
}

export const uomsApi = {
 list: () => client.get<{ data: UomOption[] }>('/inventory/uoms').then((r) => r.data.data),
 listArchived: (trashed: 'with' | 'only') =>
  client.get<{ data: UomOption[] }>('/inventory/uoms', { params: { trashed } }).then((r) => r.data.data),
 create: (data: Pick<UomOption, 'code' | 'name'>) =>
  client.post<ApiSuccess<UomOption>>('/inventory/uoms', data).then((r) => r.data.data),
 update: (id: string, data: Pick<UomOption, 'code' | 'name'>) =>
  client.put<ApiSuccess<UomOption>>(`/inventory/uoms/${id}`, data).then((r) => r.data.data),
 delete: (id: string) => client.delete(`/inventory/uoms/${id}`),
 restore: (id: string) => client.patch(`/inventory/uoms/${id}/restore`),
 conversions: (itemId: string) =>
  client.get<{ data: ItemUomConversion[] }>(`/inventory/items/${itemId}/uom-conversions`).then((r) => r.data.data),
 saveConversion: (itemId: string, data: { from_uom_id: string; to_uom_id: string; factor: string }) =>
  client.post<ApiSuccess<ItemUomConversion>>(`/inventory/items/${itemId}/uom-conversions`, data).then((r) => r.data.data),
 deleteConversion: (itemId: string, conversionId: string) =>
  client.delete(`/inventory/items/${itemId}/uom-conversions/${conversionId}`),
};
