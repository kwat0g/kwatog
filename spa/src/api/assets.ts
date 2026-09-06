import { client } from './client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Asset, AssetCategory, AssetStatus, CreateAssetData, DisposeAssetData, UpdateAssetData, AssetTransfer, AssetTransferStatus, CreateTransferData } from '@/types/assets';

export interface AssetListParams extends ListParams {
 category?: AssetCategory;
 status?: AssetStatus;
 department_id?: string;
}

export const assetsApi = {
 options: () => client.get<ApiSuccess<{ categories: Array<{ value: AssetCategory; label: string }>; statuses: Array<{ value: AssetStatus; label: string }> }>>('/assets/options').then(r => r.data.data),
 list: (params?: AssetListParams) =>
 client.get<PaginatedResponse<Asset>>('/assets', { params }).then(r => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<Asset>>(`/assets/${id}`).then(r => r.data.data),
 create: (data: CreateAssetData) =>
 client.post<ApiSuccess<Asset>>('/assets', data).then(r => r.data.data),
 update: (id: string, data: UpdateAssetData) =>
 client.put<ApiSuccess<Asset>>(`/assets/${id}`, data).then(r => r.data.data),
 destroy: (id: string) =>
 client.delete(`/assets/${id}`),
 restore: (id: string) =>
 client.patch(`/assets/${id}/restore`),
  dispose: (id: string, data: DisposeAssetData) =>
   client.post<ApiSuccess<Asset>>(`/assets/${id}/dispose`, data).then(r => r.data.data),
  // AS-03 — disposal is approval-gated: dispose only opens the request;
  // the JE posts from approve once the chain completes.
  approveDisposal: (id: string, remarks?: string) =>
   client.post<ApiSuccess<Asset>>(`/assets/${id}/dispose/approve`, { remarks }).then(r => r.data.data),
  rejectDisposal: (id: string, reason: string) =>
   client.post<ApiSuccess<Asset>>(`/assets/${id}/dispose/reject`, { reason }).then(r => r.data.data),
  cancelDisposal: (id: string) =>
   client.post<ApiSuccess<Asset>>(`/assets/${id}/dispose/cancel`).then(r => r.data.data),
  qr: (id: string) =>
   client.get<ApiSuccess<{ asset_code: string; name: string; url: string }>>(`/assets/${id}/qr`).then(r => r.data.data),
};

export const depreciationApi = {
 list: (params?: { asset_id?: string; year?: number; month?: number; per_page?: number; page?: number }) =>
 client.get('/asset-depreciations', { params }).then(r => r.data),
 runMonth: (year: number, month: number) =>
 client.post('/asset-depreciations/run', { year, month }).then(r => r.data),
};

/* ── Asset Transfers ── */

export interface AssetTransferListParams extends ListParams {
 status?: AssetTransferStatus;
}

export const assetTransfersApi = {
 options: () => client.get<ApiSuccess<{ statuses: Array<{ value: AssetTransferStatus; label: string }> }>>('/asset-transfers/options').then(r => r.data.data),
 list: (params?: AssetTransferListParams) =>
 client.get<PaginatedResponse<AssetTransfer>>('/asset-transfers', { params }).then(r => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<AssetTransfer>>(`/asset-transfers/${id}`).then(r => r.data.data),
 create: (data: CreateTransferData) =>
 client.post<ApiSuccess<AssetTransfer>>('/asset-transfers', data).then(r => r.data.data),
 approve: (id: string) =>
 client.post<ApiSuccess<AssetTransfer>>(`/asset-transfers/${id}/approve`).then(r => r.data.data),
 reject: (id: string) =>
 client.post<ApiSuccess<AssetTransfer>>(`/asset-transfers/${id}/reject`).then(r => r.data.data),
};
