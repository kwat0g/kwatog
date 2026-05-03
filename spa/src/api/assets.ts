import { client } from './client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Asset, AssetCategory, AssetStatus, CreateAssetData, DisposeAssetData } from '@/types/assets';

export interface AssetListParams extends ListParams {
  category?: AssetCategory;
  status?: AssetStatus;
  department_id?: string;
}

export const assetsApi = {
  list: (params?: AssetListParams) =>
    client.get<PaginatedResponse<Asset>>('/assets', { params }).then(r => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Asset>>(`/assets/${id}`).then(r => r.data.data),
  create: (data: CreateAssetData) =>
    client.post<ApiSuccess<Asset>>('/assets', data).then(r => r.data.data),
  update: (id: string, data: Partial<CreateAssetData>) =>
    client.put<ApiSuccess<Asset>>(`/assets/${id}`, data).then(r => r.data.data),
  destroy: (id: string) =>
    client.delete(`/assets/${id}`),
  dispose: (id: string, data: DisposeAssetData) =>
    client.post<ApiSuccess<Asset>>(`/assets/${id}/dispose`, data).then(r => r.data.data),
  qr: (id: string) =>
    client.get<ApiSuccess<{ asset_code: string; name: string; url: string }>>(`/assets/${id}/qr`).then(r => r.data.data),
};

export const depreciationApi = {
  list: (params?: { asset_id?: string; year?: number; month?: number; per_page?: number; page?: number }) =>
    client.get('/asset-depreciations', { params }).then(r => r.data),
  runMonth: (year: number, month: number) =>
    client.post('/asset-depreciations/run', { year, month }).then(r => r.data),
};
