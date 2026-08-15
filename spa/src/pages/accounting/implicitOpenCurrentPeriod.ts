import type { AccountingPeriod } from '@/api/accounting/periods';

/** The API creates the row when this currently-open month is closed. */
export function implicitOpenCurrentPeriod(now = new Date()): AccountingPeriod {
 const year = now.getFullYear();
 const month = now.getMonth() + 1;

 return {
  id: `implicit-${year}-${month}`,
  year,
  month,
  status: 'open',
  status_label: 'Open',
  closed_at: null,
  closed_by: null,
  reopened_at: null,
  reopened_by: null,
  reopen_reason: null,
  created_at: null,
  updated_at: null,
 };
}
