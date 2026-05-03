import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ShieldCheck } from 'lucide-react';
import { rolesApi, type Role } from '@/api/admin/roles';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { ListParams } from '@/types';

const columns: Column<Role>[] = [
  {
    key: 'name',
    header: 'Role',
    sortable: true,
    cell: (row) => (
      <div className="flex items-center gap-2">
        <span className="h-6 w-6 inline-flex items-center justify-center rounded-md bg-elevated text-muted">
          <ShieldCheck size={12} />
        </span>
        <StackedCell primary={row.name} secondary={<span className="font-mono">{row.slug}</span>} />
      </div>
    ),
  },
  {
    key: 'description',
    header: 'Description',
    cell: (row) => <span className="text-muted">{row.description ?? '—'}</span>,
  },
  {
    key: 'users_count',
    header: 'Users',
    align: 'right',
    cell: (row) => <NumCell>{row.users_count ?? 0}</NumCell>,
  },
  {
    key: 'permissions_count',
    header: 'Permissions',
    align: 'right',
    cell: (row) => <NumCell>{row.permissions_count ?? 0}</NumCell>,
  },
  {
    key: 'system',
    header: 'Type',
    cell: (row) =>
      row.slug === 'system_admin'
        ? <Chip variant="info">System</Chip>
        : <Chip variant="neutral">Custom</Chip>,
  },
];

export default function RolesIndexPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useState<ListParams>({ page: 1, per_page: 25, sort: 'name', direction: 'asc' });

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'roles', filters],
    queryFn: () => rolesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  return (
    <div>
      <PageHeader
        title="Roles"
        subtitle={data ? `${data.meta.total} roles` : undefined}
      />

      <FilterBar
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        searchPlaceholder="Search roles…"
      />

      <div className="px-5 py-4">
        {isLoading && !data && <SkeletonTable columns={5} rows={8} />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load roles"
            description="We couldn't load the role list. Please retry."
            action={
              <Button variant="secondary" onClick={() => window.location.reload()}>
                Retry
              </Button>
            }
          />
        )}

        {data && data.data.length === 0 && (
          <EmptyState
            icon="lock"
            title="No roles found"
            description={filters.search ? `No roles match "${filters.search}".` : 'Roles are seeded automatically.'}
          />
        )}

        {data && data.data.length > 0 && (
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onSort={(sort, direction) => setFilters((f) => ({ ...f, sort, direction, page: 1 }))}
            currentSort={filters.sort}
            currentDirection={filters.direction}
            onRowClick={(row) => navigate(`/admin/roles/${row.id}/permissions`)}
            getRowId={(row) => row.id}
          />
        )}
      </div>
    </div>
  );
}
