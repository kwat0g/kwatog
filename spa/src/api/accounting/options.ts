import { client } from '@/api/client';

export const accountingOptionsApi = {
  list: () => client.get<{ data: {
    payment_methods: Array<{ value: string; label: string }>;
    account_types: Array<{ value: string; label: string; default_normal_balance: string }>;
    normal_balances: Array<{ value: string; label: string }>;
    credit_note_types: Array<{ value: string; label: string }>;
    credit_note_statuses: Array<{ value: string; label: string }>;
  } }>('/accounting/options').then((r) => r.data.data),
};
