/**
 * ADV11 — Demand & Sales Forecasting API client.
 */
import { client } from './client';
import type {
 DemandForecast,
 ForecastMethod,
 ForecastAccuracy,
 HistoricalDemandPoint,
 ProductAccuracy,
 StockOutResponse,
 ForecastingSettings,
} from '@/types/forecasting';
import type { Product } from '@/types/crm';

export const forecastingApi = {
 options: () => client.get<{ data: { methods: Array<{ value: 'moving_avg' | 'weighted_avg'; label: string }>; demand_sources: Array<{ value: string; label: string }>; accuracy_policy: { excellent_mape: number; acceptable_mape: number } } }>('/forecasting/demand-forecasts/options').then((r) => r.data.data),
 settings: () => client.get<{ data: ForecastingSettings }>('/forecasting/settings').then((r) => r.data.data),
 list: (params?: {
 product_id?: string;
 customer_id?: string;
 year?: number;
 method?: ForecastMethod;
 }) =>
 client
 .get<{ data: DemandForecast[] }>('/forecasting/demand-forecasts', { params })
 .then((r) => r.data.data),

 historical: (params: {
 product_id: string;
 customer_id?: string;
 months_back?: number;
 }) =>
 client
 .get<{ data: HistoricalDemandPoint[] }>('/forecasting/demand-forecasts/historical', { params })
 .then((r) => r.data.data),

 recompute: (payload: {
 product_id: string;
 customer_id?: string;
 method: 'moving_avg' | 'weighted_avg';
 horizon_months?: number;
 lookback_months?: number;
 }) =>
 client
 .post<{ data: DemandForecast[]; message: string }>(
 '/forecasting/demand-forecasts/recompute',
 payload,
 )
 .then((r) => r.data),

 storeManual: (payload: {
 product_id: string;
 customer_id?: string;
 forecast_year: number;
 forecast_month: number;
 forecasted_quantity: number;
 confidence_level?: number;
 }) =>
 client
 .post<{ data: DemandForecast; message: string }>(
 '/forecasting/demand-forecasts/manual',
 payload,
 )
 .then((r) => r.data),

 stockOut: (params?: { horizon_days?: number }) =>
 client
 .get<StockOutResponse>('/forecasting/stock-out', { params })
 .then((r) => r.data),

 accuracy: (year: number) =>
 client
 .get<{ data: ForecastAccuracy }>(`/forecasting/accuracy?year=${year}`)
 .then((r) => r.data),

 accuracySummary: (year?: number) =>
 client
 .get<{ data: ForecastAccuracy }>('/forecasting/accuracy/summary', { params: { year } })
 .then((r) => r.data.data),

 accuracyByProduct: (year?: number) =>
 client
 .get<{ data: ProductAccuracy[] }>('/forecasting/accuracy/products', { params: { year } })
 .then((r) => r.data.data),

 updateMrpInclusion: (productId: string, include: boolean) =>
 client
 .patch<{ data: Product }>(`/forecasting/products/${productId}/mrp-inclusion`, {
 include_forecast_in_mrp: include,
 })
 .then((r) => r.data.data),
};
