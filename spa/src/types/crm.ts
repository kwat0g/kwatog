// Sprint 6 — CRM types. IDs are hash strings; decimals are strings.

export interface Product {
  id: string;
  part_number: string;
  name: string;
  description: string | null;
  unit_of_measure: string;
  standard_cost: string;
  is_active: boolean;
  has_bom: boolean;
  created_at: string;
  updated_at: string;
}

export interface PriceAgreement {
  id: string;
  product?: { id: string; part_number: string; name: string; unit_of_measure: string };
  customer?: { id: string; name: string };
  price: string;
  effective_from: string;
  effective_to: string;
  is_currently_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface CreateProductData {
  part_number: string;
  name: string;
  description?: string | null;
  unit_of_measure: string;
  standard_cost: string;
  is_active?: boolean;
}

export type UpdateProductData = Partial<CreateProductData>;

export interface CreatePriceAgreementData {
  product_id: string;
  customer_id: string;
  price: string;
  effective_from: string;
  effective_to: string;
}

export type UpdatePriceAgreementData = Partial<CreatePriceAgreementData>;
