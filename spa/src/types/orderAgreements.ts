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
  can_publish: boolean;
  can_record_external: boolean;
  can_acknowledge: boolean;
  revisions: OrderAgreement[];
}
