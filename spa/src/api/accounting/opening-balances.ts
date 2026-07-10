import { client } from '../client';

export interface OpeningBalanceLine {
  account_id: string; // hashid
  debit: string;
  credit: string;
}

export interface TbMatchRow {
  account_code: string;
  account_name: string;
  legacy_debit: string;
  legacy_credit: string;
  system_debit: string;
  system_credit: string;
  variance: string;
}

export interface TbMatchResult {
  balanced: boolean;
  rows: TbMatchRow[];
  legacy_total_debit: string;
  legacy_total_credit: string;
  system_total_debit: string;
  system_total_credit: string;
}

/**
 * REC-05 — go-live opening balances. The GL loader rejects an unbalanced legacy
 * trial balance (422); tb-match reconciles the system TB against the legacy TB.
 */
export const openingBalancesApi = {
  postGl: (date: string, lines: OpeningBalanceLine[]) =>
    client
      .post<{ data: unknown; message: string }>('/accounting/opening-balances/gl', { date, lines })
      .then((r) => r.data),

  tbMatch: (lines: OpeningBalanceLine[], as_of?: string) =>
    client
      .post<{ data: TbMatchResult }>('/accounting/opening-balances/tb-match', { lines, as_of })
      .then((r) => r.data.data),
};
