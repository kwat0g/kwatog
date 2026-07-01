/**
 * Task 16 — Document Control detail page.
 *
 * Shows document metadata, current revision, review status, and allows
 * publishing new revisions (file upload) and marking the document reviewed.
 */
import { useState, useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { CheckCircle, Upload } from 'lucide-react';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { documentsApi } from '@/api/quality/documents';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { DocumentCategory } from '@/types/quality/document';

const CATEGORY_CHIP: Record<DocumentCategory, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  sop: 'info',
  work_instruction: 'success',
  form: 'neutral',
  policy: 'warning',
  specification: 'info',
};

const CATEGORY_LABELS: Record<DocumentCategory, string> = {
  sop: 'SOP',
  work_instruction: 'Work Instruction',
  form: 'Form',
  policy: 'Policy',
  specification: 'Specification',
};

export default function DocumentDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [showRevisionForm, setShowRevisionForm] = useState(false);
  const [effectiveDate, setEffectiveDate] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'documents', id],
    queryFn: () => documentsApi.show(id),
    enabled: Boolean(id),
    placeholderData: (prev) => prev,
  });

  const markReviewed = useMutation({
    mutationFn: () => documentsApi.markReviewed(id),
    onSuccess: () => {
      toast.success('Document marked as reviewed');
      qc.invalidateQueries({ queryKey: ['quality', 'documents', id] });
      qc.invalidateQueries({ queryKey: ['quality', 'documents'] });
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to mark as reviewed');
    },
  });

  const publishRevision = useMutation({
    mutationFn: (formData: FormData) => documentsApi.publishRevision(id, formData),
    onSuccess: (doc) => {
      toast.success(`Revision ${doc.current_revision?.revision_number ?? ''} published`);
      qc.invalidateQueries({ queryKey: ['quality', 'documents', id] });
      qc.invalidateQueries({ queryKey: ['quality', 'documents'] });
      setShowRevisionForm(false);
      setEffectiveDate('');
      if (fileRef.current) fileRef.current.value = '';
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to publish revision');
    },
  });

  const handlePublishRevision = () => {
    const file = fileRef.current?.files?.[0];
    if (!file) {
      toast.error('Please select a file');
      return;
    }
    const formData = new FormData();
    formData.append('file', file);
    if (effectiveDate) {
      formData.append('effective_date', effectiveDate);
    }
    publishRevision.mutate(formData);
  };

  if (isLoading && !data) {
    return <SkeletonDetail />;
  }
  if (isError || !data) {
    return (
      <div>
        <EmptyState
          icon="alert-circle"
          title="Failed to load document"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      </div>
    );
  }

  // Determine review status
  const overdueReview = (() => {
    if (!data.review_interval_months || !data.is_active) return false;
    if (!data.last_reviewed_at) return true;
    const lastReview = new Date(data.last_reviewed_at);
    const due = new Date(lastReview);
    due.setMonth(due.getMonth() + data.review_interval_months);
    return due < new Date();
  })();

  const nextReviewDate = (() => {
    if (!data.review_interval_months || !data.last_reviewed_at) return null;
    const d = new Date(data.last_reviewed_at);
    d.setMonth(d.getMonth() + data.review_interval_months);
    return d.toISOString().slice(0, 10);
  })();

  return (
    <div>
      <PageHeader
        title={
          <span>
            <span className="font-mono">{data.code}</span>
            <span className="ml-2">{data.title}</span>
            <Chip variant={data.is_active ? 'success' : 'neutral'} className="ml-3">
              {data.is_active ? 'Active' : 'Inactive'}
            </Chip>
          </span>
        }
        breadcrumbs={[
          { label: 'Quality', href: '/quality' },
          { label: 'Documents', href: '/quality/documents' },
          { label: data.code },
        ]}
        actions={
          <div className="flex items-center gap-2">
            {can('quality.documents.manage') && data.is_active && (
              <>
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<CheckCircle size={14} />}
                  loading={markReviewed.isPending}
                  onClick={() => markReviewed.mutate()}
                >
                  Mark reviewed
                </Button>
                <Button
                  variant="primary"
                  size="sm"
                  icon={<Upload size={14} />}
                  onClick={() => setShowRevisionForm((v) => !v)}
                >
                  Publish revision
                </Button>
              </>
            )}
          </div>
        }
      />

      <div className="px-5 py-4 grid grid-cols-3 gap-4">
        {/* Left column — document info */}
        <div className="col-span-2 space-y-4">
          <Panel title="Document information">
            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Code</dt>
                <dd className="font-mono">{data.code}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Title</dt>
                <dd>{data.title}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Category</dt>
                <dd>
                  <Chip variant={CATEGORY_CHIP[data.category as DocumentCategory] ?? 'neutral'}>
                    {CATEGORY_LABELS[data.category as DocumentCategory] ?? data.category}
                  </Chip>
                </dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Assignee role</dt>
                <dd>{data.assignee_role?.replace(/_/g, ' ') ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Review interval</dt>
                <dd className="font-mono tabular-nums">
                  {data.review_interval_months ? `${data.review_interval_months} months` : '—'}
                </dd>
              </div>
              <div>
                <dt className="text-2xs uppercase tracking-wider text-muted">Last reviewed</dt>
                <dd className="font-mono tabular-nums">
                  {data.last_reviewed_at?.slice(0, 10) ?? 'Never'}
                </dd>
              </div>
            </dl>
            {data.description && (
              <div className="mt-3 pt-3 border-t border-subtle">
                <dt className="text-2xs uppercase tracking-wider text-muted mb-1">Description</dt>
                <dd className="text-sm whitespace-pre-line">{data.description}</dd>
              </div>
            )}
          </Panel>

          {/* Publish revision form — toggled inline */}
          {showRevisionForm && (
            <Panel title="Publish new revision">
              <div className="space-y-3">
                <div>
                  <label className="block text-xs font-medium text-secondary mb-1">
                    Document file <span className="text-danger">*</span>
                  </label>
                  <input
                    ref={fileRef}
                    type="file"
                    accept=".pdf,.doc,.docx,.xls,.xlsx"
                    className="block w-full text-sm text-secondary file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border file:border-default file:text-xs file:font-medium file:bg-elevated file:text-primary hover:file:bg-subtle"
                  />
                </div>
                <Input
                  label="Effective date"
                  type="date"
                  value={effectiveDate}
                  onChange={(e) => setEffectiveDate(e.target.value)}
                />
                <div className="flex items-center gap-2 pt-2">
                  <Button
                    variant="secondary"
                    size="sm"
                    type="button"
                    onClick={() => {
                      setShowRevisionForm(false);
                      setEffectiveDate('');
                      if (fileRef.current) fileRef.current.value = '';
                    }}
                  >
                    Cancel
                  </Button>
                  <Button
                    variant="primary"
                    size="sm"
                    loading={publishRevision.isPending}
                    onClick={handlePublishRevision}
                  >
                    Publish
                  </Button>
                </div>
              </div>
            </Panel>
          )}
        </div>

        {/* Right column — revision + review status */}
        <div className="space-y-4">
          <Panel title="Current revision">
            {data.current_revision ? (
              <dl className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <dt className="text-muted">Revision #</dt>
                  <dd className="font-mono tabular-nums">{data.current_revision.revision_number}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted">Effective date</dt>
                  <dd className="font-mono tabular-nums">
                    {data.current_revision.effective_date ?? '—'}
                  </dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted">Published at</dt>
                  <dd className="font-mono tabular-nums">
                    {data.current_revision.published_at?.slice(0, 10) ?? '—'}
                  </dd>
                </div>
              </dl>
            ) : (
              <p className="text-xs text-muted">No revisions published yet.</p>
            )}
          </Panel>

          <Panel title="Review status">
            {!data.review_interval_months ? (
              <p className="text-sm text-muted">Periodic review not configured for this document.</p>
            ) : overdueReview ? (
              <div>
                <Chip variant="warning">Overdue</Chip>
                <p className="text-sm text-muted mt-2">
                  {data.last_reviewed_at
                    ? `Last reviewed ${data.last_reviewed_at.slice(0, 10)}. Due ${nextReviewDate}.`
                    : 'This document has never been reviewed.'}
                </p>
              </div>
            ) : (
              <div>
                <Chip variant="success">Current</Chip>
                <p className="text-sm text-muted mt-2">
                  Last reviewed {data.last_reviewed_at?.slice(0, 10)}. Next review due {nextReviewDate}.
                </p>
              </div>
            )}
          </Panel>

          <Panel title="Actions">
            <Link to="/quality/documents" className="text-xs text-accent hover:underline">
              &larr; Back to documents
            </Link>
          </Panel>
        </div>
      </div>
    </div>
  );
}
