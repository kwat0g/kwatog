import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus, Pencil, Trash2, Copy } from 'lucide-react';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { prTemplatesApi } from '@/api/purchasing/purchase-requests';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import type { PurchaseRequestTemplate } from '@/types/purchasing';

const errMsg = (e: unknown, fallback: string) =>
  (e instanceof AxiosError ? e.response?.data?.message : undefined) ?? fallback;

export default function PrTemplatesListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [filters, setFilters] = useState<Record<string, unknown>>({ page: 1, per_page: 25 });
  const [deleteId, setDeleteId] = useState<number | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'pr-templates', filters],
    queryFn: () => prTemplatesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => prTemplatesApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['purchasing', 'pr-templates'] });
      toast.success('Template deleted.');
      setDeleteId(null);
    },
    onError: (e) => toast.error(errMsg(e, 'Failed to delete template.')),
  });

  const useTemplate = useMutation({
    mutationFn: (template: PurchaseRequestTemplate) => {
      return purchaseRequestsApi.create({
        template_id: template.id,
        department_id: template.department?.id ? Number(template.department.id) : undefined,
        items: template.items.map((i) => ({
          item_id: i.item_id ?? null,
          description: i.description,
          quantity: String(i.quantity),
          unit: i.unit ?? undefined,
          estimated_unit_price: i.estimated_unit_price ?? undefined,
        })),
      });
    },
    onSuccess: (pr) => {
      toast.success('PR created from template.');
      navigate(`/purchasing/purchase-requests/${pr.id}`);
    },
    onError: (e) => toast.error(errMsg(e, 'Failed to create PR from template.')),
  });

  const columns: Column<PurchaseRequestTemplate>[] = [
    { key: 'name', header: 'Name', cell: (r) => (
      <button className="font-medium text-accent hover:underline cursor-pointer" onClick={() => navigate(`/purchasing/pr-templates/${r.id}`)}>{r.name}</button>
    )},
    { key: 'department', header: 'Department', cell: (r) => r.department?.name ?? '—' },
    { key: 'items', header: 'Items', cell: (r) => `${r.items.length} line(s)` },
    { key: 'notes', header: 'Notes', cell: (r) => r.notes ?? '—' },
    { key: 'active', header: 'Active', cell: (r) => (
      <Chip variant={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'Active' : 'Inactive'}</Chip>
    )},
    { key: 'created', header: 'Created', cell: (r) => r.created_at ? formatDate(r.created_at) : '—' },
    { key: 'actions', header: '', cell: (r) => (
      <div className="flex items-center gap-1">
        <button title="Use template" onClick={() => useTemplate.mutate(r)} className="p-1.5 rounded hover:bg-subtle text-muted hover:text-accent transition-colors">
          <Copy size={14} />
        </button>
        <button title="Edit" onClick={() => navigate(`/purchasing/pr-templates/${r.id}/edit`)} className="p-1.5 rounded hover:bg-subtle text-muted hover:text-accent transition-colors">
          <Pencil size={14} />
        </button>
        {can('purchasing.pr.create') && (
          <button title="Delete" onClick={() => setDeleteId(r.id)} className="p-1.5 rounded hover:bg-subtle text-muted hover:text-danger transition-colors">
            <Trash2 size={14} />
          </button>
        )}
      </div>
    )},
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'is_active', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'true', label: 'Active' }, { value: 'false', label: 'Inactive' },
    ]},
  ];

  return (
    <div>
      <PageHeader title="PR Templates" subtitle={data ? `${data.meta.total} templates` : undefined}
        actions={can('purchasing.pr.create') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/purchasing/pr-templates/create')}>New Template</Button>
        ) : null} />
      <FilterBar filters={filterConfig} values={filters}
        onSearch={(s) => setFilters(f => ({ ...f, search: s, page: 1 }))}
        onFilter={(k, v) => setFilters(f => ({ ...f, [k]: v, page: 1 }))}
        searchPlaceholder="Search template name…" />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load templates" action={<Button onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="inbox" title="No PR templates"
          action={can('purchasing.pr.create') ? <Button variant="primary" onClick={() => navigate('/purchasing/pr-templates/create')}>New Template</Button> : undefined} />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters(f => ({ ...f, page }))} />
        </div>
      )}

      <ConfirmDialog
        isOpen={deleteId !== null}
        onClose={() => setDeleteId(null)}
        onConfirm={() => { if (deleteId !== null) deleteMutation.mutate(deleteId); }}
        title="Delete template?"
        description="This action is permanent. PRs already created from this template are unaffected."
        confirmLabel="Delete"
        variant="danger"
        pending={deleteMutation.isPending}
      />
    </div>
  );
}
