import { reportMutationError } from '@/lib/formErrors';
import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuPlus, LuKeyRound, LuCoins, LuDownload, LuCalendarClock } from '@/lib/icons';
import toast from 'react-hot-toast';
import { employeesApi, type EmployeeListParams } from '@/api/hr/employees';
import { departmentsApi } from '@/api/hr/departments';
import { employeeAccountsApi } from '@/api/hr/employee-accounts';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { Avatar } from '@/components/ui/Avatar';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatInt } from '@/lib/formatNumber';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { SidePanel } from '@/components/ui/SidePanel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatusTabs } from '@/components/ui/StatusTabs';
import { Tag } from '@/components/ui/Tag';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import { employeeStatusVariant } from '@/lib/statusVariants';
import { SalaryAdjustmentsTab } from '@/pages/hr/salary-adjustments';
import type { Employee, BulkProvisionResponse } from '@/types/hr';

import { ColumnSelectorModal } from '@/components/exports/ColumnSelectorModal';
import { ScheduledExportFormModal } from '@/components/exports/ScheduledExportFormModal';
const DEFAULT_FILTERS: EmployeeListParams = {
  page: 1,
  per_page: 25,
  sort: 'employee_no',
  direction: 'desc',
  status: 'active',
};

export default function EmployeesListPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const { can } = usePermission();
  const queryClient = useQueryClient();
  const [bulkResult, setBulkResult] = useState<BulkProvisionResponse | null>(null);
  const [exportOpen, setExportOpen] = useState(false);
  const [scheduleOpen, setScheduleOpen] = useState(false);
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
  const previewId = new URLSearchParams(location.hash.slice(1)).get('preview');
  const { data: previewEmployee, isLoading: previewLoading, isError: previewError, refetch: refetchPreview } = useQuery({
    queryKey: ['hr', 'employees', 'preview', previewId],
    queryFn: () => employeesApi.show(previewId!),
    enabled: Boolean(previewId && canViewEmployees),
  });
  const openPreview = (employeeId: string) =>
    navigate({ pathname: location.pathname, search: location.search, hash: `preview=${encodeURIComponent(employeeId)}` });
  const closePreview = () =>
    navigate({ pathname: location.pathname, search: location.search, hash: '' }, { replace: true });

  // "All" is a tab sentinel. Omit it from requests while preserving the
  // existing default of Active for first visits and old bookmarks.
  const listFilters = filters.status === 'all' ? { ...filters, status: undefined } : filters;
  const exportFilters = {
    status: filters.status === 'all' ? undefined : filters.status,
    department_id: filters.department_id,
    pay_type: filters.pay_type,
  };

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
    onError: (error) => reportMutationError(error, 'Bulk provisioning failed.'),
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
    // Same gate as the list below. This page is also the salary-adjustments
    // queue, and a checker reaches it holding hr.salary_adjustments.view WITHOUT
    // hr.employees.view — so firing these unconditionally 403'd on arrival and
    // greeted them with "You do not have permission to perform this action."
    // The filter dropdowns these feed already fall back to [].
    enabled: canViewEmployees,
  });
  const statusLabel = new Map(
    (employeeOptions?.statuses ?? []).map((status) => [status.value, status.label]),
  );

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'employees', filters],
    queryFn: () => employeesApi.list(listFilters),
    placeholderData: (prev) => prev,
    enabled: canViewEmployees,
  });

  // Counts describe the matching dataset across statuses, not just this page.
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
      key: 'full_name',
      header: 'Name',
      sortable: true,
      primary: true,
      className: 'min-w-[220px]',
      cell: (row) => (
        <div className="flex min-w-0 items-center gap-2.5">
          <Avatar src={row.photo_url} name={row.full_name} identityKey={row.id} size="md" />
          <StackedCell
            primary={row.full_name}
            secondary={<span className="text-muted">{row.position?.title ?? '—'}</span>}
          />
        </div>
      ),
    },
    {
      key: 'employee_no',
      header: 'Employee no',
      sortable: true,
      cell: (row) => <span className="font-mono">{row.employee_no}</span>,
    },
    {
      key: 'department',
      header: 'Department',
      cell: (row) => row.department ? <Tag categoryKey={row.department.id}>{row.department.name}</Tag> : '—',
    },
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
        <Chip variant={employeeStatusVariant[row.status]}>
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
            options: depts.map((d) => ({ value: d.id, label: d.name })),
          },
        ]
      : []),
    {
      key: 'employment_type',
      label: 'Type',
      type: 'select',
      options: employeeOptions?.employment_types ?? [],
    },
    {
      key: 'pay_type',
      label: 'Pay type',
      type: 'select',
      options: employeeOptions?.pay_types ?? [],
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
            {/* The column-selectable export has existed end to end — endpoint,
                API client, ColumnSelectorModal — with no page offering it, while
                admin/scheduled-exports let users *schedule* an export of a module
                they could not export by hand. Current filters are passed through,
                so what you see is what you get. */}
            {view === 'employees' && can('hr.employees.export') && (
              <>
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<LuDownload size={14} />}
                  onClick={() => setExportOpen(true)}
                >
                  Export
                </Button>
                <Button
                  variant="secondary"
                  size="sm"
                  icon={<LuCalendarClock size={14} />}
                  onClick={() => setScheduleOpen(true)}
                >
                  Schedule
                </Button>
              </>
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
          <StatusTabs
            label="Employee status"
            panelId="employee-results"
            value={String(filters.status ?? 'active')}
            onChange={(status) => setFilters((current) => ({ ...current, status, page: 1 }))}
            items={[
              { value: 'all', label: 'All', count: statusCounts?.total ?? 0 },
              ...(employeeOptions?.statuses ?? []).map((status) => ({
                value: status.value,
                label: status.label,
                count: statusCounts?.counts[status.value] ?? 0,
              })),
            ]}
          />
          <FilterBar
            filters={filterConfig}
            values={filters}
            onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
            onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
            searchPlaceholder="Search name or employee no…"
            resultCount={data?.meta.total}
          />

          <div id="employee-results" role="tabpanel" aria-label="Employees by status" tabIndex={0}>
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

          {data && data.data.length === 0 && (
            <EmptyState
              size="compact"
              icon="users"
              title="No employees match these filters"
              description="Change the status or filters to see employees."
            />
          )}

          {data && data.data.length > 0 && (
            <div className="px-5 py-4">
              <DataTable
                tableKey="employees"
                onRowClick={(row) => navigate(`/hr/employees/${row.id}`)}
                rowActions={(row) => (
                  <Button
                    size="sm"
                    variant="ghost"
                    aria-label={`Preview ${row.full_name}`}
                    onClick={() => openPreview(row.id)}
                  >
                    Preview
                  </Button>
                )}
                columns={columns}
                data={data.data}
                meta={data.meta}
                onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
                onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
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
          </div>
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
      <ColumnSelectorModal
        isOpen={exportOpen}
        onClose={() => setExportOpen(false)}
        module="hr.employees"
        filters={exportFilters}
      />
      <ScheduledExportFormModal
        isOpen={scheduleOpen}
        onClose={() => setScheduleOpen(false)}
        module="hr.employees"
        filters={exportFilters}
      />
      <SidePanel
        isOpen={Boolean(previewId && canViewEmployees)}
        onClose={closePreview}
        title={previewEmployee?.full_name ?? 'Employee preview'}
      >
        {previewLoading && <p className="text-sm text-muted">Loading employee…</p>}
        {previewError && (
          <EmptyState
            size="compact"
            icon="alert-circle"
            title="Could not load employee"
            action={<Button variant="secondary" onClick={() => refetchPreview()}>Retry</Button>}
          />
        )}
        {previewEmployee && (
          <div className="space-y-5">
            <div className="flex items-center gap-3">
              <Avatar src={previewEmployee.photo_url} name={previewEmployee.full_name} identityKey={previewEmployee.id} size="lg" />
              <div className="min-w-0">
                <p className="m-0 text-label text-primary">{previewEmployee.position?.title ?? 'Position not set'}</p>
                <p className="m-0 text-caption text-muted">{previewEmployee.employee_no}</p>
                <Chip variant={employeeStatusVariant[previewEmployee.status]}>
                  {statusLabel.get(previewEmployee.status) ?? previewEmployee.status.replace('_', ' ')}
                </Chip>
              </div>
            </div>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 border-t border-default pt-4 text-caption">
              <dt className="text-muted">Department</dt><dd className="m-0 text-primary">{previewEmployee.department?.name ?? '—'}</dd>
              <dt className="text-muted">Pay type</dt><dd className="m-0 text-primary">{previewEmployee.pay_type_label ?? previewEmployee.pay_type}</dd>
              <dt className="text-muted">Employment type</dt><dd className="m-0 text-primary">{previewEmployee.employment_type_label ?? previewEmployee.employment_type}</dd>
              <dt className="text-muted">Hired</dt><dd className="m-0 text-primary">{formatDate(previewEmployee.date_hired)}</dd>
            </dl>
            <Button variant="primary" className="w-full" onClick={() => navigate(`/hr/employees/${previewEmployee.id}`)}>
              Open full employee record
            </Button>
          </div>
        )}
      </SidePanel>
    </div>
  );
}
