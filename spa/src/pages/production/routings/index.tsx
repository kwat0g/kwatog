/**
 * Task 12 — Production Routing list page.
 *
 * Displays all product routings with pagination, search by product,
 * and actions (view, duplicate). Follows the same 5-state pattern
 * (loading / error / empty / data / stale) as all other list pages.
 */
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Copy, Plus } from 'lucide-react';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { routingsApi, type RoutingListParams } from '@/api/production/routings';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { ProductRouting } from '@/types/production/routing';

export default function RoutingsListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [filters, setFilters] = useState<RoutingListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['production', 'routings', filters],
    queryFn: () => routingsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const duplicateMut = useMutation({
    mutationFn: (id: string) => routingsApi.duplicate(id),
    onSuccess: (routing) => {
      qc.invalidateQueries({ queryKey: ['production', 'routings'] });
      toast.success(`Routing duplicated as v${routing.version}.`);
      navigate(`/production/routings/${routing.id}`);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to duplicate routing.');
    },
  });

  const columns: Column<ProductRouting>[] = [
    {
      key: 'product', header: 'Product',
      cell: (r) => r.product
        ? (
          <Link to={`/production/routings/${r.id}`} className="hover:underline">
            <div className="font-mono text-xs text-accent">{r.product.part_number}</div>
            <div className="text-muted text-xs">{r.product.name}</div>
          </Link>
        )
        : <span className="text-muted">—</span>,
    },
    {
      key: 'version', header: 'Version', align: 'right',
      cell: (r) => <NumCell>v{r.version}</NumCell>,
    },
    {
      key: 'status', header: 'Status',
      cell: (r) => (
        <Chip variant={r.is_active ? 'success' : 'neutral'}>
          {r.is_active ? 'Active' : 'Inactive'}
        </Chip>
      ),
    },
    {
      key: 'cycle', header: 'Total cycle time', align: 'right',
      cell: (r) => <NumCell>{Number(r.total_cycle_time).toFixed(1)} min</NumCell>,
    },
    {
      key: 'ops', header: 'Operations', align: 'right',
      cell: (r) => <NumCell>{r.operations?.length ?? 0}</NumCell>,
    },
    {
      key: 'actions', header: '',
      cell: (r) => (
        <div className="flex items-center justify-end gap-1">
          <Button
            size="sm"
            variant="ghost"
            icon={<Copy size={14} />}
            onClick={(e) => {
              e.stopPropagation();
              duplicateMut.mutate(r.id);
            }}
            disabled={duplicateMut.isPending}
            aria-label="Duplicate routing"
          >
            Duplicate
          </Button>
        </div>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Routings"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'routing' : 'routings'}` : undefined}
        actions={
          <Button
            size="sm"
            variant="primary"
            icon={<Plus size={14} />}
            onClick={() => navigate('/production/routings/create')}
          >
            New routing
          </Button>
        }
      />
      <FilterBar
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        searchPlaceholder="Search by product part number or name..."
      />
      {isLoading && !data && <div className="px-5 py-4"><SkeletonTable columns={6} rows={8} /></div>}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load routings"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="factory"
          title="No routings yet"
          description="Create a routing to define the sequence of operations for a product."
          action={
            <Button
              variant="primary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => navigate('/production/routings/create')}
            >
              New routing
            </Button>
          }
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onRowClick={(r) => navigate(`/production/routings/${r.id}`)}
          />
        </div>
      )}
    </div>
  );
}
