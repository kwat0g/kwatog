import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { employeesApi, type EmployeeListParams } from '@/api/hr/employees';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import type { Employee } from '@/types/hr';

export default function EmployeesListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();

  const [filters, setFilters] = useState<EmployeeListParams>({
    page: 1, per_page: 25, sort: 'employee_no', direction: 'desc',
  });

  const { data: depts = [] } = useQuery({
    queryKey: ['hr', 'departments', 'tree'],
    queryFn: () => departmentsApi.tree(),
  });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'employees', filters],
    queryFn: () => employeesApi.list(filters),
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
      cell: (row) => row.pay_type === 'monthly' ? 'Monthly' : 'Daily',
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
          {row.status.replace('_', ' ')}
        </Chip>
      ),
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'department_id',
      label: 'Department',
      type: 'select',
      options: [{ value: '', label: 'All departments' }, ...depts.map((d) => ({ value: d.id, label: d.name }))],
    },
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: '', label: 'All statuses' },
        { value: 'active', label: 'Active' },
        { value: 'on_leave', label: 'On leave' },
        { value: 'suspended', label: 'Suspended' },
        { value: 'resigned', label: 'Resigned' },
        { value: 'terminated', label: 'Terminated' },
        { value: 'retired', label: 'Retired' },
      ],
    },
    {
      key: 'employment_type',
      label: 'Type',
      type: 'select',
      options: [
        { value: '', label: 'All types' },
        { value: 'regular', label: 'Regular' },
        { value: 'probationary', label: 'Probationary' },
        { value: 'contractual', label: 'Contractual' },
        { value: 'project_based', label: 'Project-based' },
      ],
    },
    {
      key: 'pay_type',
      label: 'Pay type',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'monthly', label: 'Monthly' },
        { value: 'daily', label: 'Daily' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Employees"
        subtitle={data ? `${data.meta.total.toLocaleString()} employees` : undefined}
        actions={
          can('hr.employees.create') && (
            <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/hr/employees/create')}>
              Add employee
            </Button>
          )
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by name or employee no…"
      />

      {isLoading && !data && <SkeletonTable columns={6} rows={10} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load employees"
          description="Something went wrong. Please try again."
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="users"
          title="No employees found"
          description={filters.search ? `No matches for "${filters.search}".` : 'Add your first employee to get started.'}
          action={can('hr.employees.create') ? (
            <Button variant="primary" onClick={() => navigate('/hr/employees/create')}>Add employee</Button>
          ) : undefined}
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
          onRowClick={(row) => navigate(`/hr/employees/${row.id}`)}
        />
      )}
    </div>
  );
}
