/**
 * ADV7 — NCR Template list page.
 *
 * Lists saved NCR templates. Each row has a "Use" button that pre-fills
 * the NCR creation form with the template's values, plus edit/delete.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus, Pencil, Trash2, Copy } from 'lucide-react';
import toast from 'react-hot-toast';
import { ncrTemplatesApi } from '@/api/quality/ncr-templates';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { AxiosError } from 'axios';
import type { NcrTemplate } from '@/types/quality';

const SEVERITY_CHIP: Record<string, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  minor: 'neutral',
  major: 'warning',
  critical: 'danger',
};

export default function NcrTemplatesListPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [deleteId, setDeleteId] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'ncr-templates'],
    queryFn: () => ncrTemplatesApi.list({ per_page: 100 }),
    placeholderData: (prev) => prev,
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => ncrTemplatesApi.destroy(id),
    onSuccess: () => {
      toast.success('Template deleted');
      queryClient.invalidateQueries({ queryKey: ['quality', 'ncr-templates'] });
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to delete template');
    },
  });

  const handleUseTemplate = (tpl: NcrTemplate) => {
    navigate('/quality/ncrs/new', { state: { template: tpl } });
  };

  const columns: Column<NcrTemplate>[] = [
    { key: 'name', header: 'Name', cell: (r) => <span className="font-medium">{r.name}</span> },
    {
      key: 'product',
      header: 'Product',
      cell: (r) =>
        r.product ? (
          <span>
            <span className="font-mono">{r.product.part_number}</span>
            <span className="ml-2 text-muted">{r.product.name}</span>
          </span>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: 'source',
      header: 'Source',
      cell: (r) => <Chip variant="neutral">{r.source.replace('_', ' ')}</Chip>,
    },
    {
      key: 'severity',
      header: 'Severity',
      cell: (r) => <Chip variant={SEVERITY_CHIP[r.severity]}>{r.severity}</Chip>,
    },
    {
      key: 'is_active',
      header: 'Active',
      cell: (r) => (r.is_active ? <Chip variant="success">Yes</Chip> : <Chip variant="neutral">No</Chip>),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      cell: (r) => (
        <div className="flex items-center justify-end gap-1">
          <Button
            size="sm"
            variant="ghost"
            icon={<Copy size={13} />}
            onClick={(e) => {
              e.stopPropagation();
              handleUseTemplate(r);
            }}
          >
            Use
          </Button>
          {can('quality.ncr.manage') && (
            <>
              <Button
                size="sm"
                variant="ghost"
                icon={<Pencil size={13} />}
                onClick={(e) => {
                  e.stopPropagation();
                  navigate(`/quality/ncr-templates/${r.id}/edit`);
                }}
              />
              <Button
                size="sm"
                variant="ghost"
                icon={<Trash2 size={13} />}
                onClick={(e) => {
                  e.stopPropagation();
                  setDeleteId(r.id);
                }}
              />
            </>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="NCR templates"
        subtitle={data ? `${data.meta.total} template${data.meta.total === 1 ? '' : 's'}` : undefined}
        actions={
          can('quality.ncr.manage') ? (
            <Button
              variant="primary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => navigate('/quality/ncr-templates/new')}
            >
              New template
            </Button>
          ) : undefined
        }
      />
      {isLoading && !data && <SkeletonTable columns={6} rows={4} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load templates"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="file-text"
          title="No NCR templates"
          description="Create templates for common quality issues to speed up NCR creation."
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta} />
        </div>
      )}
      <ConfirmDialog
        isOpen={deleteId !== null}
        onClose={() => setDeleteId(null)}
        onConfirm={() => {
          if (deleteId !== null) {
            deleteMut.mutate(deleteId);
            setDeleteId(null);
          }
        }}
        title="Delete template?"
        description="This cannot be undone."
        variant="danger"
        confirmLabel="Delete"
        pending={deleteMut.isPending}
      />
    </div>
  );
}
