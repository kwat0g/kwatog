import { client } from '../client';
import type { ApiSuccess } from '@/types';
import type { BalanceSheet, IncomeStatement, TrialBalance } from '@/types/accounting';

export interface DateRangeParams { from?: string; to?: string }
export interface AsOfParams { as_of?: string }

export const statementsApi = {
  trialBalance: (params: DateRangeParams) =>
    client.get<ApiSuccess<TrialBalance>>('/accounting/statements/trial-balance', { params }).then((r) => r.data.data),
  incomeStatement: (params: DateRangeParams) =>
    client.get<ApiSuccess<IncomeStatement>>('/accounting/statements/income-statement', { params }).then((r) => r.data.data),
  balanceSheet: (params: AsOfParams) =>
    client.get<ApiSuccess<BalanceSheet>>('/accounting/statements/balance-sheet', { params }).then((r) => r.data.data),
  csvUrl: (which: 'trial-balance' | 'income-statement' | 'balance-sheet', params: Record<string, string | undefined>) => {
    const qs = new URLSearchParams({ format: 'csv', ...Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined) as [string, string][]) });
    return `/api/v1/accounting/statements/${which}?${qs.toString()}`;
  },
  pdfUrl: (which: 'trial-balance' | 'income-statement' | 'balance-sheet', params: Record<string, string | undefined>) => {
    const qs = new URLSearchParams(Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined) as [string, string][]));
    return `/api/v1/accounting/statements/${which}/pdf?${qs.toString()}`;
  },
};
