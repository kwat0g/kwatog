import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import {
  Button,
  Chip,
  EmptyState,
  FilterBar,
  SkeletonTable,
  type FilterConfig,
} from '@/components/ui';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { adminUsersApi } from '@/api/admin/users';
import { client } from '@/api/client';
import type {
  AdminUserListItem,
  AdminUserListFilters,
  AdminUserStatus,
} from '@/types/admin';

const statusVariant: Record<AdminUserStatus, 'success' | 'warning' | 'neutral'> = {
  active: 'success',
  locked: 'warning',
  inactive: 'neutral',
};

interface RoleOption { id: string; name: string }

/** U2 — Admin > Users list page. */
export default function AdminUsersIndexPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<AdminUserListFilters>({
    page: 1,
    per_page: 25,
    sort: 'last_activity',
    direction: 'desc',
  });

  const usersQuery = useQuery({
    queryKey: ['admin-users', filters],
    queryFn: () => adminUsersApi.list(filters),
    placeholderData: (previousData) => previousData,
  });

  const rolesQuery = useQuery<{ data: RoleOption[] }>({
    queryKey: ['admin-roles-list'],
    queryFn: () => client.get('/admin/roles').then((r) => r.data),
    staleTime: 60_000,
  });

  const filterConfig: FilterConfig[] = [
    {
      key: 'role_id',
      label: 'Role',
      type: 'select',
      options: (rolesQuery.data?.data ?? []).map((r) => ({ value: r.id, label: r.name })),
    },
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: 'active', label: 'Active' },
        { value: 'inactive', label: 'Inactive' },
        { value: 'locked', label: 'Locked' },
      ],
    },
  ];

  const columns: Column<AdminUserListItem>[] = [
    {
      key: 'name',
      header: 'Name',
      sortable: true,
      cell: (row) => (
        <Link to={`/admin/users/${row.id}`} className="font-medium text-primary hover:underline">
          {row.name}
        </Link>
      ),
    },
    {
      key: 'email',
      header: 'Email',
      sortable: true,
      cell: (row) => (
        <span className="font-mono tabular-nums text-secondary">{row.email}</span>
      ),
    },
    {
      key: 'role',
      header: 'Role',
      cell: (row) => row.role?.name ?? <span className="text-subtle">—</span>,
    },
    {
      key: 'employee',
      header: 'Linked Employee',
      cell: (row) =>
        row.employee ? (
          <Link
            to={`/hr/employees/${row.employee.id}`}
            className="font-mono tabular-nums text-accent hover:underline"
          >
            {row.employee.employee_no}
          </Link>
        ) : (
          <span className="text-subtle">—</span>
        ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (row) => <Chip variant={statusVariant[row.status]}>{row.status}</Chip>,
    },
    {
      key: 'last_activity',
      header: 'Last Login',
      sortable: true,
      cell: (row) => (
        <span className="font-mono tabular-nums text-secondary">
          {row.last_activity ? new Date(row.last_activity).toLocaleString() : 'Never'}
        </span>
      ),
    },
  ];

  const setFilter = (key: string, value: unknown) =>
    setFilters((f) => ({ ...f, [key]: (value as string) || undefined, page: 1 }));

  const data = usersQuery.data;

  return (
    <div>
      <PageHeader
        title="User Management"
        subtitle={data ? `${data.meta.total} users` : undefined}
        actions={
          can('admin.users.manage') && (
            <Button
              variant="primary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => navigate('/admin/users/create')}
            >
              Create User
            </Button>
          )
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters as unknown as Record<string, unknown>}
        onFilter={setFilter}
        onSearch={(s) => setFilter('search', s)}
        searchPlaceholder="Search by name or email…"
      />

      {usersQuery.isLoading && !data && <SkeletonTable columns={6} rows={10} />}

      {usersQuery.isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load users"
          description="An error occurred while loading the user list."
          action={
            <Button variant="secondary" onClick={() => usersQuery.refetch()}>
              Retry
            </Button>
          }
        />
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="inbox"
          title="No users found"
          description={
            filters.search
              ? `No users match "${filters.search}". Try a different search.`
              : 'No users match the current filters.'
          }
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
          onRowClick={(row) => navigate(`/admin/users/${row.id}`)}
        />
      )}
    </div>
  );
}
