import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuPencil, LuPlus, LuTrash2 } from '@/lib/icons';
import { crmCustomersApi, type CustomerWarrantyOption } from '@/api/crm/customers';
import { productsApi } from '@/api/crm/products';
import { usePermission } from '@/hooks/usePermission';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { productReferencesMatch, type ProductWarrantySuggestion } from './customerWarrantySuggestion';
import { composeWarrantyAdditionalTerms, warrantyAdditionalTermsCharacterLimit } from './warrantyAdditionalTerms';

const ISSUE_OPTIONS = [
  ['manufacturing_defect', 'Manufacturing defect'],
  ['shipping_damage', 'Shipping damage'],
  ['customer_caused_damage', 'Customer-caused damage'],
] as const;
const REMEDY_OPTIONS = [
  ['repair', 'Repair'],
  ['replace', 'Replace'],
  ['credit', 'Credit'],
  ['refund', 'Refund'],
] as const;
const OTHER_ISSUE_PREFIX = 'Other covered issue: ';

function splitOtherCoveredIssue(terms: string | null) {
  const [firstLine = '', ...remainingLines] = terms?.split('\n') ?? [];
  if (!firstLine.startsWith(OTHER_ISSUE_PREFIX)) {
    return { otherIssueSelected: false, otherIssue: '', additionalTerms: terms ?? '' };
  }

  return {
    otherIssueSelected: true,
    otherIssue: firstLine.slice(OTHER_ISSUE_PREFIX.length),
    additionalTerms: remainingLines.join('\n'),
  };
}

export function CustomerWarrantyOptionsManager({ customerId, agreementSuggestions = [] }: { customerId: string; agreementSuggestions?: ProductWarrantySuggestion[] }) {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [productId, setProductId] = useState('');
  const [label, setLabel] = useState('');
  const [duration, setDuration] = useState('');
  const [reportWindow, setReportWindow] = useState('');
  const [startBasis, setStartBasis] = useState<'delivery' | 'invoice'>('delivery');
  const [issues, setIssues] = useState<Array<(typeof ISSUE_OPTIONS)[number][0]>>(['manufacturing_defect']);
  const [otherIssueSelected, setOtherIssueSelected] = useState(false);
  const [otherIssue, setOtherIssue] = useState('');
  const [remedies, setRemedies] = useState<Array<(typeof REMEDY_OPTIONS)[number][0]>>(['repair']);
  const [additionalTerms, setAdditionalTerms] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editingOption, setEditingOption] = useState<CustomerWarrantyOption | null>(null);

  const clearFields = () => {
    setProductId(''); setLabel(''); setDuration(''); setReportWindow(''); setStartBasis('delivery');
    setIssues(['manufacturing_defect']); setOtherIssueSelected(false); setOtherIssue('');
    setRemedies(['repair']); setAdditionalTerms('');
  };
  const openAddForm = () => {
    clearFields();
    setEditingOption(null);
    setFormOpen(true);
  };
  const openEditForm = (option: CustomerWarrantyOption) => {
    const parsedTerms = splitOtherCoveredIssue(option.additional_terms);
    setEditingOption(option);
    setProductId(option.product.id);
    setLabel(option.label);
    setDuration(option.warranty_duration_days == null ? '' : String(option.warranty_duration_days));
    setReportWindow(option.warranty_report_window_days == null ? '' : String(option.warranty_report_window_days));
    setStartBasis(option.warranty_start_basis ?? 'delivery');
    setIssues(option.warranty_covered_issues ?? []);
    setOtherIssueSelected(parsedTerms.otherIssueSelected);
    setOtherIssue(parsedTerms.otherIssue);
    setRemedies(option.warranty_remedies ?? []);
    setAdditionalTerms(parsedTerms.additionalTerms);
    setFormOpen(true);
  };
  const closeForm = () => {
    clearFields();
    setEditingOption(null);
    setFormOpen(false);
  };

  const products = useQuery({
    queryKey: ['crm', 'products', 'options', 'warranty-setup'],
    queryFn: () => productsApi.options({ per_page: 100, is_active: true }),
    enabled: can('accounting.customers.manage'),
  });
  const productAccess = useQuery({
    queryKey: ['crm', 'customers', customerId, 'product-access'],
    queryFn: () => crmCustomersApi.productAccess(customerId),
  });
  const options = useQuery({
    queryKey: ['crm', 'customers', customerId, 'warranty-options'],
    queryFn: () => crmCustomersApi.warrantyOptions(customerId),
  });
  const authorizedProductIds = useMemo(() => new Set(
    (productAccess.data?.products ?? []).filter((product) => product.is_authorized).map((product) => product.id),
  ), [productAccess.data?.products]);
  const sortedProducts = useMemo(() => (products.data?.data ?? [])
    .filter((product) => authorizedProductIds.has(product.id))
    .sort((a, b) => a.part_number.localeCompare(b.part_number)), [products.data?.data, authorizedProductIds]);
  const warrantyProductIds = useMemo(() => new Set((options.data ?? []).map((option) => option.product.id)), [options.data]);
  const authorizedProducts = productAccess.data?.products.filter((product) => product.is_authorized) ?? [];
  const missingWarrantyProducts = authorizedProducts.filter((product) => !warrantyProductIds.has(product.id));
  const matchedSuggestions = agreementSuggestions.map((suggestion) => ({
    suggestion,
    product: sortedProducts.find((product) => productReferencesMatch(product.part_number, suggestion.productReference)),
  }));

  const applyAgreementSuggestion = (suggestion: ProductWarrantySuggestion, matchedProductId: string) => {
    setEditingOption(null);
    setProductId(matchedProductId);
    setLabel(suggestion.label.slice(0, 120));
    setDuration(String(suggestion.durationDays));
    setReportWindow(suggestion.reportWindowDays == null ? '' : String(suggestion.reportWindowDays));
    setStartBasis(suggestion.startBasis ?? 'delivery');
    setIssues(suggestion.coveredIssues);
    setOtherIssueSelected(false);
    setOtherIssue('');
    setRemedies(suggestion.remedies);
    setAdditionalTerms('');
    setFormOpen(true);
  };

  const warrantyPayload = () => ({
    label: label.trim(),
    warranty_duration_days: duration === '' ? null : Number(duration),
    warranty_report_window_days: reportWindow === '' ? null : Number(reportWindow),
    warranty_start_basis: startBasis,
    warranty_covered_issues: issues,
    warranty_remedies: remedies,
    additional_terms: composeWarrantyAdditionalTerms(additionalTerms, otherIssueSelected ? otherIssue : ''),
  });
  const create = useMutation({
    mutationFn: () => crmCustomersApi.createWarrantyOption(customerId, productId, warrantyPayload()),
    onSuccess: (created) => {
      queryClient.setQueryData<CustomerWarrantyOption[]>(['crm', 'customers', customerId, 'warranty-options'], (current) => [...(current ?? []), created]);
      void queryClient.invalidateQueries({ queryKey: ['crm', 'customers', customerId, 'warranty-options'] });
      closeForm();
      toast.success('Warranty choice added to the customer agreement.');
    },
    onError: () => toast.error('Could not save this warranty choice.'),
  });
  const update = useMutation({
    mutationFn: () => {
      if (!editingOption) throw new Error('Choose a warranty choice to edit.');
      return crmCustomersApi.updateWarrantyOption(customerId, editingOption.product.id, editingOption.id, warrantyPayload());
    },
    onSuccess: (updated) => {
      queryClient.setQueryData<CustomerWarrantyOption[]>(['crm', 'customers', customerId, 'warranty-options'], (current) =>
        (current ?? []).map((option) => option.id === updated.id ? updated : option));
      void queryClient.invalidateQueries({ queryKey: ['crm', 'customers', customerId, 'warranty-options'] });
      closeForm();
      toast.success('Warranty updated. Existing orders keep their saved terms.');
    },
    onError: () => toast.error('Could not update this warranty choice.'),
  });
  const remove = useMutation({
    mutationFn: ({ product, option }: { product: string; option: string }) => crmCustomersApi.deleteWarrantyOption(customerId, product, option),
    onSuccess: (_result, variables) => {
      queryClient.setQueryData<CustomerWarrantyOption[]>(['crm', 'customers', customerId, 'warranty-options'], (current) =>
        (current ?? []).filter((option) => option.id !== variables.option));
      void queryClient.invalidateQueries({ queryKey: ['crm', 'customers', customerId, 'warranty-options'] });
      toast.success('Warranty choice removed. Existing orders keep their saved terms.');
    },
    onError: () => toast.error('Could not remove this warranty choice.'),
  });

  const toggle = <T extends string>(values: T[], value: T, checked: boolean, set: (next: T[]) => void) => set(
    checked ? [...new Set([...values, value])] : values.filter((current) => current !== value),
  );
  const combinedAdditionalTerms = composeWarrantyAdditionalTerms(additionalTerms, otherIssueSelected ? otherIssue : '');
  const additionalTermsMaxLength = warrantyAdditionalTermsCharacterLimit(otherIssueSelected ? otherIssue : '');
  const canSave = can('accounting.customers.manage') && productId !== '' && label.trim() !== ''
    && (duration === '' || (/^\d+$/.test(duration) && Number(duration) <= 3650))
    && (reportWindow === '' || (/^\d+$/.test(reportWindow) && Number(reportWindow) <= 3650))
    && (!otherIssueSelected || otherIssue.trim() !== '')
    && (combinedAdditionalTerms?.length ?? 0) <= 2000;

  return (
    <section aria-labelledby="customer-warranty-options-heading" className="space-y-3 border-t border-default pt-4">
      <div>
        <h4 id="customer-warranty-options-heading" className="text-label font-semibold text-primary">Product warranty choices</h4>
        <p className="mt-1 text-xs leading-relaxed text-muted">Add the warranty options this customer may select for each product. The chosen terms are copied onto the order.</p>
      </div>
      {options.isError && <p role="alert" className="text-sm text-danger-fg">Could not load warranty choices.</p>}
      {productAccess.data && options.data && missingWarrantyProducts.length > 0 && (
        <p role="status" className="rounded-md border border-warning bg-surface px-3 py-2 text-xs leading-relaxed text-warning-fg">
          {missingWarrantyProducts.length} of {authorizedProducts.length} authorized product{authorizedProducts.length === 1 ? '' : 's'} {missingWarrantyProducts.length === 1 ? 'has' : 'have'} no product-specific warranty choice yet. Add a choice or review the optional fallback before accepting orders.
          <span className="mt-1 block">{missingWarrantyProducts.map((product) => `${product.part_number} · ${product.name}`).join(' · ')}</span>
        </p>
      )}
      {agreementSuggestions.length > 0 && (
        <div className="space-y-2 border-y border-default py-3" aria-labelledby="detected-warranty-terms-heading">
          <div>
            <h5 id="detected-warranty-terms-heading" className="text-sm font-semibold text-primary">Warranty terms detected from PDF</h5>
            <p className="mt-1 text-xs text-muted">Check the part number and terms, then load a suggestion into the form. Nothing is saved until you add it.</p>
          </div>
          <ul className="divide-y divide-subtle">
            {matchedSuggestions.map(({ suggestion, product }, index) => {
              const alreadyConfigured = product ? warrantyProductIds.has(product.id) : false;
              return (
                <li key={`${suggestion.productReference}-${suggestion.label}-${index}`} className="flex flex-wrap items-center justify-between gap-3 py-2">
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-primary">{suggestion.productReference} · {suggestion.label}</p>
                    <p className="text-xs text-muted">{suggestion.durationDays} days{suggestion.reportWindowDays == null ? '' : ` · report within ${suggestion.reportWindowDays} days`}{product ? ` · ${product.name}` : ''}</p>
                    {!product && <p className="mt-1 text-xs text-warning-fg">No authorized catalog product matches this part number. Add product access or match it manually.</p>}
                    {alreadyConfigured && <p className="mt-1 text-xs text-muted">This product already has a saved warranty choice. Review the document before replacing anything.</p>}
                  </div>
                  {can('accounting.customers.manage') && product && !alreadyConfigured && (
                    <Button type="button" size="sm" variant="secondary" onClick={() => applyAgreementSuggestion(suggestion, product.id)}>
                      Use detected warranty for {suggestion.productReference}
                    </Button>
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      )}
      {options.data && options.data.length > 0 ? (
        <ul aria-label="Configured warranty choices" className="space-y-2">
          {options.data.map((option) => (
            <li key={option.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-default bg-canvas px-3 py-2">
              <div className="min-w-0">
                <p className="font-medium">{option.product.name} <span className="font-mono text-xs text-muted">{option.product.part_number}</span></p>
                <p className="text-xs text-muted">{option.label} · {option.warranty_duration_days == null ? 'Term to be agreed' : `${option.warranty_duration_days} days`} · starts from {option.warranty_start_basis ?? 'agreement'}</p>
                {option.additional_terms && <p className="mt-1 text-xs text-muted">Other terms: {option.additional_terms}</p>}
              </div>
              {can('accounting.customers.manage') && <div className="flex shrink-0 items-center gap-1">
                <Button type="button" variant="secondary" size="sm" aria-label={`Edit ${option.label} for ${option.product.name}`}
                  disabled={create.isPending || update.isPending || remove.isPending} icon={<LuPencil size={14} />} onClick={() => openEditForm(option)}>Edit</Button>
                <Button type="button" variant="ghost" size="sm" aria-label={`Remove ${option.label} for ${option.product.name}`}
                  disabled={create.isPending || remove.isPending || update.isPending} icon={<LuTrash2 size={15} />} onClick={() => remove.mutate({ product: option.product.id, option: option.id })}>Remove</Button>
              </div>}
            </li>
          ))}
        </ul>
      ) : options.isLoading ? <p role="status" className="text-sm text-muted">Loading warranty choices…</p> : (
        <p className="rounded-md border border-dashed border-default px-3 py-3 text-sm text-muted">No product-specific warranty choices are configured yet.</p>
      )}
      {can('accounting.customers.manage') && !formOpen && (
        <Button type="button" size="sm" variant="secondary" icon={<LuPlus size={15} />} onClick={openAddForm}>
          {options.data?.length ? 'Add another warranty choice' : 'Add warranty choice'}
        </Button>
      )}
      {can('accounting.customers.manage') && formOpen && (
        <form aria-label={editingOption ? 'Edit product warranty choice' : 'Add product warranty choice'} className="space-y-3 rounded-md border border-default bg-surface p-3" onSubmit={(event) => { event.preventDefault(); if (canSave) (editingOption ? update : create).mutate(); }}>
          <div>
            <h5 className="text-sm font-semibold text-primary">{editingOption ? 'Edit warranty choice' : 'Add warranty choice'}</h5>
            <p className="mt-1 text-xs text-muted">{editingOption ? 'Changes apply to future orders. Existing orders retain their saved warranty terms.' : 'Set warranty terms for one product available to this customer.'}</p>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <Select label="Product" required disabled={Boolean(editingOption)} value={productId} onChange={(event) => setProductId(event.target.value)}>
              <option value="">Choose a product</option>
              {editingOption && !sortedProducts.some((product) => product.id === editingOption.product.id) && <option value={editingOption.product.id}>{editingOption.product.part_number} · {editingOption.product.name} (not currently authorized)</option>}
              {sortedProducts.map((product) => <option key={product.id} value={product.id}>{product.part_number} · {product.name}</option>)}
            </Select>
            <Input label="Warranty option name" required maxLength={120} value={label} onChange={(event) => setLabel(event.target.value)} placeholder="e.g. 1-year standard warranty" />
            <Input label="Warranty duration (days)" type="number" min={0} max={3650} step={1} value={duration} onChange={(event) => setDuration(event.target.value)} helper="Leave blank when the agreement sets the duration later." />
            <Input label="Report window (days)" type="number" min={0} max={3650} step={1} value={reportWindow} onChange={(event) => setReportWindow(event.target.value)} />
            <Select label="Warranty starts from" value={startBasis} onChange={(event) => setStartBasis(event.target.value as typeof startBasis)}>
              <option value="delivery">Delivery</option><option value="invoice">Invoice</option>
            </Select>
          </div>
          {productAccess.data && authorizedProducts.length === 0 && <p className="text-xs text-muted">Save portal product access first. Only products this customer can order appear in this list.</p>}
          <fieldset className="space-y-2">
            <legend className="text-xs font-medium text-muted">Covered issues</legend>
            <div className="flex flex-wrap gap-x-4 gap-y-1">
              {ISSUE_OPTIONS.map(([value, title]) => <Checkbox key={value} checked={issues.includes(value)} onChange={(event) => toggle(issues, value, event.target.checked, setIssues)} label={title} />)}
              <Checkbox checked={otherIssueSelected} onChange={(event) => setOtherIssueSelected(event.target.checked)} label="Other" />
            </div>
            {otherIssueSelected && (
              <Input
                label="Describe other covered issue"
                required
                maxLength={500}
                value={otherIssue}
                onChange={(event) => setOtherIssue(event.target.value)}
                helper="This will be saved with the product warranty terms."
              />
            )}
          </fieldset>
          <fieldset className="space-y-2">
            <legend className="text-xs font-medium text-muted">Remedies</legend>
            <div className="flex flex-wrap gap-x-4 gap-y-1">
              {REMEDY_OPTIONS.map(([value, title]) => <Checkbox key={value} checked={remedies.includes(value)} onChange={(event) => toggle(remedies, value, event.target.checked, setRemedies)} label={title} />)}
            </div>
          </fieldset>
          <Textarea label="Other agreement terms (optional)" maxLength={additionalTermsMaxLength} rows={2} value={additionalTerms} onChange={(event) => setAdditionalTerms(event.target.value)} placeholder="Add any product-specific warranty terms not listed above." />
          <div className="flex flex-wrap items-center gap-2">
            <Button type="submit" disabled={!canSave || create.isPending || update.isPending} icon={editingOption ? <LuPencil size={15} /> : <LuPlus size={15} />}>
              {create.isPending || update.isPending ? 'Saving…' : editingOption ? 'Save warranty changes' : 'Add warranty choice'}
            </Button>
            <Button type="button" variant="secondary" disabled={create.isPending || update.isPending} onClick={closeForm}>Cancel</Button>
          </div>
        </form>
      )}
    </section>
  );
}
