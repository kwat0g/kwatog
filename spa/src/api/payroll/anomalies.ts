/** Task A9 — Payroll anomaly flags API client. */
import { client } from '../client';
import type { PayrollAnomalyFlag } from '@/types/payroll';

interface PaginatedFlags {
  data: PayrollAnomalyFlag[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export const payrollAnomaliesApi = {
  list: (periodId: string, params?: { is_resolved?: boolean; flag_type?: string; per_page?: number; page?: number }) =>
    client.get<PaginatedFlags>(`/payroll-periods/${periodId}/anomalies`, { params }).then((r) => r.data),

  resolve: (flagId: string, remarks: string) =>
    client
      .patch<{ data: PayrollAnomalyFlag }>(`/payroll-anomalies/${flagId}/resolve`, { remarks })
      .then((r) => r.data.data),
};
