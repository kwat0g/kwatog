import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { portalContractsApi, type PortalAgreementDocument } from '@/api/b2b/contracts';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PortalContractSignatureDialog } from '@/components/contracts/PortalContractSignatureDialog';
import type { PortalContractRealm } from '@/components/contracts/contractTemplateFields';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime } from '@/lib/formatDate';
import { reportMutationError } from '@/lib/formErrors';

interface PortalAgreementsPageProps {
  realm: PortalContractRealm;
}

export default function PortalAgreementsPage({ realm }: PortalAgreementsPageProps) {
  const queryClient = useQueryClient();
  const [selectedDocument, setSelectedDocument] = useState<PortalAgreementDocument | null>(null);
  const [downloadingId, setDownloadingId] = useState<string | null>(null);
  const queryKey = ['portal-contracts', 'portal-documents', realm] as const;
  const documentsQuery = useQuery({
    queryKey,
    queryFn: () => portalContractsApi.listPortalDocuments(realm),
  });
  const signMutation = useMutation({
    mutationFn: (data: { documentId: string; typedName: string }) => portalContractsApi.signPortalDocument(realm, data.documentId, data.typedName),
    onSuccess: () => {
      setSelectedDocument(null);
      void queryClient.invalidateQueries({ queryKey });
      toast.success('Agreement signed successfully.');
    },
    onError: (error) => reportMutationError(error, 'Could not sign this agreement. Contact your account manager for help.'),
  });

  const downloadDocument = async (document: PortalAgreementDocument) => {
    setDownloadingId(document.id);
    try {
      const blob = await portalContractsApi.downloadPortalDocument(realm, document.id);
      const url = URL.createObjectURL(blob);
      const anchor = window.document.createElement('a');
      anchor.href = url;
      anchor.download = document.file_name;
      anchor.hidden = true;
      window.document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
      reportMutationError(error, 'Could not download this agreement PDF.');
    } finally {
      setDownloadingId(null);
    }
  };

  const title = realm === 'customer' ? 'Customer agreements' : 'Supplier agreements';
  const signerName = documentsQuery.data?.signer_name ?? '';

  return (
    <div>
      <PageHeader title="Agreements" subtitle="Review issued contracts and sign agreements for your organization" />
      <div className="space-y-4 px-5 py-5">
        {documentsQuery.isLoading && <SkeletonTable columns={3} rows={4} />}
        {documentsQuery.isError && <EmptyState icon="alert-circle" title="Could not load agreements" action={<Button variant="secondary" onClick={() => documentsQuery.refetch()}>Retry</Button>} />}
        {documentsQuery.data?.data.length === 0 && (
          <EmptyState icon="file-text" title="No agreements to review" description="When an account manager issues an agreement, it will appear here for download and signature." />
        )}
        {documentsQuery.data && documentsQuery.data.data.length > 0 && (
          <ul className="divide-y divide-subtle border-y border-default">
            {documentsQuery.data.data.map((document) => (
              <li key={document.id} className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                  <p className="break-words text-sm font-medium text-primary">{document.file_name}</p>
                  <p className="mt-1 text-xs text-muted">
                    {document.issued_at ? `Issued ${formatDateTime(document.issued_at)}` : 'Issued by your account manager'}
                    {document.signed_name ? ` · Signed by ${document.signed_name}` : ''}
                    {document.signed_at ? ` · ${formatDateTime(document.signed_at)}` : ''}
                  </p>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                  <Chip variant={chipVariantForStatus(document.signature_status)}>{document.signature_status === 'signed' ? 'Signed' : 'Awaiting signature'}</Chip>
                  <Button size="sm" variant="secondary" onClick={() => void downloadDocument(document)} loading={downloadingId === document.id} disabled={downloadingId !== null}>Download PDF</Button>
                  {document.signature_status === 'pending' && documentsQuery.data.can_sign && (
                    <Button size="sm" variant="primary" onClick={() => setSelectedDocument(document)}>Sign agreement</Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
        {documentsQuery.data && !documentsQuery.data.can_sign && documentsQuery.data.data.some((document) => document.signature_status === 'pending') && (
          <p className="text-sm text-muted">Your account can review these PDFs. Ask your account administrator to designate an authorized signatory if you need to sign.</p>
        )}
      </div>
      <PortalContractSignatureDialog
        isOpen={selectedDocument !== null}
        documentName={selectedDocument?.file_name ?? ''}
        signerName={signerName}
        isSubmitting={signMutation.isPending}
        onCancel={() => setSelectedDocument(null)}
        onConfirm={(typedName) => selectedDocument && signMutation.mutate({ documentId: selectedDocument.id, typedName })}
      />
      <span className="sr-only">{title}</span>
    </div>
  );
}
