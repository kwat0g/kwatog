import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { crmCustomersApi } from '@/api/crm/customers';
import { productsApi, type ProductOption } from '@/api/crm/products';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { productReferencesMatch, type ProductWarrantySuggestion } from '@/components/crm/customerWarrantySuggestion';
import { composeWarrantyAdditionalTerms, warrantyAdditionalTermsCharacterLimit } from '@/components/crm/warrantyAdditionalTerms';
import type { ProductOrderLimitSuggestion } from './customerAgreementPdfExtraction';
import {
  customerWarrantyAdditionalTermsField,
  customerWarrantyLabelField,
  customerWarrantyOtherIssueField,
  normalizeCustomerText,
} from '@/lib/validation/customerFields';

export type CustomerProductWarrantyIssue = ProductWarrantySuggestion['coveredIssues'][number];
export type CustomerProductWarrantyRemedy = ProductWarrantySuggestion['remedies'][number];

export interface CustomerProductWarrantyDraft {
  product_id: string;
  label: string;
  warranty_duration_days: number | null;
  warranty_report_window_days: number | null;
  warranty_start_basis: 'delivery' | 'invoice' | null;
  warranty_covered_issues: CustomerProductWarrantyIssue[];
  warranty_remedies: CustomerProductWarrantyRemedy[];
  additional_terms: string | null;
}

interface CustomerProductWarrantyFormDraft extends CustomerProductWarrantyDraft {
  other_covered_issue_selected: boolean;
  other_covered_issue: string;
}

export interface CustomerProductOrderingDraft {
  portal_catalog_mode: 'standard' | 'restricted';
  portal_product_ids: string[];
  product_order_limits: Array<{
    product_id: string;
    minimum_order_quantity: number;
    maximum_order_quantity: number | null;
  }>;
  product_warranties: CustomerProductWarrantyDraft[];
}

interface ProductOrderLimitDraft {
  product_id: string;
  minimum_order_quantity: number | null;
  maximum_order_quantity: number | null;
}

const ISSUE_OPTIONS: Array<[CustomerProductWarrantyIssue, string]> = [
  ['manufacturing_defect', 'Manufacturing defect'],
  ['shipping_damage', 'Shipping damage'],
  ['customer_caused_damage', 'Customer-caused damage'],
];
const REMEDY_OPTIONS: Array<[CustomerProductWarrantyRemedy, string]> = [
  ['repair', 'Repair'],
  ['replace', 'Replace'],
  ['credit', 'Credit'],
  ['refund', 'Refund'],
];
const EMPTY_PRODUCTS: ProductOption[] = [];

function validWarrantyDays(value: number | null): boolean {
  return value === null || (Number.isInteger(value) && value >= 0 && value <= 3650);
}

function validWarrantyDraft(warranty: CustomerProductWarrantyFormDraft): boolean {
  const validOtherIssue = !warranty.other_covered_issue_selected || (
    warranty.other_covered_issue.trim().length > 0
    && customerWarrantyOtherIssueField.safeParse(warranty.other_covered_issue).success
  );
  return customerWarrantyLabelField.safeParse(warranty.label).success
    && validWarrantyDays(warranty.warranty_duration_days)
    && validWarrantyDays(warranty.warranty_report_window_days)
    && customerWarrantyAdditionalTermsField.safeParse(warranty.additional_terms ?? '').success
    && validOtherIssue;
}

async function loadActiveProducts(signal: AbortSignal): Promise<ProductOption[]> {
  const firstPage = await productsApi.options({ page: 1, per_page: 100, is_active: true }, signal);
  const remainingPages = await Promise.all(
    Array.from({ length: Math.max(0, firstPage.meta.last_page - 1) }, (_, index) =>
      productsApi.options({ page: index + 2, per_page: 100, is_active: true }, signal)),
  );
  return [firstPage, ...remainingPages]
    .flatMap((page) => page.data)
    .sort((left, right) => left.part_number.localeCompare(right.part_number));
}

function fromSuggestion(productId: string, suggestion: ProductWarrantySuggestion): CustomerProductWarrantyFormDraft {
  return {
    product_id: productId,
    label: suggestion.label.slice(0, 120),
    warranty_duration_days: suggestion.durationDays,
    warranty_report_window_days: suggestion.reportWindowDays ?? null,
    warranty_start_basis: suggestion.startBasis ?? 'delivery',
    warranty_covered_issues: suggestion.coveredIssues,
    warranty_remedies: suggestion.remedies,
    additional_terms: null,
    other_covered_issue_selected: false,
    other_covered_issue: '',
  };
}

function emptyWarranty(productId: string): CustomerProductWarrantyFormDraft {
  return {
    product_id: productId,
    label: '',
    warranty_duration_days: null,
    warranty_report_window_days: null,
    warranty_start_basis: 'delivery',
    warranty_covered_issues: [],
    warranty_remedies: [],
    additional_terms: null,
    other_covered_issue_selected: false,
    other_covered_issue: '',
  };
}

export function CustomerProductOrderingSetup({
  productReferences,
  warrantySuggestions,
  orderLimitSuggestions,
  onSetupChange,
  onReadyChange,
  onValidityChange,
}: {
  productReferences: string[];
  warrantySuggestions: ProductWarrantySuggestion[];
  orderLimitSuggestions: ProductOrderLimitSuggestion[];
  onSetupChange: (draft: CustomerProductOrderingDraft) => void;
  onReadyChange: (ready: boolean) => void;
  onValidityChange: (valid: boolean) => void;
}) {
  const catalog = useQuery({
    queryKey: ['crm', 'products', 'options', 'customer-creation-ordering'],
    queryFn: ({ signal }) => loadActiveProducts(signal),
  });
  const orderLimitCapability = useQuery({
    queryKey: ['crm', 'customers', 'product-order-limit-capability'],
    queryFn: crmCustomersApi.productOrderLimitCapability,
    retry: false,
  });
  const orderLimitsSupported = orderLimitCapability.data?.supported === true;
  const [mode, setMode] = useState<CustomerProductOrderingDraft['portal_catalog_mode']>('restricted');
  const [selectedProductIds, setSelectedProductIds] = useState<string[]>([]);
  const [orderLimits, setOrderLimits] = useState<ProductOrderLimitDraft[]>([]);
  const [warranties, setWarranties] = useState<CustomerProductWarrantyFormDraft[]>([]);
  const [expandedProductIds, setExpandedProductIds] = useState<string[]>([]);
  const [search, setSearch] = useState('');
  const appliedPdfKey = useRef('');

  const products = catalog.data ?? EMPTY_PRODUCTS;
  const productById = useMemo(() => new Map(products.map((product) => [product.id, product])), [products]);
  const pdfKey = JSON.stringify({ productReferences, warrantySuggestions, orderLimitSuggestions });

  useEffect(() => {
    onReadyChange(catalog.isSuccess && orderLimitCapability.isSuccess);
  }, [catalog.isSuccess, onReadyChange, orderLimitCapability.isSuccess]);

  useEffect(() => {
    const selected = new Set(selectedProductIds);
    const selectedWarranties = warranties.filter((warranty) => selected.has(warranty.product_id));
    const everyProductHasWarranty = selectedProductIds.length > 0 && selectedProductIds.every((productId) =>
      selectedWarranties.some((warranty) => warranty.product_id === productId && customerWarrantyLabelField.safeParse(warranty.label).success));
    const everyWarrantyHasName = selectedWarranties.every((warranty) => customerWarrantyLabelField.safeParse(warranty.label).success);
    const everyWarrantyIsValid = selectedWarranties.every(validWarrantyDraft);
    const everyProductLimitIsValid = !orderLimitsSupported || selectedProductIds.every((productId) => {
      const limits = orderLimits.find((entry) => entry.product_id === productId);
      return !!limits && Number.isInteger(limits.minimum_order_quantity) && (limits.minimum_order_quantity ?? 0) >= 1
        && (limits.maximum_order_quantity === null || (Number.isInteger(limits.maximum_order_quantity)
          && limits.maximum_order_quantity >= (limits.minimum_order_quantity ?? 1)));
    });
    const valid = catalog.isSuccess && orderLimitCapability.isSuccess && everyProductHasWarranty
      && everyWarrantyHasName && everyWarrantyIsValid && everyProductLimitIsValid;
    onValidityChange(valid);
  }, [catalog.isSuccess, onValidityChange, orderLimitCapability.isSuccess, orderLimits, orderLimitsSupported, selectedProductIds, warranties]);

  useEffect(() => {
    onSetupChange({
      portal_catalog_mode: mode,
      portal_product_ids: selectedProductIds,
      product_order_limits: orderLimitsSupported ? selectedProductIds.map((productId) => {
        const limits = orderLimits.find((entry) => entry.product_id === productId);
        return {
          product_id: productId,
          minimum_order_quantity: limits?.minimum_order_quantity ?? 1,
          maximum_order_quantity: limits?.maximum_order_quantity ?? null,
        };
      }) : [],
      product_warranties: warranties
        .filter((warranty) => selectedProductIds.includes(warranty.product_id))
        .map(({ other_covered_issue_selected, other_covered_issue, ...warranty }) => ({
          ...warranty,
          label: normalizeCustomerText(warranty.label),
          additional_terms: composeWarrantyAdditionalTerms(
            normalizeCustomerText(warranty.additional_terms ?? '', true),
            other_covered_issue_selected ? normalizeCustomerText(other_covered_issue) : '',
          ),
        })),
    });
  }, [mode, onSetupChange, orderLimits, orderLimitsSupported, selectedProductIds, warranties]);

  useEffect(() => {
    if (!catalog.data) return;
    if (productReferences.length === 0 && warrantySuggestions.length === 0 && orderLimitSuggestions.length === 0) {
      appliedPdfKey.current = '';
      return;
    }
    if (appliedPdfKey.current === pdfKey) return;
    appliedPdfKey.current = pdfKey;

    const match = (reference: string) => catalog.data.find((product) => productReferencesMatch(product.part_number, reference));
    const foundProducts = productReferences.flatMap((reference) => {
      const product = match(reference);
      return product ? [product] : [];
    });
    const importedWarranties = warrantySuggestions.flatMap((suggestion) => {
      const product = match(suggestion.productReference);
      return product ? [{ product, suggestion }] : [];
    });
    const importedOrderLimits = orderLimitSuggestions.flatMap((suggestion) => {
      const product = match(suggestion.productReference);
      return product ? [{ product, suggestion }] : [];
    });

    setSelectedProductIds((current) => [...new Set([
      ...current, ...foundProducts.map((product) => product.id),
      ...importedWarranties.map(({ product }) => product.id), ...importedOrderLimits.map(({ product }) => product.id),
    ])]);
    setExpandedProductIds((current) => [...new Set([...current, ...importedWarranties.map(({ product }) => product.id)])]);
    setWarranties((current) => {
      const next = [...current];
      for (const { product, suggestion } of importedWarranties) {
        if (!next.some((option) => option.product_id === product.id && option.label.toLocaleLowerCase() === suggestion.label.toLocaleLowerCase())) {
          next.push(fromSuggestion(product.id, suggestion));
        }
      }
      return next;
    });
    setOrderLimits((current) => {
      const next = [...current];
      for (const { product, suggestion } of importedOrderLimits) {
        const index = next.findIndex((limit) => limit.product_id === product.id);
        const existing = index >= 0 ? next[index] : { product_id: product.id, minimum_order_quantity: 1, maximum_order_quantity: null };
        const updated = {
          ...existing,
          minimum_order_quantity: suggestion.minimumOrderQuantity ?? existing.minimum_order_quantity ?? 1,
          maximum_order_quantity: suggestion.maximumOrderQuantity ?? existing.maximum_order_quantity ?? null,
        };
        if (index >= 0) next[index] = updated;
        else next.push(updated);
      }
      return next;
    });
  }, [catalog.data, orderLimitSuggestions, pdfKey, productReferences, warrantySuggestions]);

  const exactProductExists = (reference: string) => products.some((product) => productReferencesMatch(product.part_number, reference));
  const unmatchedReferences = [...new Set([
    ...productReferences,
    ...warrantySuggestions.map((suggestion) => suggestion.productReference),
    ...orderLimitSuggestions.map((suggestion) => suggestion.productReference),
  ].filter((reference) => !exactProductExists(reference)))];
  const visibleProducts = products.filter((product) => {
    const term = search.trim().toLocaleLowerCase();
    return !term || product.part_number.toLocaleLowerCase().includes(term) || product.name.toLocaleLowerCase().includes(term);
  });

  const toggleProduct = (productId: string, checked: boolean) => {
    setSelectedProductIds((current) => checked ? [...new Set([...current, productId])] : current.filter((id) => id !== productId));
    setExpandedProductIds((current) => checked ? [...new Set([...current, productId])] : current.filter((id) => id !== productId));
  };

  const updateWarranty = (index: number, update: Partial<CustomerProductWarrantyFormDraft>) => {
    setWarranties((current) => current.map((warranty, warrantyIndex) => warrantyIndex === index ? { ...warranty, ...update } : warranty));
  };

  const orderLimitFor = (productId: string) => orderLimits.find((limit) => limit.product_id === productId)
    ?? { product_id: productId, minimum_order_quantity: 1, maximum_order_quantity: null };
  const updateOrderLimit = (productId: string, field: 'minimum_order_quantity' | 'maximum_order_quantity', value: string) => {
    const parsed = value === '' ? null : Number(value);
    setOrderLimits((current) => {
      const existing = current.find((limit) => limit.product_id === productId)
        ?? { product_id: productId, minimum_order_quantity: 1, maximum_order_quantity: null };
      const updated = { ...existing, [field]: parsed };
      return current.some((limit) => limit.product_id === productId)
        ? current.map((limit) => limit.product_id === productId ? updated : limit)
        : [...current, updated];
    });
  };

  const unmatchedWarrantyReferences = new Set(warrantySuggestions.map((suggestion) => suggestion.productReference)
    .filter((reference) => !exactProductExists(reference)));
  const selectedWarrantyOptions = warranties.filter((warranty) => selectedProductIds.includes(warranty.product_id));
  const productsMissingWarranty = selectedProductIds.filter((productId) =>
    !selectedWarrantyOptions.some((warranty) => warranty.product_id === productId && warranty.label.trim().length > 0));
  const hasUnnamedWarranty = selectedWarrantyOptions.some((warranty) => warranty.label.trim().length === 0);
  const hasUndescribedOtherIssue = selectedWarrantyOptions.some((warranty) =>
    warranty.other_covered_issue_selected && warranty.other_covered_issue.trim().length === 0);
  const hasInvalidWarrantyFields = selectedWarrantyOptions.some((warranty) => !validWarrantyDraft(warranty));
  const hasInvalidOrderLimits = orderLimitsSupported && selectedProductIds.some((productId) => {
    const limits = orderLimitFor(productId);
    return !Number.isInteger(limits.minimum_order_quantity) || (limits.minimum_order_quantity ?? 0) < 1
      || (limits.maximum_order_quantity !== null && (!Number.isInteger(limits.maximum_order_quantity)
        || limits.maximum_order_quantity < (limits.minimum_order_quantity ?? 1)));
  });

  return (
    <Panel title="Product Ordering Setup" className="min-w-0 mb-5">
      <div className="space-y-4">
        <p className="text-label font-medium text-primary">Products this customer may order <span aria-hidden="true" className="text-danger-fg">*</span><span className="sr-only"> required</span></p>
        <p className="max-w-3xl text-sm leading-relaxed text-secondary">
          Choose which products this customer can order and add warranty choices by product. Exact part-number matches from an agreement are selected for review; the PDF is never used to guess a different catalog item.
        </p>

        <div className="grid gap-4 lg:grid-cols-[minmax(16rem,0.8fr)_minmax(0,1.2fr)]">
          <div className="space-y-3">
            <Select label="Catalog access" value={mode} onChange={(event) => setMode(event.target.value as typeof mode)}>
              <option value="restricted">Customer-specific products</option>
              <option value="standard">Standard catalog plus selected products</option>
            </Select>
            <p className="text-xs leading-relaxed text-muted">
              {mode === 'restricted'
                ? 'Only checked products will appear in this customer portal.'
                : 'Standard catalog products stay available; checked products are added to the customer portal.'}
            </p>
          </div>
          <div className="space-y-2">
            <Input aria-label="Search products for ordering setup" placeholder="Search part number or product name" value={search} onChange={(event) => setSearch(event.target.value)} />
            {unmatchedReferences.length > 0 && (
              <aside aria-label="Unmatched PDF part numbers" className="space-y-2 rounded-md border border-warning/40 bg-warning-bg px-3 py-3">
                <p aria-live="polite" className="text-sm font-medium text-warning-fg">
                  {unmatchedReferences.length} PDF part number{unmatchedReferences.length === 1 ? '' : 's'} could not be matched automatically.
                </p>
                <p className="text-xs leading-relaxed text-warning-fg">Review the references, then select the products that apply from the catalog list. Add warranty choices manually for those products.</p>
                <ul className="flex flex-wrap gap-2">
                  {unmatchedReferences.map((reference) => <li key={reference} className="rounded border border-warning/40 bg-canvas px-2 py-1 font-mono text-xs text-primary">{reference}</li>)}
                </ul>
              </aside>
            )}
            {catalog.isLoading ? (
              <p role="status" className="rounded-md border border-default bg-surface px-3 py-4 text-sm text-muted">Loading active products…</p>
            ) : catalog.isError ? (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-default bg-surface px-3 py-3">
                <p role="alert" className="text-sm text-danger-fg">Could not load the active product catalog. Retry before creating this customer.</p>
                <Button type="button" size="sm" variant="secondary" onClick={() => void catalog.refetch()}>Retry catalog</Button>
              </div>
            ) : (
              <>
                <ul aria-label="Products available for ordering setup" className="max-h-72 divide-y divide-default overflow-y-auto rounded-md border border-default bg-canvas">
                  {visibleProducts.map((product) => (
                    <li key={product.id} className="flex min-w-0 items-center gap-3 px-3 py-2">
                      {product.image_url
                        ? <img src={product.image_url} alt="" className="h-10 w-10 shrink-0 rounded border border-default object-cover" />
                        : <div aria-hidden="true" className="h-10 w-10 shrink-0 rounded border border-default bg-surface" />}
                      <Checkbox
                        className="min-w-0 flex-1"
                        checked={selectedProductIds.includes(product.id)}
                        onChange={(event) => toggleProduct(product.id, event.target.checked)}
                        label={<span className="min-w-0"><span className="block truncate font-medium">{product.part_number} · {product.name}</span><span className="block text-xs text-muted">{product.unit_of_measure ?? 'Unit not set'}</span></span>}
                      />
                    </li>
                  ))}
                  {visibleProducts.length === 0 && <li className="px-3 py-5 text-center text-sm text-muted">No active products match this search.</li>}
                </ul>
                <p className="text-xs text-muted">
                  {selectedProductIds.length} selected · {products.length} active {products.length === 1 ? 'product' : 'products'} loaded
                </p>
              </>
            )}
          </div>
        </div>

        <section aria-labelledby="customer-product-order-limits-heading" className="space-y-3 border-t border-default pt-4">
          <div>
            <h3 id="customer-product-order-limits-heading" className="text-label font-semibold text-primary">Order quantity limits</h3>
            <p className="mt-1 text-xs text-muted">Limits apply to the total quantity of each product on one sales order.</p>
          </div>
          {orderLimitCapability.isLoading && <p role="status" className="text-sm text-muted">Checking quantity limit support…</p>}
          {orderLimitCapability.isError && (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-warning/40 bg-warning-bg px-3 py-3">
              <p role="alert" className="text-sm text-warning-fg">Could not check quantity limit support. Retry before continuing.</p>
              <Button type="button" size="sm" variant="secondary" onClick={() => void orderLimitCapability.refetch()}>Retry check</Button>
            </div>
          )}
          {orderLimitCapability.data && !orderLimitsSupported && (
            <div className="space-y-1 rounded-md border border-warning/40 bg-warning-bg px-3 py-3 text-sm text-warning-fg">
              <p>Per-customer quantity limits need a database update before they can be saved. This setup is read-only for now.</p>
              {orderLimitSuggestions.length > 0 && <p className="text-xs">Detected in the PDF: {orderLimitSuggestions.map((limit) =>
                `${limit.productReference} (${limit.minimumOrderQuantity ?? 1}–${limit.maximumOrderQuantity ?? 'no max'})`,
              ).join(' · ')}. These values will not be saved until quantity-limit support is enabled.</p>}
            </div>
          )}
          {orderLimitsSupported && selectedProductIds.length === 0 && (
            <p className="rounded-md border border-dashed border-default px-3 py-3 text-sm text-muted">Select products above to set minimum and maximum order quantities.</p>
          )}
          {orderLimitsSupported && selectedProductIds.length > 0 && (
            <ul aria-label="Product order quantity limits" className="divide-y divide-default rounded-md border border-default bg-canvas">
              {selectedProductIds.map((productId) => {
                const product = productById.get(productId);
                if (!product) return null;
                const limit = orderLimitFor(productId);
                const invalidMinimum = !Number.isInteger(limit.minimum_order_quantity) || (limit.minimum_order_quantity ?? 0) < 1;
                const invalidMaximum = limit.maximum_order_quantity !== null && (!Number.isInteger(limit.maximum_order_quantity)
                  || limit.maximum_order_quantity < (limit.minimum_order_quantity ?? 1));
                return (
                  <li key={productId} className="grid gap-3 px-3 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(8rem,0.5fr)_minmax(8rem,0.5fr)] sm:items-end">
                    <div className="min-w-0 pb-1">
                      <p className="font-medium text-primary">{product.part_number} · {product.name}</p>
                      <p className="text-xs text-muted">Quantity per sales order</p>
                    </div>
                    <Input label={`Minimum order quantity for ${product.part_number}`} type="number" min={1} step={1} inputMode="numeric" required
                      value={limit.minimum_order_quantity ?? ''} error={invalidMinimum ? 'Enter a whole number of at least 1.' : undefined}
                      onChange={(event) => updateOrderLimit(productId, 'minimum_order_quantity', event.target.value)} />
                    <Input label={`Maximum order quantity for ${product.part_number} (optional)`} type="number" min={limit.minimum_order_quantity ?? 1} step={1} inputMode="numeric"
                      value={limit.maximum_order_quantity ?? ''} error={invalidMaximum ? 'Must be at least the minimum.' : undefined}
                      onChange={(event) => updateOrderLimit(productId, 'maximum_order_quantity', event.target.value)} />
                  </li>
                );
              })}
            </ul>
          )}
          {hasInvalidOrderLimits && <p role="status" className="text-sm text-warning-fg">Enter a whole number minimum and a maximum that is equal to or greater than it.</p>}
        </section>

        <section aria-labelledby="customer-product-warranty-options-heading" className="space-y-3 border-t border-default pt-4">
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <div>
              <h3 id="customer-product-warranty-options-heading" className="text-label font-semibold text-primary">Warranty choices by product <span aria-hidden="true" className="text-danger-fg">*</span><span className="sr-only"> required</span></h3>
              <p className="mt-1 text-xs text-muted">Add at least one named warranty for every selected product. Duration, report window, start date, and extra terms are optional when the agreement does not specify them.</p>
            </div>
            <span className="text-xs text-muted">{warranties.filter((warranty) => selectedProductIds.includes(warranty.product_id)).length} choices</span>
          </div>

          {selectedProductIds.length === 0 && (
            <p role="status" className="rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">Select at least one product before continuing.</p>
          )}
          {productsMissingWarranty.length > 0 && (
            <p role="status" className="rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">
              Add at least one named warranty choice for every selected product before continuing.
            </p>
          )}
          {hasUnnamedWarranty && productsMissingWarranty.length === 0 && (
            <p role="status" className="rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">Complete or remove each unnamed warranty choice before continuing.</p>
          )}
          {hasUndescribedOtherIssue && (
            <p role="status" className="rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">Describe every selected “Other” covered issue before continuing.</p>
          )}
          {hasInvalidWarrantyFields && !hasUnnamedWarranty && !hasUndescribedOtherIssue && (
            <p role="status" className="rounded-md border border-warning/40 bg-warning-bg px-3 py-2 text-sm text-warning-fg">
              Check each warranty name, day count, and text field. Use a real warranty title and whole-day values from 0 to 3,650.
            </p>
          )}

          {selectedProductIds.length === 0 ? (
            <p className="rounded-md border border-dashed border-default px-3 py-3 text-sm text-muted">Choose at least one product from the catalog list above to configure its warranty.</p>
          ) : (
            <ul className="divide-y divide-default rounded-md border border-default bg-canvas">
              {selectedProductIds.map((productId) => {
                const product = productById.get(productId);
                if (!product) return null;
                const options = warranties.flatMap((warranty, index) => warranty.product_id === productId ? [{ warranty, index }] : []);
                const isExpanded = expandedProductIds.includes(productId);
                return (
                  <li key={productId} className="p-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="font-medium text-primary">{product.part_number} · {product.name}</p>
                        <p className="mt-0.5 text-xs text-muted">
                          {options.length > 0 ? `${options.length} warranty ${options.length === 1 ? 'choice' : 'choices'} configured` : 'Required: add at least one product-specific warranty choice'}
                          {unmatchedWarrantyReferences.has(product.part_number) && ' · PDF terms need manual review'}
                        </p>
                      </div>
                      <div className="flex shrink-0 flex-wrap gap-2">
                        <Button type="button" size="sm" variant="secondary" aria-expanded={isExpanded} onClick={() => setExpandedProductIds((current) => isExpanded ? current.filter((id) => id !== productId) : [...current, productId])}>
                          {isExpanded ? 'Hide warranty details' : 'Show warranty details'}
                        </Button>
                        <Button type="button" size="sm" variant="secondary" onClick={() => {
                          const nextIndex = warranties.length;
                          setWarranties((current) => [...current, emptyWarranty(productId)]);
                          setExpandedProductIds((current) => [...new Set([...current, productId])]);
                          window.setTimeout(() => document.getElementById(`customer-warranty-label-${nextIndex}`)?.focus(), 0);
                        }}>Add warranty choice</Button>
                      </div>
                    </div>

                    {isExpanded && (
                      <div className="mt-3 space-y-3 border-t border-subtle pt-3">
                        {options.map(({ warranty, index }) => (
                          <fieldset key={`${productId}-${index}`} className="space-y-3 rounded-md border border-default bg-surface p-3">
                            <legend className="sr-only">Warranty choice {index + 1} for {product.name}</legend>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                              <Input id={`customer-warranty-label-${index}`} label={`Warranty option name for ${product.name}`} required maxLength={120} value={warranty.label} onChange={(event) => updateWarranty(index, { label: event.target.value })} error={warranty.label && !customerWarrantyLabelField.safeParse(warranty.label).success ? 'Enter a real warranty name using standard punctuation.' : undefined} placeholder="e.g. 12-month standard" />
                              <Input label={`Warranty duration for ${product.name} (days, optional)`} type="number" min={0} max={3650} step={1} value={warranty.warranty_duration_days ?? ''} onChange={(event) => updateWarranty(index, { warranty_duration_days: event.target.value === '' ? null : Number(event.target.value) })} error={!validWarrantyDays(warranty.warranty_duration_days) ? 'Enter a whole number from 0 to 3,650.' : undefined} />
                              <Input label={`Report window for ${product.name} (days, optional)`} type="number" min={0} max={3650} step={1} value={warranty.warranty_report_window_days ?? ''} onChange={(event) => updateWarranty(index, { warranty_report_window_days: event.target.value === '' ? null : Number(event.target.value) })} error={!validWarrantyDays(warranty.warranty_report_window_days) ? 'Enter a whole number from 0 to 3,650.' : undefined} />
                              <Select label={`Warranty starts from for ${product.name} (optional)`} value={warranty.warranty_start_basis ?? ''} onChange={(event) => updateWarranty(index, { warranty_start_basis: event.target.value ? event.target.value as 'delivery' | 'invoice' : null })}>
                                <option value="">Not specified</option><option value="delivery">Delivery</option><option value="invoice">Invoice</option>
                              </Select>
                              <div className="flex items-end justify-start sm:justify-end">
                                <Button type="button" size="sm" variant="ghost" onClick={() => setWarranties((current) => current.filter((_, optionIndex) => optionIndex !== index))}>
                                  Remove {warranty.label || 'unnamed'} choice
                                </Button>
                              </div>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                              <fieldset className="space-y-1">
                                <legend className="text-xs font-medium text-muted">Covered issues (optional)</legend>
                                {ISSUE_OPTIONS.map(([value, label]) => <Checkbox key={value} checked={warranty.warranty_covered_issues.includes(value)} onChange={(event) => updateWarranty(index, { warranty_covered_issues: event.target.checked ? [...new Set([...warranty.warranty_covered_issues, value])] : warranty.warranty_covered_issues.filter((item) => item !== value) })} label={label} />)}
                                <Checkbox
                                  checked={warranty.other_covered_issue_selected}
                                  onChange={(event) => updateWarranty(index, { other_covered_issue_selected: event.target.checked })}
                                  label="Other"
                                  aria-label={`Other covered issue for ${product.name}`}
                                />
                                {warranty.other_covered_issue_selected && (
                                  <Input
                                    label={`Describe other covered issue for ${product.name}`}
                                    required
                                    maxLength={500}
                                    value={warranty.other_covered_issue}
                                    onChange={(event) => updateWarranty(index, { other_covered_issue: event.target.value })}
                                    error={warranty.other_covered_issue && !customerWarrantyOtherIssueField.safeParse(warranty.other_covered_issue).success ? 'Describe a real covered issue using plain text.' : undefined}
                                    helper="Saved with this product’s warranty terms."
                                  />
                                )}
                              </fieldset>
                              <fieldset className="space-y-1">
                                <legend className="text-xs font-medium text-muted">Possible remedies (optional)</legend>
                                {REMEDY_OPTIONS.map(([value, label]) => <Checkbox key={value} checked={warranty.warranty_remedies.includes(value)} onChange={(event) => updateWarranty(index, { warranty_remedies: event.target.checked ? [...new Set([...warranty.warranty_remedies, value])] : warranty.warranty_remedies.filter((item) => item !== value) })} label={label} />)}
                              </fieldset>
                            </div>
                            <Textarea label={`Additional terms for ${product.name} (optional)`} maxLength={warrantyAdditionalTermsCharacterLimit(warranty.other_covered_issue_selected ? warranty.other_covered_issue : '')} rows={2} value={warranty.additional_terms ?? ''} onChange={(event) => updateWarranty(index, { additional_terms: event.target.value || null })} error={warranty.additional_terms && !customerWarrantyAdditionalTermsField.safeParse(warranty.additional_terms).success ? 'Use plain text up to 2,000 characters.' : undefined} placeholder="Product-specific terms from the agreement" />
                          </fieldset>
                        ))}
                        {options.length === 0 && <p className="text-xs text-muted">No warranty terms were detected. Add an option from the signed agreement or finish setup on the customer record.</p>}
                      </div>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </section>
      </div>
    </Panel>
  );
}
