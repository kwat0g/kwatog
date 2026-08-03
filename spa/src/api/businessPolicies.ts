import { client } from './client';
import type { ApiSuccess } from '@/types';

export interface BusinessPolicies {
  customer_payment_terms_days: number;
  vendor_payment_terms_days: number;
  sales_delivery_lead_days: number;
  mrp_default_lead_time_days: number;
  mrp_work_order_normal_priority: number;
  purchase_order_vp_threshold: number;
  functional_currency_code: string;
  reporting_currency_code: string;
  translation_adjustment_account_code: string;
  vat_rate: string;
  vat_status: string;
}

export const businessPoliciesApi = {
  get: () => client.get<ApiSuccess<BusinessPolicies>>('/business-policies').then((response) => response.data.data),
};
