import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Plus, ShieldCheck, Trash2, Copy, KeyRound, GitCompareArrows } from 'lucide-react';
import { rolesApi, type Role } from '@/api/admin/roles';
import { formatDateTime } from '@/lib/formatDate';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Tooltip } from '@/components/ui/Tooltip';
import { PageHeader } from '@/components/layout/PageHeader';
import { CanDo } from '@/components/guards/CanDo';
import { usePermission } from '@/hooks/usePermission';
import type { RoleListParams } from '@/api/admin/roles';

/**
 * Series R — Task R1.
 *
 * Role list. Adds:
 *   - "New role" button (gated by admin.roles.manage).
 *   - System / Custom chip driven by the new `is_system` field.
 *   - Per-row Edit permissions / Clone / Delete actions, with system roles
 *     hard-disabled and rationale exposed via tooltip.
 *   - Type filter (System / Custom / All).
 *
 * Backend protection still wins: clicking Delete on a system role would 422
 * with the same message even if the UI failed to disable the button.
 */
export default function RolesIndexPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const formatModification = (row: Role): string | null => {
    if (row.last_modified_by) return row.last_modified_by;
    if (row.last_modified_at) return '(unknown user)';
    return null;
  };
  const [filters, setFilters] = useState<RoleListParams>({
    page: 1,
    per_page: 25,
    sort: 'name',
    direction: 'asc',
  });
  const [confirmDelete, setConfirmDelete] = useState<Role | null>(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'roles', filters],
    queryFn: () => rolesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const remove = useMutation({
    mutationFn: (role: Role) => rolesApi.delete(role.id),
    onSuccess: () => {
      toast.success('Role deleted.');
      setConfirmDelete(null);
      queryClient.invalidateQueries({ queryKey: ['admin', 'roles'] });
    },
    onError: (err: { response?: { data?: { message?: string } } }) => {
      toast.error(err?.response?.data?.message ?? 'Could not delete role.');
    },
  });

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
          <StackedCell
            primary={row.name}
            secondary={<span className="font-mono">{row.slug}</span>}
          />
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
      key: 'type',
      header: 'Type',
      cell: (row) =>
        row.is_system ? (
          <Chip variant="info">System</Chip>
        ) : (
          <Chip variant="neutral">Custom</Chip>
        ),
    },
    {
      key: 'last_modified',
      header: 'Last modified',
      cell: (row) => {
        const who = formatModification(row);
        return who ? (
          <StackedCell
            primary={who}
            secondary={
              <span className="text-muted">
                {row.last_modified_at ? formatDateTime(row.last_modified_at) : '—'}
              </span>
            }
          />
        ) : (
          <span className="text-muted">—</span>
        );
      },
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      cell: (row) => (
        <div className="flex items-center justify-end gap-1.5" onClick={(e) => e.stopPropagation()}>
          <CanDo permission="admin.roles.manage">
            <Button
              variant="ghost"
              size="sm"
              icon={<KeyRound size={12} />}
              onClick={() => navigate(`/admin/roles/${row.id}/permissions`)}
              aria-label={`Edit permissions for ${row.name}`}
            >
              Permissions
            </Button>
          </CanDo>
          <CanDo permission="admin.roles.manage">
            <Tooltip content="Clone into a new custom role">
              <Button
                variant="ghost"
                size="sm"
                icon={<Copy size={12} />}
                onClick={() =>
                  navigate('/admin/roles/create', { state: { cloneFrom: row.id } })
                }
                aria-label={`Clone ${row.name}`}
              >
                Clone
              </Button>
            </Tooltip>
          </CanDo>
          <CanDo permission="admin.roles.manage">
            <Tooltip
              content={
                row.is_system
                  ? 'System roles cannot be deleted.'
                  : (row.users_count ?? 0) > 0
                    ? 'Cannot delete a role with assigned users.'
                    : 'Delete this role.'
              }
            >
              <span>
                <Button
                  variant="ghost"
                  size="sm"
                  icon={<Trash2 size={12} />}
                  disabled={row.is_system || (row.users_count ?? 0) > 0}
                  onClick={() => setConfirmDelete(row)}
                  aria-label={`Delete ${row.name}`}
                >
                  Delete
                </Button>
              </span>
            </Tooltip>
          </CanDo>
        </div>
      ),
    },
  ];

  const filterConfig = [
    {
      key: 'is_system',
      label: 'Type',
      type: 'select' as const,
      options: [
        { value: 'true', label: 'System' },
        { value: 'false', label: 'Custom' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Roles & permissions"
        subtitle={data ? `${data.meta.total} roles` : undefined}
        backTo="/admin/users-roles"
        backLabel="Users & Roles"
        actions={
          can('admin.roles.manage') && (
            <>
              <Button
                variant="secondary"
                size="sm"
                icon={<GitCompareArrows size={14} />}
                onClick={() => navigate('/admin/roles/compare')}
              >
                Compare roles
              </Button>
              <Button
                variant="primary"
                size="sm"
                icon={<Plus size={14} />}
                onClick={() => navigate('/admin/roles/create')}
              >
                New role
              </Button>
            </>
          )
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        searchPlaceholder="Search roles…"
      />

      <div className="px-5 py-4">
        {isLoading && !data && <SkeletonTable columns={6} rows={8} />}

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
            description={
              filters.search
                ? `No roles match "${filters.search}".`
                : 'Create your first custom role to start tailoring permission sets for your team.'
            }
            action={
              can('admin.roles.manage') ? (
                <Button variant="primary" onClick={() => navigate('/admin/roles/create')}>
                  New role
                </Button>
              ) : undefined
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
            onRowClick={(row) => navigate(`/admin/roles/${row.id}/permissions`)}
            getRowId={(row) => row.id}
          />
        )}
      </div>

      <ConfirmDialog
        isOpen={!!confirmDelete}
        title="Delete role?"
        description={
          confirmDelete
            ? `“${confirmDelete.name}” will be permanently removed. This action cannot be undone.`
            : ''
        }
        confirmLabel={remove.isPending ? 'Deleting…' : 'Delete'}
        variant="danger"
        onConfirm={() => {
          if (confirmDelete) remove.mutate(confirmDelete);
        }}
        onClose={() => setConfirmDelete(null)}
        pending={remove.isPending}
      />
    </div>
  );
}
