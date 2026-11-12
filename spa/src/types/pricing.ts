import type { PriceAgreement } from './crm';

export interface PriceOffer {
  price: string;
  pricing_method: 'flat' | 'tiered';
  tiers: Array<{ min_qty: number; unit_price: string }> | null;
  effective_from: string;
  effective_to: string;
  notes: string;
}

export interface PriceRequest {
  id: string;
  product: NonNullable<PriceAgreement['product']>;
  customer: { id: string; name: string };
  quantity: number | null;
  requested_price: string | null;
  order_limit_increase_request?: {
    current_maximum_quantity: string;
    requested_quantity: string;
  } | null;
  notes: string;
  status:
    | 'requested'
    | 'pending_finance'
    | 'offered'
    | 'accepted'
    | 'rejected'
    | 'sales_rejected'
    | 'customer_cancelled'
    | 'withdrawn'
    | 'expired';
  version: number;
  available_actions?: {
    can_propose: boolean;
    can_approve: boolean;
    can_withdraw: boolean;
    can_accept: boolean;
    can_reject: boolean;
    can_cancel?: boolean;
  };
  permissions?: { view: boolean; propose: boolean; approve: boolean; withdraw: boolean };
  decision_reason?: string | null;
  cost_review?: {
    signature: string;
    cost: string;
    incomplete: boolean;
    minimum_price: string | null;
    base_price: string | null;
  };
  offer?: PriceOffer | null;
  history?: Array<{ action: string; at: string; data: { notes?: string } }>;
}

export interface ItemCostReview {
  version: number;
  history: Array<{ version: number; notes: string | null; renewal_reason: string }>;
  id: string;
  item: { id: string; code: string; name: string; unit_of_measure: string };
  receipt_number: string;
  previous_cost: string;
  proposed_cost: string;
  current_cost: string;
  status: 'pending' | 'approved' | 'rejected';
  updated_at?: string;
  notes: string | null;
  permissions?: { view: boolean; review: boolean; approve: boolean };
  available_actions?: { approve: boolean; reject: boolean; renew: boolean };
}
