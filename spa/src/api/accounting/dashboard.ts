import { client } from '../client';
import type { ApiSuccess } from '@/types';
import type { FinanceDashboardSummary } from '@/types/accounting';

export const financeDashboardApi = {
  summary: () =>
    client.get<ApiSuccess<FinanceDashboardSummary>>('/dashboard/finance').then((r) => r.data.data),
};
