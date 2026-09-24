import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { CreateVendorData, UpdateVendorData, Vendor } from '@/types/accounting';

export interface VendorListParams extends ListParams {
  is_active?: boolean | string;
}

export interface Bir2307Data {
  vendor: { id: string; name: string; tin: string | null; address: string | null };
  payor: { name: string; tin: string | null; address: string | null };
  year: number;
  quarter: number;
  income_payments: Array<{
    month: number;
    gross_amount: string;
    tax_withheld: string;
    atc: string | null;
  }>;
  summary: { total_gross: string; total_tax_withheld: string };
}

export const vendorsApi = {
  list: (params?: VendorListParams) =>
    client.get<PaginatedResponse<Vendor>>('/vendors', { params }).then((r) => r.data),
  show: (id: string) => client.get<ApiSuccess<Vendor>>(`/vendors/${id}`).then((r) => r.data.data),
  create: (data: CreateVendorData) =>
    client.post<ApiSuccess<Vendor>>('/vendors', data).then((r) => r.data.data),
  update: (id: string, data: UpdateVendorData) =>
    client.put<ApiSuccess<Vendor>>(`/vendors/${id}`, data).then((r) => r.data.data),
  delete: (id: string) => client.delete(`/vendors/${id}`),
  restore: (id: string) => client.patch(`/vendors/${id}/restore`),
  bir2307: (id: string, year: number, quarter: number) =>
    client
      .get<ApiSuccess<Bir2307Data>>(`/vendors/${id}/bir-2307`, { params: { year, quarter } })
      .then((r) => r.data.data),
  bir2307PdfUrl: (id: string, year: number, quarter: number) =>
    `/api/v1/vendors/${id}/bir-2307/pdf?year=${year}&quarter=${quarter}`,
};
