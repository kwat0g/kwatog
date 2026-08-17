import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuPlus, LuKeyRound, LuCoins } from '@/lib/icons';
import toast from 'react-hot-toast';
import { employeesApi, type EmployeeListParams } from '@/api/hr/employees';
import { departmentsApi } from '@/api/hr/departments';
import { employeeAccountsApi } from '@/api/hr/employee-accounts';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatInt } from '@/lib/formatNumber';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import { SalaryAdjustmentsTab } from '@/pages/hr/salary-adjustments';
import type { Employee, BulkProvisionResponse } from '@/types/hr';

import { ListEmptyState } from '@/components/ui/ListEmptyState';
const DEFAULT_FILTERS: EmployeeListParams = {
  page: 1,
  per_page: 25,
  sort: 'employee_no',
  direction: 'desc',
  status: 'active',
};

/**
 * The four headcount tiles. `polarity` is declarative: StatCard only colours a
 * `delta`, and these show none today, but resignations and terminations rising
 * is bad news and the tile should never render that in success green if a trend
 * is added later.
 */
const STATUS_TILES: Array<{
  status: string;
  label: string;
  helper: string;
  polarity?: 'higher-is-better' | 'lower-is-better';
  className?: string;
}> = [
  {
    status: 'active',
    label: 'Active',
    helper: 'all matching employees',
    className: 'border-success/30 bg-success-bg/20',
  },
  {
    status: 'on_leave',
    label: 'On Leave',
    helper: 'all matching employees',
    className: 'border-warning/30 bg-warning-bg/20',
  },
  {
    status: 'resigned',
    label: 'Resigned',
    helper: 'all matching employees',
    polarity: 'lower-is-better',
  },
  {
    status: 'terminated',
    label: 'Terminated',
    helper: 'all matching employees',
    polarity: 'lower-is-better',
    className: 'border-danger/30 bg-danger-bg/20',
  },
];

export default function EmployeesListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const queryClient = useQueryClient();
  const [bulkResult, setBulkResult] = useState<BulkProvisionResponse | null>(null);
  const canViewDepartments = can('hr.departments.view');
  const canViewEmployees = can('hr.employees.view');
  const canViewAdjustments = can('hr.salary_adjustments.view');
  // Scope cut: salary adjustments folded into this page. A checker like
  // production_manager has hr.salary_adjustments.view WITHOUT hr.employees.view,
  // so the adjustments queue must be their landing view, not the employee list.
  const [view, setView] = useState<'employees' | 'adjustments'>(
    canViewEmployees ? 'employees' : 'adjustments',
  );

  // Bound to the URL so dashboard drill-downs (?status=active, ?status=on_leave)
  // arrive pre-filtered and the browser back button restores the previous view.
  const [filters, setFilters] = useUrlFilters<EmployeeListParams>(DEFAULT_FILTERS);

  // U1 — bulk system-account provisioning.
  const bulkProvision = useMutation({
    mutationFn: (rows: Employee[]) =>
      employeeAccountsApi.bulkProvision(
        rows.map((r) => r.id),
        true,
      ),
    onSuccess: (r) => {
      const { summary } = r;
      const msg =
        `Created ${summary.success}/${summary.total} accounts` +
        (summary.skipped ? ` · ${summary.skipped} skipped` : '') +
        (summary.failed ? ` · ${summary.failed} failed` : '');
      if (summary.failed > 0) {
        toast.error(msg);
      } else {
        toast.success(msg);
      }
      setBulkResult(r);
      queryClient.invalidateQueries({ queryKey: ['hr', 'employees'] });
    },
    onError: () => toast.error('Bulk provisioning failed.'),
  });

  const { data: depts = [] } = useQuery({
    queryKey: ['hr', 'departments', 'tree'],
    queryFn: () => departmentsApi.tree(),
    enabled: canViewDepartments,
  });
  const { data: employeeOptions } = useQuery({
    queryKey: ['hr', 'employee-options'],
    queryFn: employeesApi.options,
    staleTime: 5 * 60 * 1000,
  });
  const statusLabel = new Map(
    (employeeOptions?.statuses ?? []).map((status) => [status.value, status.label]),
  );

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'employees', filters],
    queryFn: () => employeesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  // KPI tiles are server-side aggregates over the WHOLE filtered set. Counting
  // `data.data` client-side counted the current 25-row page, so an HR officer
  // at 200+ headcount read "Active 18" — and clicking the tile re-filtered the
  // entire dataset, so the number changed on arrival. `status` is deliberately
  // excluded from the key and the request: these tiles navigate TO a status, so
  // they must keep describing every status once one is selected.
  const countFilters: Omit<EmployeeListParams, 'status'> = {
    search: filters.search,
    department_id: filters.department_id,
    position_id: filters.position_id,
    employment_type: filters.employment_type,
    pay_type: filters.pay_type,
  };
  const { data: statusCounts } = useQuery({
    queryKey: ['hr', 'employees', 'status-counts', countFilters],
    queryFn: () => employeesApi.statusCounts(countFilters),
    enabled: canViewEmployees && view === 'employees',
    placeholderData: (prev) => prev,
  });

  const columns: Column<Employee>[] = [
    {
      key: 'employee_no',
      header: 'Employee no',
      sortable: true,
      cell: (row) => <span className="font-mono">{row.employee_no}</span>,
    },
    {
      key: 'full_name',
      header: 'Name',
      sortable: true,
      cell: (row) => (
        <StackedCell
          primary={row.full_name}
          secondary={<span className="text-muted">{row.position?.title ?? '—'}</span>}
        />
      ),
    },
    { key: 'department', header: 'Department', cell: (row) => row.department?.name ?? '—' },
    {
      key: 'pay_type',
      header: 'Pay type',
      cell: (row) => row.pay_type_label ?? row.pay_type,
    },
    {
      key: 'date_hired',
      header: 'Hired',
      sortable: true,
      align: 'left',
      cell: (row) => <NumCell>{formatDate(row.date_hired)}</NumCell>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (row) => (
        <Chip variant={chipVariantForStatus(row.status)}>
          {statusLabel.get(row.status) ?? row.status.replace('_', ' ')}
        </Chip>
      ),
    },
  ];

  const filterConfig: FilterConfig[] = [
    ...(canViewDepartments
      ? [
          {
            key: 'department_id',
            label: 'Department',
            type: 'select' as const,
            options: [
              { value: '', label: 'All departments' },
              ...depts.map((d) => ({ value: d.id, label: d.name })),
            ],
          },
        ]
      : []),
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [{ value: '', label: 'All statuses' }, ...(employeeOptions?.statuses ?? [])],
    },
    {
      key: 'employment_type',
      label: 'Type',
      type: 'select',
      options: [{ value: '', label: 'All types' }, ...(employeeOptions?.employment_types ?? [])],
    },
    {
      key: 'pay_type',
      label: 'Pay type',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...(employeeOptions?.pay_types ?? [])],
    },
  ];

  return (
    <div>
      <PageHeader
        title={view === 'adjustments' ? 'Salary Adjustments' : 'Employees'}
        subtitle={
          view === 'adjustments'
            ? 'Maker-checker queue · pending changes await approval'
            : data
              ? `${formatInt(data.meta.total)} employees`
              : undefined
        }
        actions={
          <>
            {canViewEmployees && canViewAdjustments && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuCoins size={14} />}
                onClick={() => setView(view === 'employees' ? 'adjustments' : 'employees')}
              >
                {view === 'employees' ? 'Salary Adjustments' : 'Employee List'}
              </Button>
            )}
            {view === 'employees' && can('hr.employees.create') && (
              <Button
                variant="primary"
                size="sm"
                icon={<LuPlus size={14} />}
                onClick={() => navigate('/hr/employees/create')}
              >
                Add employee
              </Button>
            )}
          </>
        }
      />

      {view === 'adjustments' ? (
        <SalaryAdjustmentsTab />
      ) : (
        <>
          <FilterBar
            filters={filterConfig}
            values={filters}
            onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
            onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
            searchPlaceholder="Search name or employee no…"
          />

          {isLoading && !data && <SkeletonTable columns={6} rows={10} />}

          {isError && (
            <EmptyState
              icon="alert-circle"
              title="Failed to load employees"
              description="Something went wrong. Please try again."
              action={
                <Button variant="secondary" onClick={() => refetch()}>
                  Retry
                </Button>
              }
            />
          )}

          {data && (
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 px-5 py-4 border-b border-default bg-canvas">
              {STATUS_TILES.map((tile) => (
                <StatCard
                  key={tile.status}
                  label={tile.label}
                  value={statusCounts ? formatInt(statusCounts.counts[tile.status] ?? 0) : '—'}
                  helper={statusCounts ? tile.helper : 'Counting…'}
                  linkTo={`?status=${tile.status}`}
                  polarity={tile.polarity}
                  className={tile.className}
                />
              ))}
            </div>
          )}

          {data && data.data.length === 0 && (
            <ListEmptyState searchTerm={filters.search as string | undefined} />
          )}

          {data && data.data.length > 0 && (
            <div className="px-5 py-4">
              <DataTable
                tableKey="employees"
                onRowClick={(row) => navigate(`/hr/employees/${row.id}`)}
                columns={columns}
                data={data.data}
                meta={data.meta}
                onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
                onSort={(sort, direction) =>
                  setFilters((f) => ({ ...f, sort, direction, page: 1 }))
                }
                currentSort={filters.sort}
                currentDirection={filters.direction}
                selectable={can('hr.employees.provision_account')}
                bulkActions={
                  can('hr.employees.provision_account')
                    ? [
                        {
                          label: bulkProvision.isPending
                            ? 'Provisioning…'
                            : 'Create system accounts',
                          icon: <LuKeyRound size={12} />,
                          variant: 'primary',
                          onClick: (rows) => bulkProvision.mutate(rows),
                        },
                      ]
                    : undefined
                }
              />
            </div>
          )}
        </>
      )}

      {/* Bulk-provision result modal */}
      <Modal
        isOpen={!!bulkResult}
        onClose={() => setBulkResult(null)}
        title="Bulk provisioning results"
        size="md"
      >
        {bulkResult && (
          <div className="space-y-3 text-sm">
            <div className="text-muted">
              Total:{' '}
              <span className="font-mono tabular-nums text-primary">
                {bulkResult.summary.total}
              </span>{' '}
              · <span className="text-success-fg">{bulkResult.summary.success} success</span> ·{' '}
              <span className="text-warning-fg">{bulkResult.summary.skipped} skipped</span> ·{' '}
              <span className="text-danger-fg">{bulkResult.summary.failed} failed</span>
            </div>
            <ul className="border border-default rounded-md max-h-64 overflow-auto divide-y divide-subtle">
              {bulkResult.results.map((row) => (
                <li
                  key={row.employee_id}
                  className="flex items-center justify-between px-3 py-2 text-xs"
                >
                  <span className="font-mono tabular-nums text-secondary">{row.employee_id}</span>
                  <Chip
                    variant={
                      row.status === 'success'
                        ? 'success'
                        : row.status === 'skipped'
                          ? 'warning'
                          : 'danger'
                    }
                  >
                    {row.status}
                  </Chip>
                  <span className="text-muted truncate ml-2 flex-1 text-right">{row.message}</span>
                </li>
              ))}
            </ul>
            <ModalFooter>
              <Button variant="primary" onClick={() => setBulkResult(null)}>
                Done
              </Button>
            </ModalFooter>
          </div>
        )}
      </Modal>
    </div>
  );
}
