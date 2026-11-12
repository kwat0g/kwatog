import { useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { portalContractsApi } from '@/api/b2b/contracts';
import { vendorsApi } from '@/api/accounting/vendors';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import { reportMutationError } from '@/lib/formErrors';
import { ContractTemplateForm } from './ContractTemplateForm';
import { contractRecordFields, type ContractTemplateSubmission, type PortalContractRealm } from './contractTemplateFields';

interface AgreementDocumentManagerProps {
  realm: PortalContractRealm;
  entityId: string;
  entityName: string;
  previewValues?: Record<string, string | null | undefined>;
  allowTemplateCreation?: boolean;
  pendingFiles?: File[];
}

export function AgreementDocumentManager({ realm, entityId, entityName, previewValues, allowTemplateCreation = false, pendingFiles = [] }: AgreementDocumentManagerProps) {
  const { can } = usePermission();
  const queryClient = useQueryClient();
  const inputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [templateId, setTemplateId] = useState('');
  const [effectiveDate, setEffectiveDate] = useState('');
  const [expiryDate, setExpiryDate] = useState('');
  const [showTemplateForm, setShowTemplateForm] = useState(false);
  const [downloadingId, setDownloadingId] = useState<string | null>(null);
  const [attachedQueuedFiles, setAttachedQueuedFiles] = useState<File[]>([]);
  const canManage = realm === 'customer' ? can('accounting.customers.manage') : can('b2b.portal_access.manage');
  const canViewTemplates = can('b2b.portal_access.view');
  const canCreateTemplate = allowTemplateCreation && canManage && can('b2b.portal_access.manage');
  const documentQueryKey = ['portal-contracts', 'documents', realm, entityId] as const;
  const templateQueryKey = ['portal-contracts', 'templates', realm] as const;
  const documentsQuery = useQuery({
    queryKey: documentQueryKey,
    queryFn: () => realm === 'customer'
      ? portalContractsApi.listCustomerDocuments(entityId)
      : portalContractsApi.listSupplierDocuments(entityId),
    enabled: Boolean(entityId),
  });
  const templatesQuery = useQuery({
    queryKey: templateQueryKey,
    queryFn: () => portalContractsApi.listTemplates(realm),
    enabled: canViewTemplates && canManage,
  });
  const vendorQuery = useQuery({
    queryKey: ['accounting', 'vendors', entityId],
    queryFn: () => vendorsApi.show(entityId),
    enabled: realm === 'supplier' && canViewTemplates && canManage && Boolean(entityId),
  });

  const invalidateDocuments = () => queryClient.invalidateQueries({ queryKey: documentQueryKey });
  const attachMutation = useMutation({
    mutationFn: (selectedFile: File) => realm === 'customer'
      ? portalContractsApi.attachCustomerPdf(entityId, selectedFile)
      : portalContractsApi.attachSupplierPdf(entityId, selectedFile),
    onSuccess: (_document, selectedFile) => {
      setFile(null);
      if (inputRef.current) inputRef.current.value = '';
      if (pendingFiles.includes(selectedFile)) {
        setAttachedQueuedFiles((current) => current.includes(selectedFile) ? current : [...current, selectedFile]);
      }
      void invalidateDocuments();
      toast.success('PDF saved as a staff-only draft. Review it before issuing.');
    },
    onError: (error) => reportMutationError(error, 'Could not attach the agreement PDF.'),
  });
  const templateMutation = useMutation({
    mutationFn: (selectedTemplateId: string) => realm === 'customer'
      ? portalContractsApi.issueCustomerTemplate(entityId, selectedTemplateId)
      : portalContractsApi.issueSupplierTemplate(entityId, selectedTemplateId, {
        effective_date: effectiveDate || undefined,
        expiry_date: expiryDate || undefined,
      }),
    onSuccess: () => {
      setTemplateId('');
      setEffectiveDate('');
      setExpiryDate('');
      void invalidateDocuments();
      toast.success('Auto-filled PDF saved as a staff-only draft. Review it before issuing.');
    },
    onError: (error) => reportMutationError(error, 'Could not generate the agreement PDF.'),
  });
  const createTemplateMutation = useMutation({
    mutationFn: (submission: ContractTemplateSubmission) => portalContractsApi.createTemplate(submission),
    onSuccess: (template) => {
      setShowTemplateForm(false);
      setTemplateId(template.id);
      void queryClient.invalidateQueries({ queryKey: templateQueryKey });
      toast.success('Mapped template saved. Creating the filled PDF draft…');
      templateMutation.mutate(template.id);
    },
    onError: (error) => reportMutationError(error, 'Could not save the mapped PDF template.'),
  });
  const issueMutation = useMutation({
    mutationFn: (documentId: string) => realm === 'customer'
      ? portalContractsApi.issueCustomerDocument(entityId, documentId)
      : portalContractsApi.issueSupplierDocument(entityId, documentId),
    onSuccess: () => {
      void invalidateDocuments();
      toast.success('Agreement issued to the portal for signature.');
    },
    onError: (error) => reportMutationError(error, 'Could not issue this agreement. Download and check the PDF, then try again.'),
  });

  const values = useMemo(() => {
    const baseValues = previewValues ?? (realm !== 'supplier' || !vendorQuery.data ? {} : {
      vendor_name: vendorQuery.data.name,
      vendor_contact_name: vendorQuery.data.contact_person,
      vendor_email: vendorQuery.data.email,
      vendor_phone: vendorQuery.data.phone,
      vendor_address: vendorQuery.data.address,
      payment_terms: vendorQuery.data.payment_terms_days == null ? null : `${vendorQuery.data.payment_terms_days} days`,
    });
    return realm === 'supplier'
      ? { ...baseValues, effective_date: effectiveDate || null, expiry_date: expiryDate || null }
      : baseValues;
  }, [effectiveDate, expiryDate, previewValues, realm, vendorQuery.data]);
  const selectedTemplate = templatesQuery.data?.find((template) => template.id === templateId);
  const recordFields = contractRecordFields(realm);
  const effectiveDatePdfField = realm === 'supplier'
    ? Object.entries(selectedTemplate?.field_mappings ?? {}).find(([, recordKey]) => recordKey === 'effective_date')?.[0]
    : undefined;
  const expiryDatePdfField = realm === 'supplier'
    ? Object.entries(selectedTemplate?.field_mappings ?? {}).find(([, recordKey]) => recordKey === 'expiry_date')?.[0]
    : undefined;
  const effectiveDateRequired = Boolean(effectiveDatePdfField && selectedTemplate?.required_fields.includes(effectiveDatePdfField));
  const expiryDateRequired = Boolean(expiryDatePdfField && selectedTemplate?.required_fields.includes(expiryDatePdfField));
  const dateRangeError = effectiveDate && expiryDate && expiryDate < effectiveDate
    ? 'Agreement end date must be on or after its start date.'
    : undefined;
  const requiredDatesReady = (!effectiveDateRequired || Boolean(effectiveDate))
    && (!expiryDateRequired || Boolean(expiryDate))
    && !dateRangeError;

  const downloadDocument = async (documentId: string, filename: string) => {
    setDownloadingId(documentId);
    try {
      const blob = realm === 'customer'
        ? await portalContractsApi.downloadCustomerDocument(entityId, documentId)
        : await portalContractsApi.downloadSupplierDocument(entityId, documentId);
      const url = URL.createObjectURL(blob);
      const anchor = window.document.createElement('a');
      anchor.href = url;
      anchor.download = filename;
      anchor.click();
      URL.revokeObjectURL(url);
    } catch (error) {
      reportMutationError(error, 'Could not download this agreement PDF.');
    } finally {
      setDownloadingId(null);
    }
  };

  return (
    <section aria-labelledby={`agreements-heading-${realm}`} className="space-y-4">
      <div>
        <h2 id={`agreements-heading-${realm}`} className="text-lg font-semibold text-primary">Agreement documents</h2>
        <p className="mt-1 text-sm text-muted">For {entityName}, attach a reviewed PDF as a staff draft or generate one from a mapped fillable template. Generated documents use saved record terms and stay private until staff reviews and issues them.</p>
      </div>

      {allowTemplateCreation && canManage && !canCreateTemplate && (
        <p role="note" className="max-w-3xl rounded-md border border-default bg-surface px-3 py-2 text-sm leading-relaxed text-secondary">
          {canViewTemplates
            ? 'You can generate drafts from existing mapped templates below. Creating or mapping a template requires Portal Access management permission.'
            : realm === 'customer'
              ? 'You can attach a reviewed PDF as a private staff draft. Automatic filling at this step requires a mapped fillable template. During new customer creation, readable labeled terms can suggest record fields; scanned PDFs require manual entry. Creating or mapping a template requires Portal Access management permission.'
              : 'You can attach a reviewed PDF as a private staff draft. Automatic filling requires a mapped fillable template; readable terms are not imported into supplier records at this step. Creating or mapping one requires Portal Access management permission.'}
        </p>
      )}

      {canManage && (
        <div className="space-y-4 border-y border-default py-4">
          {realm === 'customer' && pendingFiles.length > 0 && (
            <div className="space-y-2" aria-labelledby="queued-customer-agreements-heading">
              <div>
                <h3 id="queued-customer-agreements-heading" className="text-sm font-semibold text-primary">PDFs selected during customer creation</h3>
                <p className="mt-1 text-xs text-muted">Attach each file to save it as a private staff draft. It will not be issued to the customer until staff reviews it.</p>
              </div>
              <ul className="divide-y divide-subtle border-y border-default">
                {pendingFiles.map((pendingFile) => {
                  const attached = attachedQueuedFiles.includes(pendingFile);
                  return (
                    <li key={`${pendingFile.name}-${pendingFile.lastModified}`} className="flex flex-wrap items-center justify-between gap-3 py-2">
                      <span className="min-w-0 break-all text-sm text-primary">{pendingFile.name}</span>
                      <Button
                        type="button"
                        size="sm"
                        variant={attached ? 'ghost' : 'secondary'}
                        onClick={() => attachMutation.mutate(pendingFile)}
                        disabled={attached || attachMutation.isPending}
                        loading={attachMutation.isPending && attachMutation.variables === pendingFile}
                      >
                        {attached ? `Attached ${pendingFile.name}` : `Attach ${pendingFile.name}`}
                      </Button>
                    </li>
                  );
                })}
              </ul>
            </div>
          )}

          <div className="grid gap-4 lg:grid-cols-2">
          <div className="space-y-2">
            <label htmlFor={`agreement-pdf-${realm}`} className="block text-sm font-medium text-primary">Attach a PDF</label>
            <input
              ref={inputRef}
              id={`agreement-pdf-${realm}`}
              type="file"
              accept="application/pdf,.pdf"
              className="block w-full text-sm text-secondary file:mr-3 file:rounded-md file:border file:border-default file:bg-surface file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            />
            <Button size="sm" variant="secondary" onClick={() => file && attachMutation.mutate(file)} disabled={!file || attachMutation.isPending} loading={attachMutation.isPending}>
              Save PDF draft
            </Button>
          </div>

          {(canViewTemplates || canCreateTemplate) && (
            <div className="space-y-2">
              {canViewTemplates && (
                <>
                  <Select
                    label="Generate from a mapped template"
                    value={templateId}
                    onChange={(event) => {
                      setTemplateId(event.target.value);
                      setEffectiveDate('');
                      setExpiryDate('');
                    }}
                    disabled={!templatesQuery.data?.length || templatesQuery.isLoading}
                  >
                    <option value="">Choose a {realm} contract template…</option>
                    {(templatesQuery.data ?? []).map((template) => <option key={template.id} value={template.id}>{template.name} · v{template.version}</option>)}
                  </Select>
                  {templatesQuery.isLoading && <p className="text-xs text-muted">Loading templates…</p>}
                  {templatesQuery.isError && <p role="alert" className="text-xs text-danger">Could not load templates. Retry the page to continue.</p>}
                  {!templatesQuery.isLoading && !templatesQuery.isError && templatesQuery.data?.length === 0 && (
                    <p className="text-xs text-muted">No {realm} templates are available. You can still attach a PDF above.</p>
                  )}
                  {selectedTemplate && selectedTemplate.auto_fill_available && (
                    <div className="rounded-md border border-default bg-surface p-3">
                      <p className="text-xs font-semibold text-primary">Values for {entityName}</p>
                      <ul className="mt-2 space-y-1 text-xs text-secondary">
                        {Object.entries(selectedTemplate.field_mappings).map(([pdfField, recordKey]) => {
                          const fieldLabel = recordFields.find((field) => field.key === recordKey)?.label ?? recordKey;
                          return <li key={pdfField} className="flex flex-wrap justify-between gap-x-4"><span>{pdfField} ← {fieldLabel}</span><span className="text-muted">{values[recordKey] || 'No saved value'}</span></li>;
                        })}
                      </ul>
                      <p className="mt-2 text-xs text-muted">Required blank values will block generation. Check the PDF draft before issuing it.</p>
                    </div>
                  )}
                  {realm === 'supplier' && (effectiveDatePdfField || expiryDatePdfField) && (
                    <div className="grid gap-3 sm:grid-cols-2">
                      {effectiveDatePdfField && <Input
                        label="Agreement start date"
                        type="date"
                        required={effectiveDateRequired}
                        helper={effectiveDateRequired ? `Fills “${effectiveDatePdfField}” in the PDF.` : `Optional · fills “${effectiveDatePdfField}” in the PDF.`}
                        value={effectiveDate}
                        onChange={(event) => setEffectiveDate(event.target.value)}
                        disabled={templateMutation.isPending}
                      />}
                      {expiryDatePdfField && <Input
                        label="Agreement end date"
                        type="date"
                        min={effectiveDate || undefined}
                        required={expiryDateRequired}
                        helper={expiryDateRequired ? `Fills “${expiryDatePdfField}” in the PDF.` : `Optional · fills “${expiryDatePdfField}” in the PDF.`}
                        value={expiryDate}
                        onChange={(event) => setExpiryDate(event.target.value)}
                        error={dateRangeError}
                        disabled={templateMutation.isPending}
                      />}
                    </div>
                  )}
                  <Button size="sm" variant="secondary" onClick={() => templateId && requiredDatesReady && templateMutation.mutate(templateId)} disabled={!templateId || !requiredDatesReady || templateMutation.isPending} loading={templateMutation.isPending}>
                    Generate PDF draft
                  </Button>
                </>
              )}
              {canCreateTemplate && (
                <div className="space-y-3">
                  <Button type="button" size="sm" variant="secondary" onClick={() => setShowTemplateForm((current) => !current)}>
                    {showTemplateForm ? 'Cancel PDF mapping' : 'Create and fill from a PDF'}
                  </Button>
                  {showTemplateForm && (
                    <div className="space-y-3 rounded-md border border-default bg-canvas p-3">
                      <p className="text-xs leading-relaxed text-muted">Map fields in a fillable PDF to this saved customer’s details, allowed products, and warranty terms. PDFs without fillable fields can be attached above; their text is not imported automatically.</p>
                      <ContractTemplateForm
                        realm={realm}
                        previewValues={values}
                        onInspect={async (selectedFile, selectedRealm) => (await portalContractsApi.inspectTemplate(selectedFile, selectedRealm)).pdf_fields}
                        onSubmit={(submission) => createTemplateMutation.mutate(submission)}
                        isSubmitting={createTemplateMutation.isPending || templateMutation.isPending}
                        onCancel={() => setShowTemplateForm(false)}
                      />
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
          </div>
        </div>
      )}

      {documentsQuery.isLoading && <SkeletonTable columns={3} rows={3} />}
      {documentsQuery.isError && <EmptyState icon="alert-circle" title="Could not load agreement documents" action={<Button variant="secondary" onClick={() => documentsQuery.refetch()}>Retry</Button>} />}
      {documentsQuery.data && documentsQuery.data.length === 0 && (
        <p className="py-3 text-sm text-muted">No agreement PDFs have been attached for this {realm}.</p>
      )}
      {documentsQuery.data && documentsQuery.data.length > 0 && (
        <ul className="divide-y divide-subtle border-y border-default">
          {documentsQuery.data.map((document) => (
            <li key={document.id} className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <p className="break-words text-sm font-medium text-primary">{document.file_name}</p>
                <p className="mt-1 text-xs text-muted">
                  {document.uploaded_at ? `Added ${formatDateTime(document.uploaded_at)}` : 'Added'}
                  {document.signed_name ? ` · Signed by ${document.signed_name}` : ''}
                  {document.signed_at ? ` · ${formatDateTime(document.signed_at)}` : ''}
                </p>
              </div>
              <div className="flex shrink-0 flex-wrap items-center gap-2">
                <Chip variant={chipVariantForStatus(document.signature_status)}>{document.signature_status === 'draft' ? 'Staff draft' : document.signature_status === 'signed' ? 'Signed' : document.signature_status === 'cancelled' ? 'Request cancelled' : 'Awaiting signature'}</Chip>
                <Button size="xs" variant="ghost" onClick={() => void downloadDocument(document.id, document.file_name)} loading={downloadingId === document.id} disabled={downloadingId !== null}>
                  Download PDF
                </Button>
                {canManage && document.signature_status === 'draft' && (
                  <Button size="xs" variant="primary" onClick={() => issueMutation.mutate(document.id)} loading={issueMutation.isPending && issueMutation.variables === document.id} disabled={issueMutation.isPending}>
                    Issue to portal
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
