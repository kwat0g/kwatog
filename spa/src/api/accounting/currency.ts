import { client } from '../client';
import type { ApiSuccess, PaginatedResponse } from '@/types';
import type {
 CreateFxRateData,
 FxRate,
 TranslatedBalanceSheet,
 TranslatedIncomeStatement,
 TranslatedTrialBalance,
} from '@/types/accounting';

export interface FxRateListParams {
 currency_code?: string;
 per_page?: number;
 page?: number;
}
export interface TranslatedRangeParams {
 from?: string;
 to?: string;
 currency?: string;
}
export interface TranslatedAsOfParams {
 as_of?: string;
 currency?: string;
}

export const currencyApi = {
 listRates: (params?: FxRateListParams) =>
 client.get<PaginatedResponse<FxRate>>('/accounting/currency/fx-rates', { params }).then((r) => r.data),
 storeRate: (data: CreateFxRateData) =>
 client.post<ApiSuccess<FxRate>>('/accounting/currency/fx-rates', data).then((r) => r.data.data),
 trialBalance: (params: TranslatedRangeParams) =>
 client.get<ApiSuccess<TranslatedTrialBalance>>('/accounting/currency/trial-balance', { params }).then((r) => r.data.data),
 incomeStatement: (params: TranslatedRangeParams) =>
 client.get<ApiSuccess<TranslatedIncomeStatement>>('/accounting/currency/income-statement', { params }).then((r) => r.data.data),
 balanceSheet: (params: TranslatedAsOfParams) =>
 client.get<ApiSuccess<TranslatedBalanceSheet>>('/accounting/currency/balance-sheet', { params }).then((r) => r.data.data),
};
