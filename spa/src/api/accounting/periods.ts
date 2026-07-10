import { client } from '../client';
import type { PaginatedResponse } from '@/types';

export type AccountingPeriodStatus = 'open' | 'closed' | 'reopened';

export interface AccountingPeriod {
  id: string;
  year: number;
  month: number;
  status: AccountingPeriodStatus;
  status_label: string;
  closed_at: string | null;
  closed_by: { id: string | null; name: string } | null;
  reopened_at: string | null;
  reopened_by: { id: string | null; name: string } | null;
  reopen_reason: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface PeriodListParams {
  status?: AccountingPeriodStatus;
  year?: number | string;
  per_page?: number;
}

/**
 * REC-14 — accounting period close / reopen. The close locks the month; reopen
 * requires a reason and is recorded on the period for the audit trail.
 */
export const accountingPeriodsApi = {
  list: (params?: PeriodListParams) =>
    client.get<PaginatedResponse<AccountingPeriod>>('/accounting/periods', { params }).then((r) => r.data),
  close: (year: number, month: number) =>
    client.post<{ data: AccountingPeriod }>('/accounting/periods/close', { year, month }).then((r) => r.data.data),
  reopen: (year: number, month: number, reason: string) =>
    client.post<{ data: AccountingPeriod }>('/accounting/periods/reopen', { year, month, reason }).then((r) => r.data.data),
};
