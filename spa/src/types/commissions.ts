export type CommissionEarningStatus = 'pending' | 'approved' | 'paid';

export interface CommissionEarning {
  id: string;
  sales_order: { id: string; so_number: string };
  employee: { id: string; first_name: string; last_name: string };
  order_total: string;
  commission_rate: string;
  commission_amount: string;
  status: CommissionEarningStatus;
  approved_by: string | null;
  approved_at: string | null;
  paid_at: string | null;
  created_at: string;
}

export interface CommissionRate {
  id: string;
  employee: { id: string; first_name: string; last_name: string };
  rate: string;
  effective_from: string;
  effective_until: string | null;
  created_at: string;
}

export interface CreateCommissionRateData {
  employee_id: string;
  rate: string;
  effective_from: string;
}
