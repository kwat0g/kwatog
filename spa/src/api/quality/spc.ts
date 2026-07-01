import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type {
  SpcControlChart,
  SpcDataPoint,
  SpcAlert,
  CreateSpcChartData,
  RunCapabilityData,
  SpcCapabilityResult,
  SpcChartStatus,
} from '@/types/quality/spc';

export interface SpcChartListParams extends ListParams {
  product_id?: string;
  status?: SpcChartStatus;
}

export interface SpcAlertListParams extends ListParams {
  chart_id?: string;
}

export const spcApi = {
  /** List SPC control charts (paginated). */
  listCharts: (params?: SpcChartListParams) =>
    client
      .get<PaginatedResponse<SpcControlChart>>('/quality/spc/charts', { params })
      .then((r) => r.data),

  /** Create a new control chart. */
  createChart: (data: CreateSpcChartData) =>
    client
      .post<ApiSuccess<SpcControlChart>>('/quality/spc/charts', data)
      .then((r) => r.data.data),

  /** Show a single chart with recent data points. */
  showChart: (id: string) =>
    client
      .get<ApiSuccess<SpcControlChart>>(`/quality/spc/charts/${id}`)
      .then((r) => r.data.data),

  /** Paginated data points for a chart. */
  getChartData: (id: string, params?: ListParams) =>
    client
      .get<PaginatedResponse<SpcDataPoint>>(`/quality/spc/charts/${id}/data`, { params })
      .then((r) => r.data),

  /** Force recalculation of control limits. */
  recalculate: (id: string) =>
    client
      .post<ApiSuccess<SpcControlChart>>(`/quality/spc/charts/${id}/recalculate`)
      .then((r) => r.data.data),

  /** Run a capability study (Cp/Cpk). */
  runCapability: (data: RunCapabilityData) =>
    client
      .post<ApiSuccess<SpcCapabilityResult>>('/quality/spc/capability', data)
      .then((r) => r.data.data),

  /** List unresolved alerts (paginated). */
  listAlerts: (params?: SpcAlertListParams) =>
    client
      .get<PaginatedResponse<SpcAlert>>('/quality/spc/alerts', { params })
      .then((r) => r.data),

  /** Acknowledge (resolve) an alert. */
  acknowledgeAlert: (id: string, data: { notes?: string }) =>
    client
      .post<ApiSuccess<SpcAlert>>(`/quality/spc/alerts/${id}/acknowledge`, data)
      .then((r) => r.data.data),
};
