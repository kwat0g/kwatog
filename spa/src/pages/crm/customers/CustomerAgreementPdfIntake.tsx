import { useRef, useState } from 'react';
import { useFormContext, type FieldPath } from 'react-hook-form';
import { downloadAuthenticatedFile } from '@/api/download';
import { Button } from '@/components/ui/Button';
import type { CustomerFormInput } from './form';
import { extractCustomerAgreementFields, type CustomerAgreementExtraction, type ProductOrderLimitSuggestion, type ProductWarrantySuggestion } from './customerAgreementPdfExtraction';
import { CUSTOMER_AGREEMENT_PDF_MAX_BYTES, readCustomerAgreementPdf } from './readCustomerAgreementPdf';

const MAX_PDF_FILES = 5;

const FIELD_LABELS: Record<string, string> = {
  name: 'Customer name',
  contact_person: 'Contact person',
  contact_person_role: 'Contact role',
  email: 'Email',
  phone: 'Phone',
  address: 'Billing address',
  delivery_address: 'Delivery address',
  agreement_starts_on: 'Agreement starts',
  agreement_ends_on: 'Agreement ends',
  payment_terms_days: 'Default payment terms',
  allowed_payment_methods: 'Payment methods',
  shipping_payer: 'Shipping paid by',
};

function displaySuggestion(value: unknown): string {
  if (Array.isArray(value)) return value.map((item) => String(item).replaceAll('_', ' ')).join(', ');
  if (typeof value === 'number') return `${value} days`;
  if (value === 'customer') return 'Customer / buyer';
  if (value === 'company') return 'Supplier / company';
  return String(value ?? '');
}

function readableError(error: unknown): string {
  return error instanceof Error ? error.message : 'Could not read this PDF. It was not imported or queued for upload.';
}

export function CustomerAgreementPdfIntake({
  onFilesSelected,
  onProductReferences,
  onProductWarranties,
  onProductOrderLimits,
  onReadingChange,
}: {
  onFilesSelected: (files: File[]) => void;
  onProductReferences?: (references: string[]) => void;
  onProductWarranties?: (suggestions: ProductWarrantySuggestion[]) => void;
  onProductOrderLimits?: (suggestions: ProductOrderLimitSuggestion[]) => void;
  onReadingChange?: (reading: boolean) => void;
}) {
  const { getValues, setValue, formState: { dirtyFields } } = useFormContext<CustomerFormInput>();
  const [files, setFiles] = useState<File[]>([]);
  const [extraction, setExtraction] = useState<CustomerAgreementExtraction | null>(null);
  const [reading, setReading] = useState(false);
  const [errors, setErrors] = useState<string[]>([]);
  const [filledCount, setFilledCount] = useState(0);
  const selectionVersion = useRef(0);

  const applyValues = (values: CustomerAgreementExtraction['fields'], replaceExisting: boolean) => {
    let changed = 0;
    for (const [key, value] of Object.entries(values)) {
      if (value === undefined) continue;
      const field = key as FieldPath<CustomerFormInput>;
      const current = getValues(field);
      const isEmpty = current == null || current === '' || (Array.isArray(current) && current.length === 0);
      const isUntouchedPaymentDefault = key === 'payment_terms_days' && !dirtyFields.payment_terms_days;
      if (replaceExisting || isEmpty || isUntouchedPaymentDefault) {
        setValue(field, value as never, { shouldDirty: true, shouldValidate: true });
        changed += 1;
      }
    }
    setFilledCount(changed);
  };

  const selectFiles = async (selected: File[]) => {
    const currentSelection = ++selectionVersion.current;
    const validationErrors: string[] = [];
    if (selected.length > MAX_PDF_FILES) {
      validationErrors.push(`Choose up to ${MAX_PDF_FILES} PDFs at a time.`);
    }
    const candidates = selected.slice(0, MAX_PDF_FILES).filter((file) => {
      const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
      if (!isPdf) validationErrors.push(`${file.name} is not a PDF and was skipped.`);
      else if (file.size > CUSTOMER_AGREEMENT_PDF_MAX_BYTES) validationErrors.push(`${file.name} is over 10 MB and was skipped.`);
      return isPdf && file.size <= CUSTOMER_AGREEMENT_PDF_MAX_BYTES;
    });

    setFiles([]);
    setExtraction(null);
    setErrors(validationErrors);
    setFilledCount(0);
    onFilesSelected([]);
    onProductReferences?.([]);
    onProductWarranties?.([]);
    onProductOrderLimits?.([]);
    if (!candidates.length) {
      setReading(false);
      onReadingChange?.(false);
      return;
    }

    setReading(true);
    onReadingChange?.(true);
    try {
      const readResults = await Promise.all(candidates.map(async (file) => {
        try {
          const text = await readCustomerAgreementPdf(file);
          const document = extractCustomerAgreementFields(text);
          if (!document.documentKind) {
            return {
              file,
              error: `${file.name}: This PDF was not recognized as a customer agreement or warranty schedule. It was not imported or queued for upload. Use a searchable PDF with labeled customer and agreement terms, or a product warranty schedule.`,
            };
          }
          return { file, text };
        } catch (error) {
          return { file, error: `${file.name}: ${readableError(error)}` };
        }
      }));
      if (currentSelection !== selectionVersion.current) return;
      const readableText = readResults.flatMap((result) => 'text' in result ? [result.text] : []);
      const readErrors = readResults.flatMap((result) => 'error' in result && result.error ? [result.error] : []);
      const recognizedFiles = readResults.flatMap((result) => 'text' in result ? [result.file] : []);
      const nextErrors = [...validationErrors, ...readErrors];
      setFiles(recognizedFiles);
      onFilesSelected(recognizedFiles);
      setErrors(nextErrors);
      if (readableText.length) {
        const nextExtraction = extractCustomerAgreementFields(readableText.join('\n'));
        setExtraction(nextExtraction);
        applyValues(nextExtraction.fields, false);
        onProductReferences?.(nextExtraction.productReferences);
        onProductWarranties?.(nextExtraction.productWarranties);
        onProductOrderLimits?.(nextExtraction.productOrderLimits);
      }
    } finally {
      if (currentSelection === selectionVersion.current) {
        setReading(false);
        onReadingChange?.(false);
      }
    }
  };

  return (
    <section aria-labelledby="customer-agreement-pdf-heading" className="space-y-3">
      <div>
        <h4 id="customer-agreement-pdf-heading" className="text-label font-semibold text-primary">Agreement PDFs</h4>
        <p className="mt-1 max-w-3xl text-sm leading-relaxed text-secondary">
          Searchable agreements or warranty schedules can fill blank fields. Review detected details; originals attach after save.
        </p>
        <button
          type="button"
          className="mt-1 inline-flex text-sm font-medium text-accent underline underline-offset-2 hover:text-accent-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
          onClick={() => void downloadAuthenticatedFile('/api/v1/crm/customers/sample-agreement', {
            errorMessage: 'Failed to generate the sample customer agreement.',
          })}
        >
          Download sample agreement
        </button>
        <p className="mt-1 text-xs text-muted">Fictional, watermarked demo file; not for signing.</p>
      </div>
      <div className="space-y-1.5">
        <label htmlFor="customer-agreement-pdfs" className="block text-sm font-medium text-primary">Select PDF files (optional)</label>
        <input
          id="customer-agreement-pdfs"
          type="file"
          accept="application/pdf,.pdf"
          multiple
          onChange={(event) => void selectFiles(Array.from(event.currentTarget.files ?? []))}
          className="block w-full text-sm text-secondary file:mr-3 file:rounded-md file:border file:border-default file:bg-surface file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        />
        <p className="text-xs text-muted">Up to {MAX_PDF_FILES} files · 10 MB and 60 pages each. Searchable agreements or warranty schedules only; scanned or unrelated PDFs are rejected.</p>
      </div>

      {files.length > 0 && (
        <ul aria-label="PDFs ready to attach" className="space-y-1 text-sm text-secondary">
          {files.map((file) => <li key={`${file.name}-${file.lastModified}`} className="break-all">{file.name}</li>)}
        </ul>
      )}
      {reading && (
        <div role="status" aria-live="polite" className="flex items-start gap-3 rounded-md border border-accent/25 bg-accent/5 px-3 py-3">
          <span aria-hidden="true" className="mt-0.5 h-5 w-5 shrink-0 animate-spin rounded-full border-2 border-accent/25 border-t-accent motion-reduce:animate-none" />
          <div className="min-w-0 flex-1">
            <p className="text-sm font-semibold text-primary">Reading and checking your PDF…</p>
            <p className="mt-0.5 text-xs leading-relaxed text-secondary">Extracting agreement terms, product access, and warranty details. This can take a moment.</p>
            <div aria-hidden="true" className="mt-2 h-1 overflow-hidden rounded-full bg-accent/10">
              <div className="h-full w-full animate-pulse rounded-full bg-accent/60 motion-reduce:animate-none" />
            </div>
          </div>
        </div>
      )}
      {errors.map((error) => <p key={error} role="alert" className="text-sm text-danger-fg">{error}</p>)}

      {extraction && (
        <div aria-live="polite" className="rounded-md border border-default bg-surface p-3">
          {Object.keys(extraction.fields).length > 0 || extraction.productReferences.length > 0 || extraction.productWarranties.length > 0 || extraction.productOrderLimits.length > 0 ? (
            <details>
              <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                <span className="font-semibold text-primary">Review detected {extraction.documentKind === 'warranty-schedule' ? 'warranty terms' : 'agreement terms'}</span>
                <span className="text-xs text-muted">
                  {Object.keys(extraction.fields).length > 0 && `${Object.keys(extraction.fields).length} fields`}
                  {Object.keys(extraction.fields).length > 0 && extraction.productReferences.length > 0 && ' · '}
                  {extraction.productReferences.length > 0 && `${extraction.productReferences.length} products`}
                  {(Object.keys(extraction.fields).length > 0 || extraction.productReferences.length > 0) && extraction.productWarranties.length > 0 && ' · '}
                  {extraction.productWarranties.length > 0 && `${extraction.productWarranties.length} product warranties`}
                  {(Object.keys(extraction.fields).length > 0 || extraction.productReferences.length > 0 || extraction.productWarranties.length > 0) && extraction.productOrderLimits.length > 0 && ' · '}
                  {extraction.productOrderLimits.length > 0 && `${extraction.productOrderLimits.length} quantity limits`}
                </span>
              </summary>
              <div className="mt-3 space-y-3 border-t border-default pt-3">
                {Object.keys(extraction.fields).length > 0 && (
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <p className="text-xs text-muted">{filledCount} filled where blank. Check each value against the PDF and complete missing details.</p>
                    <Button type="button" size="sm" variant="secondary" onClick={() => applyValues(extraction.fields, true)}>
                      Replace with detected values
                    </Button>
                  </div>
                )}
                {Object.keys(extraction.fields).length > 0 && <dl className="grid gap-x-5 gap-y-2 sm:grid-cols-2">
                  {Object.entries(extraction.fields).map(([key, value]) => (
                    <div key={key} className="min-w-0">
                      <dt className="text-xs font-medium text-muted">{FIELD_LABELS[key] ?? key}</dt>
                      <dd className="break-words text-sm text-primary">{displaySuggestion(value)}</dd>
                    </div>
                  ))}
                </dl>}
                {extraction.productReferences.length > 0 && (
                  <div className="space-y-1 border-t border-default pt-3">
                    <h3 className="text-sm font-semibold text-primary">Products listed in the agreement</h3>
                    <p className="text-sm text-secondary">{extraction.productReferences.join(' · ')}</p>
                  </div>
                )}
                {extraction.productOrderLimits.length > 0 && (
                  <div className="space-y-1 border-t border-default pt-3">
                    <h3 className="text-sm font-semibold text-primary">Product order quantities</h3>
                    <ul className="space-y-1 text-sm text-secondary">
                      {extraction.productOrderLimits.map((limit) => (
                        <li key={limit.productReference}>
                          <span className="font-medium text-primary">{limit.productReference}</span>
                          <span> — minimum {limit.minimumOrderQuantity ?? 1}; maximum {limit.maximumOrderQuantity ?? 'not specified'} per sales order</span>
                        </li>
                      ))}
                    </ul>
                    <p className="text-xs leading-relaxed text-muted">Exact part-number matches are prefilled for review in Product Ordering Setup.</p>
                  </div>
                )}
                {extraction.productWarranties.length > 0 && (
                  <div className="space-y-1 border-t border-default pt-3">
                    <h3 className="text-sm font-semibold text-primary">Product warranties</h3>
                    <ul className="space-y-1 text-sm text-secondary">
                      {extraction.productWarranties.map((warranty) => (
                        <li key={`${warranty.productReference}-${warranty.label}`}>
                          <span className="font-medium text-primary">{warranty.productReference} · {warranty.label}</span>
                          <span> — {warranty.durationDays} days{warranty.reportWindowDays == null ? '' : `; report within ${warranty.reportWindowDays} days`}</span>
                        </li>
                      ))}
                    </ul>
                    <p className="text-xs leading-relaxed text-muted">Review the matched products and imported warranty choices in Product Ordering Setup before saving.</p>
                  </div>
                )}
              </div>
            </details>
          ) : (
            <p className="text-sm text-secondary">
              {extraction.documentKind === 'warranty-schedule'
                ? 'Generic warranty terms were recognized, but no product-specific warranty row was found. The PDF will remain queued for attachment; assign the terms to the correct product before offering that warranty.'
                : 'The agreement PDF was recognized and queued for attachment, but no labeled fields were extracted. Review its details after the customer is saved.'}
            </p>
          )}

        </div>
      )}
    </section>
  );
}
