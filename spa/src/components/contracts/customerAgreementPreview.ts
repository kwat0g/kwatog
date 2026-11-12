import type { CustomerProductAccess, CustomerWarrantyOption } from '@/api/crm/customers';
import type { Customer } from '@/types/accounting';

const ISSUE_LABELS = {
  manufacturing_defect: 'manufacturing defect',
  shipping_damage: 'shipping damage',
  customer_caused_damage: 'customer-caused damage',
} as const;

const REMEDY_LABELS = {
  repair: 'repair',
  replace: 'replacement',
  credit: 'credit',
  refund: 'refund',
} as const;

function coordinate(value: number | string | null | undefined): number | null {
  if (value == null || String(value).trim() === '') return null;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

export function customerAgreementPreviewValues(
  customer: Customer,
  productAccess?: CustomerProductAccess,
  warrantyOptions: CustomerWarrantyOption[] = [],
): Record<string, string | null> {
  const latitude = coordinate(customer.delivery_latitude);
  const longitude = coordinate(customer.delivery_longitude);
  const hasDeliveryPin = latitude !== null && longitude !== null
    && latitude >= -90 && latitude <= 90
    && longitude >= -180 && longitude <= 180;
  const allowedProductsSummary = productAccess?.products
    .filter((product) => product.is_authorized)
    .map((product) => `${product.part_number} · ${product.name}`)
    .join('\n') || null;

  const warrantySummaryRows = warrantyOptions.map((option) => {
    const terms = [option.label, option.warranty_duration_days == null ? 'duration to be agreed' : `${option.warranty_duration_days} days`];
    if (option.warranty_start_basis) terms.push(`from ${option.warranty_start_basis}`);
    if (option.warranty_report_window_days != null) terms.push(`report within ${option.warranty_report_window_days} days`);
    if (option.warranty_covered_issues?.length) {
      terms.push(`covers ${option.warranty_covered_issues.map((issue) => ISSUE_LABELS[issue]).join(', ')}`);
    }
    if (option.warranty_remedies?.length) {
      terms.push(`remedies: ${option.warranty_remedies.map((remedy) => REMEDY_LABELS[remedy]).join(', ')}`);
    }
    if (option.additional_terms) terms.push(option.additional_terms);
    return `${option.product.part_number} · ${option.product.name}: ${terms.join('; ')}`;
  });

  if (customer.warranty_duration_days != null || customer.warranty_report_window_days != null
    || customer.warranty_covered_issues?.length || customer.warranty_remedies?.length) {
    const fallback = [
      customer.warranty_duration_days == null ? null : `${customer.warranty_duration_days} days`,
      customer.warranty_start_basis ? `from ${customer.warranty_start_basis}` : null,
      customer.warranty_report_window_days == null ? null : `report within ${customer.warranty_report_window_days} days`,
      customer.warranty_covered_issues?.length
        ? `covers ${customer.warranty_covered_issues.map((issue) => ISSUE_LABELS[issue]).join(', ')}` : null,
      customer.warranty_remedies?.length
        ? `remedies: ${customer.warranty_remedies.map((remedy) => REMEDY_LABELS[remedy]).join(', ')}` : null,
    ].filter(Boolean).join('; ');
    warrantySummaryRows.push(`Customer fallback (only where no product option applies): ${fallback || 'terms configured'}`);
  }

  const allowedPaymentMethods = customer.allowed_payment_methods === null
    ? 'All supported payment methods'
    : customer.allowed_payment_methods.map((method) => ({ cash: 'Cash', check: 'Check', bank_transfer: 'Bank transfer' })[method]).join(', ');

  return {
    customer_name: customer.name,
    customer_contact_name: customer.contact_person,
    customer_contact_role: customer.contact_person_role ?? null,
    customer_email: customer.email,
    customer_phone: customer.phone,
    customer_address: customer.address,
    delivery_address: customer.delivery_address,
    delivery_map_pin: hasDeliveryPin ? `${latitude!.toFixed(7)}, ${longitude!.toFixed(7)}` : null,
    payment_terms: customer.payment_terms_days == null ? null : `${customer.payment_terms_days} days`,
    allowed_payment_methods: allowedPaymentMethods,
    shipping_payer: customer.shipping_payer ? ({ customer: 'Customer', company: 'Company' }[customer.shipping_payer]) : null,
    effective_date: customer.agreement_starts_on,
    expiry_date: customer.agreement_ends_on,
    allowed_products_summary: allowedProductsSummary,
    warranty_summary: warrantySummaryRows.length ? warrantySummaryRows.join('\n') : null,
  };
}
