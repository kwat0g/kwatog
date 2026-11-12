export type CustomerWarrantyIssue = 'manufacturing_defect' | 'shipping_damage' | 'customer_caused_damage';
export type CustomerWarrantyRemedy = 'repair' | 'replace' | 'credit' | 'refund';

export interface ProductWarrantySuggestion {
  productReference: string;
  label: string;
  durationDays: number;
  reportWindowDays?: number;
  startBasis?: 'delivery' | 'invoice';
  coveredIssues: CustomerWarrantyIssue[];
  remedies: CustomerWarrantyRemedy[];
}

export function normalizeProductReference(reference: string): string {
  return reference.normalize('NFKC').trim().replace(/\s+/g, ' ').toLowerCase();
}

export function productReferencesMatch(left: string, right: string): boolean {
  return normalizeProductReference(left) === normalizeProductReference(right);
}
