/**
 * Sprint 7 — Task 59 — Inspection specs list page.
 *
 * One row per product that has a spec. Clicking the part number opens the
 * editor (always /quality/inspection-specs/{product_hash_id} — keyed on
 * product because there is exactly one active spec per product).
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { inspectionSpecsApi, type InspectionSpecListParams } from '@/api/quality/inspectionSpecs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { InspectionSpec } from '@/types/quality';

export default function InspectionSpecsListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<InspectionSpecListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['quality', 'inspection-specs', filters],
    queryFn: () => inspectionSpecsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<InspectionSpec>[] = [
    {
      key: 'product', header: 'Product',
      cell: (r) => r.product
        ? <Link to={`/quality/inspection-specs/${r.product.id}`} className="hover:underline">
            <span className="font-mono text-accent">{r.product.part_number}</span>
            <span className="ml-2 text-muted">{r.product.name}</span>
          </Link>
        : <span className="text-muted">—</span>,
    },
    { key: 'version', header: 'Version', align: 'right',
      cell: (r) => <NumCell>v{r.version}</NumCell> },
    { key: 'item_count', header: 'Parameters', align: 'right',
      cell: (r) => <NumCell>{r.item_count}</NumCell> },
    { key: 'is_active', header: 'Status',
      cell: (r) => r.is_active
        ? <Chip variant="success">Active</Chip>
        : <Chip variant="neutral">Archived</Chip> },
    { key: 'updated', header: 'Updated', align: 'right',
      cell: (r) => <NumCell>{r.updated_at?.slice(0, 10) ?? '—'}</NumCell> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'is_active', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'true', label: 'Active' }, { value: 'false', label: 'Archived' },
    ] },
  ];

  return (
    <div>
      <PageHeader
        title="Inspection specs"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'spec' : 'specs'}` : undefined}
        actions={can('quality.specs.manage') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/quality/inspection-specs/new')}>
            New spec
          </Button>
        ) : undefined}
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search…"
      />
      {isLoading && !data && <SkeletonTable columns={5} rows={6} />}
      {isError && <EmptyState
        icon="alert-circle"
        title="Failed to load inspection specs"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
      />}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="clipboard-check"
          title="No inspection specs yet"
          description="Create a spec from any product detail page or click 'New spec' above."
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
