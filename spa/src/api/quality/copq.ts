import { client } from '../client';

export interface CopqTrendMonth {
  month: string;
  internal_scrap_cost: number;
  internal_rework_cost: number;
  external_return_cost: number;
  total_cost: number;
}

export interface CopqSummary {
  current_month: {
    internal_scrap_cost: number;
    internal_rework_cost: number;
    external_return_cost: number;
    total_cost: number;
    period_label: string;
  };
  ytd: {
    internal_scrap_cost: number;
    internal_rework_cost: number;
    external_return_cost: number;
    total_cost: number;
  };
}

export interface CopqByProduct {
  product_id: string;
  product_name: string;
  part_number: string;
  ncr_count: number;
  scrap_cost: number;
  rework_cost: number;
  total_cost: number;
}

export interface CopqBySupplier {
  vendor_id: string;
  vendor_name: string;
  ncr_count: number;
  defective_qty: number;
}

export interface CopqByParams {
  from?: string;
  to?: string;
  limit?: number;
}

export const copqApi = {
  trend: (months = 12) =>
    client.get<{ data: CopqTrendMonth[] }>('/quality/copq/trend', { params: { months } }).then((r) => r.data.data),
  summary: () =>
    client.get<{ data: CopqSummary }>('/quality/copq/summary').then((r) => r.data.data),
  byProduct: (params?: CopqByParams) =>
    client.get<{ data: CopqByProduct[] }>('/quality/copq/by-product', { params }).then((r) => r.data.data),
  bySupplier: (params?: CopqByParams) =>
    client.get<{ data: CopqBySupplier[] }>('/quality/copq/by-supplier', { params }).then((r) => r.data.data),
};
