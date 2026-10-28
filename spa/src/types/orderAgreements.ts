export interface OrderAgreement {
  id: string;
  revision: number;
  terms: string;
  snapshot: {
    customer_id: string;
    payment_terms_days: number;
    delivery_terms: string | null;
    incoterm: string | null;
    subtotal: string;
    vat_amount: string;
    total_amount: string;
    items: Array<{
      product_id: string;
      product_label: string;
      quantity: string;
      unit_price: string;
      delivery_date: string | null;
    }>;
  };
  published_at: string;
  published_by: string;
  file_name: string | null;
  acknowledgment: null | {
    method: 'portal' | 'external';
    representative: string;
    acknowledged_at: string;
    recorded_at: string;
    recorded_by: string;
    reference: string | null;
  };
  is_current: boolean;
  is_stale: boolean;
}

export interface OrderAgreements {
  sales_order_id: string;
  so_number: string;
  payment_method?: 'cash' | 'check' | 'bank_transfer' | null;
  customer_terms_snapshot?: CustomerTermsSnapshot | null;
  warranty_terms?: Array<{
    product_id: string;
    product_label: string;
    terms: WarrantyTermsSnapshot | null;
  }>;
  can_publish: boolean;
  can_record_external: boolean;
  can_acknowledge: boolean;
  revisions: OrderAgreement[];
}

export interface WarrantyTermsSnapshot {
  warranty_duration_days: number | null;
  warranty_report_window_days: number | null;
  warranty_start_basis: 'delivery' | 'invoice' | null;
  warranty_covered_issues: Array<'manufacturing_defect' | 'shipping_damage' | 'customer_caused_damage'> | null;
  warranty_remedies: Array<'repair' | 'replace' | 'credit' | 'refund'> | null;
}

export interface CustomerTermsSnapshot {
  agreement_status: 'not_recorded' | 'not_yet_effective' | 'in_effect' | 'expired';
  agreement_starts_on: string | null;
  agreement_ends_on: string | null;
  allowed_payment_methods: Array<'cash' | 'check' | 'bank_transfer'> | null;
  shipping_payer: 'customer' | 'company' | null;
  payment_terms_days: number;
  delivery_terms: string | null;
  incoterm: string | null;
  warranty_defaults: WarrantyTermsSnapshot | null;
}
