/**
 * ADV11 — Demand & Sales Forecasting API client.
 */
import { client } from './client';
import type {
  DemandForecast,
  ForecastMethod,
  HistoricalDemandPoint,
  StockOutResponse,
} from '@/types/forecasting';

export const forecastingApi = {
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
};
