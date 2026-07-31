import { client } from './client';
import type { ApiSuccess } from '@/types';

export interface BusinessPolicies {
  customer_payment_terms_days: number;
  vendor_payment_terms_days: number;
  sales_delivery_lead_days: number;
  mrp_default_lead_time_days: number;
  purchase_order_vp_threshold: number;
  vat_rate: string;
}

export const businessPoliciesApi = {
  get: () => client.get<ApiSuccess<BusinessPolicies>>('/business-policies').then((response) => response.data.data),
};
