/**
 * Task 16 — Document Control list page.
 *
 * Catalog of controlled documents (SOPs, work instructions, forms, policies,
 * specifications). Shows current revision, category, and review status. A
 * warning indicator highlights documents overdue for review.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus, AlertCircle } from 'lucide-react';
import { documentsApi, type DocumentListParams } from '@/api/quality/documents';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { ControlledDocument, DocumentCategory } from '@/types/quality/document';

type ChipVariant = 'success' | 'danger' | 'warning' | 'neutral' | 'info';

const CATEGORY_CHIP: Record<DocumentCategory, ChipVariant> = {
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

/** Returns true when the document is overdue for review. */
function isOverdue(doc: ControlledDocument): boolean {
  if (!doc.review_interval_months || !doc.is_active) return false;
  if (!doc.last_reviewed_at) return true;
  const lastReview = new Date(doc.last_reviewed_at);
  const due = new Date(lastReview);
  due.setMonth(due.getMonth() + doc.review_interval_months);
  return due < new Date();
}

export default function DocumentsListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<DocumentListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'documents', filters],
    queryFn: () => documentsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<ControlledDocument>[] = [
    {
      key: 'code',
      header: 'Code',
      cell: (r) => (
        <Link to={`/quality/documents/${r.id}`} className="font-mono text-accent hover:underline">
          {r.code}
        </Link>
      ),
    },
    {
      key: 'title',
      header: 'Title',
      cell: (r) => <span>{r.title}</span>,
    },
    {
      key: 'category',
      header: 'Category',
      cell: (r) => (
        <Chip variant={CATEGORY_CHIP[r.category as DocumentCategory] ?? 'neutral'}>
          {CATEGORY_LABELS[r.category as DocumentCategory] ?? r.category}
        </Chip>
      ),
    },
    {
      key: 'revision',
      header: 'Rev',
      align: 'right',
      cell: (r) => (
        <NumCell>{r.current_revision ? r.current_revision.revision_number : '—'}</NumCell>
      ),
    },
    {
      key: 'review_status',
      header: 'Review Status',
      cell: (r) => {
        if (!r.review_interval_months) {
          return <span className="text-muted text-xs">No review</span>;
        }
        if (isOverdue(r)) {
          return (
            <span className="inline-flex items-center gap-1">
              <AlertCircle size={12} className="text-warning" />
              <Chip variant="warning">Overdue</Chip>
            </span>
          );
        }
        return <Chip variant="success">Current</Chip>;
      },
    },
    {
      key: 'active',
      header: 'Status',
      cell: (r) => (
        <Chip variant={r.is_active ? 'success' : 'neutral'}>
          {r.is_active ? 'Active' : 'Inactive'}
        </Chip>
      ),
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'category',
      label: 'Category',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'sop', label: 'SOP' },
        { value: 'work_instruction', label: 'Work Instruction' },
        { value: 'form', label: 'Form' },
        { value: 'policy', label: 'Policy' },
        { value: 'specification', label: 'Specification' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Documents"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'document' : 'documents'}` : undefined}
        actions={
          can('quality.documents.manage') ? (
            <Button
              variant="primary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => navigate('/quality/documents/new')}
            >
              New document
            </Button>
          ) : undefined
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by code or title..."
      />
      {isLoading && !data && <SkeletonTable columns={6} rows={6} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load documents"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="file-text"
          title="No documents yet"
          description="Create your first controlled document to track SOPs, work instructions, forms, and policies."
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
          />
        </div>
      )}
    </div>
  );
}
