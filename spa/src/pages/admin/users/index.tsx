import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import toast from 'react-hot-toast';
import {
  Button,
  Chip,
  EmptyState,
  FilterBar,
  Modal,
  Select,
  SkeletonTable,
  Textarea,
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
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [filters, setFilters] = useState<AdminUserListFilters>({
    page: 1,
    per_page: 25,
    sort: 'last_activity',
    direction: 'desc',
  });
  const [selectedRows, setSelectedRows] = useState<AdminUserListItem[]>([]);
  const [bulkRoleModalOpen, setBulkRoleModalOpen] = useState(false);
  const [selectedRoleId, setSelectedRoleId] = useState<string>('');
  const [bulkReason, setBulkReason] = useState<string>('');

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

  const bulkChangeRole = useMutation({
    mutationFn: ({ userIds, roleId, reason }: { userIds: string[]; roleId: string; reason: string }) =>
      adminUsersApi.bulkChangeRole(userIds, roleId, reason),
    onSuccess: (r) => {
      toast.success(`${r.updated} user(s) updated.`);
      setBulkRoleModalOpen(false);
      setBulkReason('');
      setSelectedRoleId('');
      setSelectedRows([]);
      queryClient.invalidateQueries({ queryKey: ['admin-users'] });
    },
    onError: () => toast.error('Failed to update roles.'),
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

  const openBulkRoleModal = () => {
    setSelectedRoleId('');
    setBulkReason('');
    setBulkRoleModalOpen(true);
  };

  const submitBulkRoleChange = () => {
    if (!selectedRoleId || bulkReason.length < 5 || selectedRows.length === 0) return;
    bulkChangeRole.mutate({
      userIds: selectedRows.map((r) => r.id),
      roleId: selectedRoleId,
      reason: bulkReason,
    });
  };

  const data = usersQuery.data;

  return (
    <div>
      <PageHeader
        title="User Management"
        subtitle={data ? `${data.meta.total} users` : undefined}
        backTo="/admin/users"
        backLabel="Admin"
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
          selectable={can('admin.users.manage')}
          bulkActions={
            can('admin.users.manage')
              ? [
                  {
                    label: 'Change role',
                    variant: 'secondary',
                    onClick: (rows) => {
                      setSelectedRows(rows);
                      openBulkRoleModal();
                    },
                  },
                ]
              : undefined
          }
        />
      )}

      <Modal
        isOpen={bulkRoleModalOpen}
        onClose={() => {
          setBulkRoleModalOpen(false);
          setBulkReason('');
          setSelectedRoleId('');
        }}
        title="Bulk Change Role"
        size="sm"
      >
        <div className="space-y-4 py-2">
          <div>
            <label className="block text-2xs uppercase tracking-wider text-muted font-medium mb-1">
              Role
            </label>
            <Select
              value={selectedRoleId}
              onChange={(e) => setSelectedRoleId(e.target.value)}
              disabled={bulkChangeRole.isPending}
            >
              <option value="">— Select role —</option>
              {(rolesQuery.data?.data ?? []).map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="block text-2xs uppercase tracking-wider text-muted font-medium mb-1">
              Reason (required)
            </label>
            <Textarea
              value={bulkReason}
              onChange={(e) => setBulkReason(e.target.value)}
              placeholder="Enter reason for bulk role change (min 5 characters)…"
              disabled={bulkChangeRole.isPending}
              rows={3}
            />
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button
              variant="secondary"
              size="sm"
              onClick={() => setBulkRoleModalOpen(false)}
              disabled={bulkChangeRole.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="primary"
              size="sm"
              onClick={submitBulkRoleChange}
              disabled={bulkChangeRole.isPending || !selectedRoleId || bulkReason.length < 5}
              loading={bulkChangeRole.isPending}
            >
              Update Roles
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}