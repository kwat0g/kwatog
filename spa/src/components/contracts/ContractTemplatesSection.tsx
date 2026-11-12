import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { portalContractsApi, type PortalContractTemplate } from '@/api/b2b/contracts';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { usePermission } from '@/hooks/usePermission';
import { reportMutationError } from '@/lib/formErrors';
import { ContractTemplateForm } from './ContractTemplateForm';
import type { ContractTemplateSubmission, PortalContractRealm } from './contractTemplateFields';

export function ContractTemplatesSection() {
  const { can } = usePermission();
  const canManage = can('b2b.portal_access.manage');
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [realm, setRealm] = useState<PortalContractRealm>('customer');
  const templatesQuery = useQuery({
    queryKey: ['portal-contracts', 'templates'],
    queryFn: () => portalContractsApi.listTemplates(),
    enabled: can('b2b.portal_access.view'),
  });
  const createMutation = useMutation({
    mutationFn: (submission: ContractTemplateSubmission) => portalContractsApi.createTemplate(submission),
    onSuccess: () => {
      setOpen(false);
      void queryClient.invalidateQueries({ queryKey: ['portal-contracts', 'templates'] });
      toast.success('Contract template saved. Review its field mapping before issuing documents.');
    },
    onError: (error) => reportMutationError(error, 'Could not save the contract template.'),
  });

  const templateDescription = (template: PortalContractTemplate) => template.auto_fill_available
    ? `${Object.keys(template.field_mappings).length} field${Object.keys(template.field_mappings).length === 1 ? '' : 's'} mapped · ${template.required_fields.length} required`
    : 'Attachment only · no fillable fields';

  return (
    <section className="space-y-4 px-5 py-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold text-primary">Contract templates</h2>
          <p className="mt-1 max-w-3xl text-sm text-muted">Upload customer or supplier PDFs once, map their fillable fields to saved records, then review each generated draft before issuing it.</p>
        </div>
        {canManage && <Button variant="primary" size="sm" onClick={() => setOpen(true)}>Add contract template</Button>}
      </div>

      {templatesQuery.isLoading && <SkeletonTable columns={4} rows={4} />}
      {templatesQuery.isError && <EmptyState icon="alert-circle" title="Could not load contract templates" action={<Button variant="secondary" onClick={() => templatesQuery.refetch()}>Retry</Button>} />}
      {templatesQuery.data?.length === 0 && <EmptyState icon="file-text" title="No contract templates yet" description="Add a PDF template to reuse it when preparing customer or supplier agreements." />}
      {templatesQuery.data && templatesQuery.data.length > 0 && (
        <ul className="divide-y divide-subtle border-y border-default">
          {templatesQuery.data.map((template) => (
            <li key={template.id} className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="break-words text-sm font-medium text-primary">{template.name}</span>
                  <Chip variant="neutral">{template.realm === 'customer' ? 'Customer' : 'Supplier'}</Chip>
                  <span className="text-xs text-muted">Version {template.version}</span>
                </div>
                <p className="mt-1 break-words text-xs text-secondary">{template.filename} · {templateDescription(template)}</p>
                {template.created_by && <p className="mt-1 text-xs text-muted">Added by {template.created_by}</p>}
              </div>
            </li>
          ))}
        </ul>
      )}

      <Modal isOpen={open} onClose={() => !createMutation.isPending && setOpen(false)} title="Add contract template" size="xl">
        <div className="space-y-4">
          <Select label="Portal type" value={realm} onChange={(event) => setRealm(event.target.value as PortalContractRealm)} disabled={createMutation.isPending}>
            <option value="customer">Customer</option>
            <option value="supplier">Supplier</option>
          </Select>
          <ContractTemplateForm
            key={realm}
            realm={realm}
            onInspect={async (file, targetRealm) => (await portalContractsApi.inspectTemplate(file, targetRealm)).pdf_fields}
            onSubmit={(submission) => createMutation.mutate(submission)}
            isSubmitting={createMutation.isPending}
            onCancel={() => setOpen(false)}
          />
        </div>
      </Modal>
    </section>
  );
}
