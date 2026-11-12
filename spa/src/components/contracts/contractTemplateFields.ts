export type PortalContractRealm = 'customer' | 'supplier';

export interface ContractRecordField {
  key: string;
  label: string;
}

const customerFields: ContractRecordField[] = [
  { key: 'customer_name', label: 'Customer name' },
  { key: 'customer_contact_name', label: 'Customer contact name' },
  { key: 'customer_contact_role', label: 'Customer contact role' },
  { key: 'customer_email', label: 'Customer email' },
  { key: 'customer_phone', label: 'Customer phone' },
  { key: 'customer_address', label: 'Customer address' },
  { key: 'delivery_address', label: 'Delivery address' },
  { key: 'delivery_map_pin', label: 'Delivery map pin' },
  { key: 'payment_terms', label: 'Payment terms' },
  { key: 'allowed_payment_methods', label: 'Allowed payment methods' },
  { key: 'shipping_payer', label: 'Freight paid by' },
  { key: 'effective_date', label: 'Agreement start date' },
  { key: 'expiry_date', label: 'Agreement end date' },
  { key: 'allowed_products_summary', label: 'Allowed products' },
  { key: 'warranty_summary', label: 'Warranty terms' },
];

const supplierFields: ContractRecordField[] = [
  { key: 'vendor_name', label: 'Supplier name' },
  { key: 'vendor_contact_name', label: 'Supplier contact name' },
  { key: 'vendor_email', label: 'Supplier email' },
  { key: 'vendor_phone', label: 'Supplier phone' },
  { key: 'vendor_address', label: 'Supplier address' },
  { key: 'payment_terms', label: 'Payment terms' },
  { key: 'effective_date', label: 'Agreement start date' },
  { key: 'expiry_date', label: 'Agreement end date' },
];

export function contractRecordFields(realm: PortalContractRealm): ContractRecordField[] {
  return realm === 'customer' ? customerFields : supplierFields;
}

export interface DiscoveredContractField {
  name: string;
  type: string | null;
}

export interface ContractTemplateSubmission {
  name: string;
  realm: PortalContractRealm;
  file: File;
  field_mappings: Record<string, string>;
  required_fields: string[];
}
