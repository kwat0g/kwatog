import { describe, expect, it } from 'vitest';
import { extractCustomerAgreementFields } from './customerAgreementPdfExtraction';

describe('customer agreement PDF extraction', () => {
  it('extracts labeled customer terms and keeps product warranties scoped to their products', () => {
    const agreementText = [
      'Customer Supply Agreement',
      'Customer legal name',
      'Demo Automotive Components, Inc.',
      'Authorized contact person',
      'Marissa Santos',
      'Contact role / title',
      'Procurement Manager',
      'Business email',
      'marissa.santos@example.test',
      'Business phone',
      '+63 2 8123 4567',
      'Billing address',
      '1200 Sample Avenue, Santa Rosa, Laguna',
      'Delivery / receiving address',
      'Warehouse Gate B, Biñan, Laguna',
      'Effective date (YYYY-MM-DD)',
      '2026-11-10',
      'Expiry date (YYYY-MM-DD)',
      '2027-11-09',
      'Customer default payment terms',
      'Net 30 calendar days from accepted invoice',
      'Payment methods accepted under this agreement',
      'Bank transfer or company check',
      'Freight paid by',
      'Buyer',
      'Warranty terms by product and available option',
      'PN-900 · 12-month standard: 365 days from delivery; report within 30 days; manufacturing defects and shipping damage; repair or replacement.',
      'PN-220 · 6-month limited: 180 days from delivery; report within 14 days; manufacturing defects; replacement of affected part.',
    ].join('\n');

    const result = extractCustomerAgreementFields(agreementText);

    expect(result.documentKind).toBe('customer-agreement');
    expect(result.fields).toEqual({
      name: 'Demo Automotive Components, Inc.',
      contact_person: 'Marissa Santos',
      contact_person_role: 'Procurement Manager',
      email: 'marissa.santos@example.test',
      phone: '+63 2 8123 4567',
      address: '1200 Sample Avenue, Santa Rosa, Laguna',
      delivery_address: 'Warehouse Gate B, Biñan, Laguna',
      agreement_starts_on: '2026-11-10',
      agreement_ends_on: '2027-11-09',
      payment_terms_days: 30,
      allowed_payment_methods: ['bank_transfer', 'check'],
      shipping_payer: 'customer',
    });

    expect(result.productWarranties).toEqual([
      {
        productReference: 'PN-900',
        label: '12-month standard',
        durationDays: 365,
        reportWindowDays: 30,
        startBasis: 'delivery',
        coveredIssues: ['manufacturing_defect', 'shipping_damage'],
        remedies: ['repair', 'replace'],
      },
      {
        productReference: 'PN-220',
        label: '6-month limited',
        durationDays: 180,
        reportWindowDays: 14,
        startBasis: 'delivery',
        coveredIssues: ['manufacturing_defect'],
        remedies: ['replace'],
      },
    ]);
    expect(result.productReferences).toEqual(['PN-900', 'PN-220']);
    expect(result.fields).not.toHaveProperty('warranty_duration_days');
  });

  it('extracts authorized products even when the agreement has no warranty row for one of them', () => {
    const result = extractCustomerAgreementFields([
      'Customer Supply Agreement',
      'Customer legal name: Demo Automotive Components, Inc.',
      'Products currently authorized for this customer',
      'PN-900 · Rear lamp assembly | Minimum order quantity: 5 units | Maximum order quantity per sales order: 250 units',
      'PN-220 · Body wiring harness | Minimum order quantity: 1 unit | Maximum order quantity per sales order: 80 units',
      'Warranty terms by product and available option',
      'PN-900 · 12-month standard: 365 days from delivery.',
    ].join('\n'));

    expect(result.productReferences).toEqual(['PN-900', 'PN-220']);
    expect(result.productOrderLimits).toEqual([
      { productReference: 'PN-900', minimumOrderQuantity: 5, maximumOrderQuantity: 250 },
      { productReference: 'PN-220', minimumOrderQuantity: 1, maximumOrderQuantity: 80 },
    ]);
  });

  it('extracts order quantity ranges from the example agreement wording', () => {
    const result = extractCustomerAgreementFields([
      'Customer Supply Agreement',
      'Customer legal name: Example Components Corporation',
      'Products currently authorized for this customer',
      'BB-001 · Wiper Motor Bobbin — minimum 10 / maximum 500 pcs per order',
      'BU-001 · Windshield Wiper Bushing — minimum 5 / maximum 300 pcs per order',
    ].join('\n'));

    expect(result.productOrderLimits).toEqual([
      { productReference: 'BB-001', minimumOrderQuantity: 10, maximumOrderQuantity: 500 },
      { productReference: 'BU-001', minimumOrderQuantity: 5, maximumOrderQuantity: 300 },
    ]);
  });

  it('recognizes a generic warranty PDF without converting it into customer-wide fallback terms', () => {
    const result = extractCustomerAgreementFields([
      'Warranty coverage: 12 months from delivery; report within 30 days; manufacturing defects; repair or replacement.',
      'Unlabeled text: Net 90 days',
      'Some date: 2026-02-03',
    ].join('\n'));

    expect(result.documentKind).toBe('warranty-schedule');
    expect(result.fields).toEqual({});
    expect(result.productWarranties).toEqual([]);
  });

  it('keeps side-by-side agreement fields separate and joins a wrapped warranty clause', () => {
    const result = extractCustomerAgreementFields([
      'Authorized contact person | Contact role / title',
      'Marissa Santos | Procurement Manager',
      'Payment methods accepted under this agreement | Freight paid by',
      'Bank transfer or company check | Buyer',
      'Warranty terms by product and available option',
      'PN-900 · 12-month standard: 365 days from delivery; report within 30 days; manufacturing defects and shipping damage;',
      'repair or replacement.',
    ].join('\n'));

    expect(result.fields).toEqual({
      contact_person: 'Marissa Santos',
      contact_person_role: 'Procurement Manager',
      allowed_payment_methods: ['bank_transfer', 'check'],
      shipping_payer: 'customer',
    });
    expect(result.productWarranties[0]).toEqual({
      productReference: 'PN-900', label: '12-month standard', durationDays: 365,
      reportWindowDays: 30, startBasis: 'delivery',
      coveredIssues: ['manufacturing_defect', 'shipping_damage'], remedies: ['repair', 'replace'],
    });
  });

  it('does not classify unrelated readable PDFs as customer agreements even when they contain similar labels', () => {
    const result = extractCustomerAgreementFields([
      'Quarterly Operations Summary',
      'Customer legal name: Example Software, Inc.',
      'Customer default payment terms: Net 30 calendar days',
      'This report summarizes internal software subscriptions.',
    ].join('\n'));

    expect(result.documentKind).toBeNull();
    expect(result.fields.name).toBe('Example Software, Inc.');
  });
});
