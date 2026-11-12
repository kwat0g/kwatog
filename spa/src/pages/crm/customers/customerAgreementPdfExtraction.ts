import type { CustomerFormInput } from './form';
import type { ProductWarrantySuggestion } from '@/components/crm/customerWarrantySuggestion';
export type { ProductWarrantySuggestion } from '@/components/crm/customerWarrantySuggestion';

type AgreementFields = Partial<Pick<CustomerFormInput,
  | 'name'
  | 'contact_person'
  | 'contact_person_role'
  | 'email'
  | 'phone'
  | 'address'
  | 'delivery_address'
  | 'agreement_starts_on'
  | 'agreement_ends_on'
  | 'payment_terms_days'
  | 'allowed_payment_methods'
  | 'shipping_payer'
>>;

export interface CustomerAgreementExtraction {
  fields: AgreementFields;
  productReferences: string[];
  productWarranties: ProductWarrantySuggestion[];
  productOrderLimits: ProductOrderLimitSuggestion[];
  documentKind: 'customer-agreement' | 'warranty-schedule' | null;
}

export interface ProductOrderLimitSuggestion {
  productReference: string;
  minimumOrderQuantity?: number;
  maximumOrderQuantity?: number;
}

const labels = [
  'customer legal name', 'customer name', 'buyer legal name', 'buyer name',
  'authorized contact person', 'customer contact name', 'contact person',
  'contact role / title', 'contact person role', 'contact role',
  'business email', 'customer email', 'email address', 'email',
  'business phone', 'customer phone', 'phone number', 'phone',
  'customer billing address', 'billing address', 'customer address',
  'delivery / receiving address', 'delivery address', 'receiving address',
  'effective date (yyyy-mm-dd)', 'agreement start date', 'effective date',
  'expiry date (yyyy-mm-dd)', 'expiration date', 'expiry date', 'agreement end date',
  'customer default payment terms', 'payment terms (days)', 'payment terms',
  'payment methods accepted under this agreement', 'allowed payment methods', 'payment methods',
  'freight paid by', 'shipping paid by', 'shipping payer',
  'products currently authorized for this customer', 'allowed products summary', 'authorized products', 'allowed products',
  'warranty terms by product and available option', 'warranty coverage',
  'customer fallback warranty', 'fallback warranty', 'warranty terms', 'warranty',
];

const labelBoundary = new RegExp(
  `\\s+(?:${labels.map(escapeRegExp).sort((a, b) => b.length - a.length).join('|')})(?=\\s*(?::|$)|\\s)`,
  'i',
);

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function normalizedLines(text: string): string[] {
  return text
    .replace(/\r/g, '\n')
    .split(/\n+/)
    .map((line) => line.replace(/[\u00a0\t]+/g, ' ').replace(/\s{2,}/g, ' ').trim())
    .filter(Boolean);
}

function readLabel(text: string, names: string[]): string | undefined {
  const pattern = new RegExp(`^\\s*(?:${names.map(escapeRegExp).sort((a, b) => b.length - a.length).join('|')})(?:\\s*[:：–—-]\\s*|\\s+|$)(.*)$`, 'i');
  const lines = normalizedLines(text);
  for (let index = 0; index < lines.length; index += 1) {
    const cells = lines[index].split(/\s*\|\s*/);
    for (let cellIndex = 0; cellIndex < cells.length; cellIndex += 1) {
      const match = cells[cellIndex].match(pattern);
      if (!match) continue;

      const value = match[1].trim().split(labelBoundary, 1)[0].trim();
      if (value && !isKnownLabel(value)) return cleanValue(value);

      const nextLine = lines[index + 1];
      if (!nextLine) continue;
      const nextCells = nextLine.split(/\s*\|\s*/);
      const nextValue = nextCells[cellIndex] ?? nextCells[0];
      if (!nextValue || isKnownLabel(nextValue)) continue;
      return cleanValue(nextValue.split(labelBoundary, 1)[0].trim());
    }
  }
  return undefined;
}

function isKnownLabel(value: string): boolean {
  return labels.some((label) => new RegExp(`^${escapeRegExp(label)}(?:\\s|:|$)`, 'i').test(value.trim()));
}

function cleanValue(value: string): string | undefined {
  const cleaned = value
    .replace(/^[\s:：–—-]+|[\s•·]+$/g, '')
    .replace(/^—$/, '')
    .trim();
  return cleaned && cleaned !== '—' ? cleaned : undefined;
}

function durationDays(text: string): number | undefined {
  const withoutReportWindow = text.replace(/\breport\s+(?:within|in)\s+\d{1,4}\s*(?:days?|months?)\b/ig, '');
  const days = withoutReportWindow.match(/\b(\d{1,4})\s*days?\b/i);
  if (days) return Number(days[1]);

  const period = text.match(/\b(\d{1,3})\s*[- ]?(months?|years?)\b/i);
  if (!period) return undefined;
  const amount = Number(period[1]);
  return period[2].toLowerCase().startsWith('year') ? amount * 365 : amount === 12 ? 365 : amount * 30;
}

function reportWindowDays(text: string): number | undefined {
  const match = text.match(/\breport\s+(?:within|in)\s+(\d{1,4})\s*(days?|months?)\b/i);
  if (!match) return undefined;
  const amount = Number(match[1]);
  return match[2].toLowerCase().startsWith('month') ? amount * 30 : amount;
}

function coveredIssues(text: string): NonNullable<CustomerFormInput['warranty_covered_issues']> {
  const values: NonNullable<CustomerFormInput['warranty_covered_issues']> = [];
  if (/manufacturing\s+defect/i.test(text)) values.push('manufacturing_defect');
  if (/shipping\s+damage|transit\s+damage/i.test(text)) values.push('shipping_damage');
  if (/customer[- ]caused\s+damage/i.test(text)) values.push('customer_caused_damage');
  return values;
}

function remedies(text: string): NonNullable<CustomerFormInput['warranty_remedies']> {
  const values: NonNullable<CustomerFormInput['warranty_remedies']> = [];
  if (/repair/i.test(text)) values.push('repair');
  if (/replace|replacement/i.test(text)) values.push('replace');
  if (/credit/i.test(text)) values.push('credit');
  if (/refund/i.test(text)) values.push('refund');
  return values;
}

function warrantyBasis(text: string): 'delivery' | 'invoice' | undefined {
  if (/from\s+(?:delivery|delivered|receipt|receiving)/i.test(text)) return 'delivery';
  if (/from\s+(?:invoice|invoicing|invoice date)/i.test(text)) return 'invoice';
  return undefined;
}

function extractProductWarranties(text: string): ProductWarrantySuggestion[] {
  const suggestions: ProductWarrantySuggestion[] = [];
  const lines = normalizedLines(text);
  for (let index = 0; index < lines.length; index += 1) {
    const line = lines[index];
    const match = line.match(/\b([A-Z]{1,8}[-_]?[0-9]{1,8})\s*[·:–—-]\s*(.+)$/i);
    if (!match) continue;
    const [, productReference] = match;
    let terms = match[2];
    const continuation = lines[index + 1];
    if (continuation && /\b(?:report\s+within|manufacturing|shipping|repair|replace|replacement|credit|refund|from\s+(?:delivery|invoice))\b/i.test(continuation)) {
      terms += ` ${continuation}`;
    }
    const duration = durationDays(terms);
    if (duration == null || !warrantyBasis(terms)) continue;
    const label = terms.split(/:\s*/)[0].trim() || `${duration} days`;
    const reportWindow = reportWindowDays(terms);
    suggestions.push({
      productReference,
      label,
      durationDays: duration,
      ...(reportWindow == null ? {} : { reportWindowDays: reportWindow }),
      ...(warrantyBasis(terms) ? { startBasis: warrantyBasis(terms) } : {}),
      coveredIssues: coveredIssues(terms),
      remedies: remedies(terms),
    });
  }
  return suggestions;
}

function productReferenceFromLine(line: string): string | undefined {
  const match = line.trim().match(/^([A-Z0-9][A-Z0-9._/-]{1,39})\s*[·:–—-]\s+\S/i);
  if (!match || !/[a-z]/i.test(match[1]) || !/\d/.test(match[1])) return undefined;
  return match[1];
}

function extractAuthorizedProductReferences(text: string): string[] {
  const references = new Set<string>();
  let inAuthorizedProductSection = false;
  const sectionEnd = /^(?:\d+\.\s*)?(?:sales orders|payment,|delivery and receiving|product-specific warranty choices|inspection, defects|quality and warranty)\b/i;
  const heading = /(?:authorized|allowed) products|products currently authorized/i;

  for (const line of normalizedLines(text)) {
    const cells = line.split(/\s*\|\s*/);
    const headingIndex = cells.findIndex((cell) => heading.test(cell));
    if (headingIndex >= 0) {
      inAuthorizedProductSection = true;
      const headingCell = cells[headingIndex];
      const inlineValue = headingCell.match(/(?:authorized|allowed) products|products currently authorized\b.*?\s*[:：]\s*(.+)$/i)?.[1];
      if (inlineValue) {
        const reference = productReferenceFromLine(inlineValue);
        if (reference) references.add(reference);
      }
      for (const cell of cells.slice(headingIndex + 1)) {
        const reference = productReferenceFromLine(cell);
        if (reference) references.add(reference);
      }
      continue;
    }

    if (inAuthorizedProductSection && sectionEnd.test(line)) {
      inAuthorizedProductSection = false;
    }
    if (!inAuthorizedProductSection) continue;

    for (const cell of cells) {
      const reference = productReferenceFromLine(cell);
      if (reference) references.add(reference);
    }
  }

  return [...references];
}

function extractProductOrderLimits(text: string): ProductOrderLimitSuggestion[] {
  const suggestions = new Map<string, ProductOrderLimitSuggestion>();
  for (const line of normalizedLines(text)) {
    const reference = productReferenceFromLine(line);
    if (!reference) continue;
    const minimum = line.match(/\bminimum(?:\s+order)?(?:\s+quantity)?\s*(?:[:=–—-]\s*)?(\d{1,9})\b/i);
    const maximum = line.match(/\bmaximum(?:\s+order)?(?:\s+quantity)?(?:\s+per\s+(?:sales\s+)?order)?\s*(?:[:=–—-]\s*)?(\d{1,9})\b/i);
    if (!minimum && !maximum) continue;

    const suggestion: ProductOrderLimitSuggestion = { productReference: reference };
    if (minimum) suggestion.minimumOrderQuantity = Number(minimum[1]);
    if (maximum) suggestion.maximumOrderQuantity = Number(maximum[1]);
    if (suggestion.minimumOrderQuantity === 0 || suggestion.maximumOrderQuantity === 0) continue;
    if (suggestion.minimumOrderQuantity != null && suggestion.maximumOrderQuantity != null
      && suggestion.maximumOrderQuantity < suggestion.minimumOrderQuantity) continue;
    suggestions.set(reference.toLocaleUpperCase(), suggestion);
  }
  return [...suggestions.values()];
}

function paymentDays(value: string): number | undefined {
  if (/due\s+on\s+receipt|immediately|cash\s+on\s+delivery/i.test(value)) return 0;
  const match = value.match(/\b(?:net\s*)?(\d{1,3})\s*(?:calendar\s+)?days?\b/i);
  return match ? Number(match[1]) : undefined;
}

function paymentMethods(value: string): NonNullable<CustomerFormInput['allowed_payment_methods']> {
  const matches: Array<{ method: 'cash' | 'check' | 'bank_transfer'; index: number }> = [];
  const patterns: Array<[typeof matches[number]['method'], RegExp]> = [
    ['cash', /cash/i],
    ['check', /check|cheque/i],
    ['bank_transfer', /bank\s+transfer|wire\s+transfer|bank\s+deposit/i],
  ];
  for (const [method, pattern] of patterns) {
    const match = pattern.exec(value);
    if (match) matches.push({ method, index: match.index });
  }
  return matches.sort((left, right) => left.index - right.index).map(({ method }) => method);
}

function shippingPayer(value: string): 'customer' | 'company' | undefined {
  if (/buyer|customer/i.test(value)) return 'customer';
  if (/supplier|seller|company/i.test(value)) return 'company';
  return undefined;
}

export function extractCustomerAgreementFields(text: string): CustomerAgreementExtraction {
  const fields: AgreementFields = {};
  const setString = <K extends keyof AgreementFields>(key: K, value: string | undefined) => {
    if (value) fields[key] = value as AgreementFields[K];
  };

  setString('name', readLabel(text, ['customer legal name', 'customer name', 'buyer legal name', 'buyer name']));
  setString('contact_person', readLabel(text, ['authorized contact person', 'customer contact name', 'contact person']));
  setString('contact_person_role', readLabel(text, ['contact role / title', 'contact person role', 'contact role']));
  setString('email', readLabel(text, ['business email', 'customer email', 'email address', 'email']));
  setString('phone', readLabel(text, ['business phone', 'customer phone', 'phone number', 'phone']));
  setString('address', readLabel(text, ['customer billing address', 'billing address', 'customer address']));
  setString('delivery_address', readLabel(text, ['delivery / receiving address', 'delivery address', 'receiving address']));

  const startsOn = readLabel(text, ['effective date (yyyy-mm-dd)', 'agreement start date', 'effective date']);
  if (startsOn && /^\d{4}-\d{2}-\d{2}$/.test(startsOn)) fields.agreement_starts_on = startsOn;
  const endsOn = readLabel(text, ['expiry date (yyyy-mm-dd)', 'expiration date', 'expiry date', 'agreement end date']);
  if (endsOn && /^\d{4}-\d{2}-\d{2}$/.test(endsOn)) fields.agreement_ends_on = endsOn;

  const terms = readLabel(text, ['customer default payment terms', 'payment terms (days)', 'payment terms']);
  if (terms) {
    const days = paymentDays(terms);
    if (days != null && days <= 365) fields.payment_terms_days = days;
  }
  const methods = readLabel(text, ['payment methods accepted under this agreement', 'allowed payment methods', 'payment methods']);
  if (methods) {
    const detectedMethods = paymentMethods(methods);
    if (detectedMethods.length) fields.allowed_payment_methods = detectedMethods;
  }
  const freight = readLabel(text, ['freight paid by', 'shipping paid by', 'shipping payer']);
  if (freight) {
    const payer = shippingPayer(freight);
    if (payer) fields.shipping_payer = payer;
  }

  const productWarranties = extractProductWarranties(text);
  const productOrderLimits = extractProductOrderLimits(text);
  const productReferences = [...new Set([
    ...extractAuthorizedProductReferences(text),
    ...productWarranties.map((warranty) => warranty.productReference),
  ])];

  const genericWarranty = readLabel(text, ['customer fallback warranty', 'fallback warranty', 'warranty coverage', 'warranty terms', 'warranty']);
  const hasStructuredGenericWarranty = Boolean(
    genericWarranty
      && durationDays(genericWarranty) != null
      && warrantyBasis(genericWarranty),
  );

  const searchableText = normalizedLines(text).join(' ');
  const hasAgreementHeading = /\b(?:customer|buyer|supply|sales|purchase|commercial|master)\s+(?:(?:customer|supply|sales|purchase)\s+)*(?:agreement|contract)\b/i.test(searchableText);
  const hasCustomerIdentity = Boolean(fields.name);
  const hasAgreementTerms = Boolean(
    fields.agreement_starts_on
    || fields.agreement_ends_on
    || fields.payment_terms_days != null
    || fields.allowed_payment_methods?.length
    || fields.shipping_payer
    || productWarranties.length,
  );
  const hasWarrantyHeading = /\bwarranty(?:\s+(?:terms?|schedule|coverage|policy))?\b/i.test(searchableText);
  const hasStructuredWarranty = productWarranties.length > 0
    || hasStructuredGenericWarranty;
  const documentKind = hasAgreementHeading && hasCustomerIdentity && hasAgreementTerms
    ? 'customer-agreement'
    : hasWarrantyHeading && hasStructuredWarranty
      ? 'warranty-schedule'
      : null;

  return { fields, productReferences, productWarranties, productOrderLimits, documentKind };
}
