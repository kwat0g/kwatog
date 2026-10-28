import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { employeeTrainingsApi } from '@/api/hr/employee-trainings';
import { PageHeader } from '@/components/layout/PageHeader';
import { DataTable } from '@/components/ui/DataTable';
import { FilterBar } from '@/components/ui/FilterBar';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryErrorState } from '@/components/ui/QueryErrorState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import type { ListParams } from '@/types';
import type { EmployeeTraining } from '@/types/hr';

type TrainingFilters = ListParams & { signal?: 'expired' | 'upcoming' };

export default function EmployeeTrainingRecordsPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useUrlFilters<TrainingFilters>({ page: 1, per_page: 25 });
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'employee-training-records', filters],
    queryFn: () => employeeTrainingsApi.listAll(filters),
    placeholderData: (previous) => previous,
  });

  const columns = [
    { key: 'employee', header: 'Employee', cell: (row: EmployeeTraining) => row.employee?.full_name ?? '—' },
    { key: 'training', header: 'Training', cell: (row: EmployeeTraining) => row.training?.name ?? '—' },
    {
      key: 'status', header: 'Status',
      cell: (row: EmployeeTraining) => <Chip variant={chipVariantForStatus(row.status)}>{row.status_label}</Chip>,
    },
    { key: 'scheduled_for', header: 'Scheduled', cell: (row: EmployeeTraining) => row.scheduled_for ? formatDate(row.scheduled_for) : '—' },
    { key: 'expires_at', header: 'Expires', cell: (row: EmployeeTraining) => row.expires_at ? formatDate(row.expires_at) : '—' },
  ];

  return (
    <div>
      <PageHeader title="Employee Training Records" subtitle={data ? `${data.meta.total} records` : undefined} />
      <FilterBar
        values={filters}
        filters={[
          { key: 'signal', label: 'Signal', type: 'select', options: [
            { value: 'expired', label: 'Expired' },
            { value: 'upcoming', label: 'Upcoming / expiring' },
          ] },
        ]}
        onFilter={(key, value) => setFilters((current) => ({ ...current, [key]: value, page: 1 }))}
        onSearch={(search) => setFilters((current) => ({ ...current, search, page: 1 }))}
        searchPlaceholder="Search employees or training programs…"
      />
      {isLoading && !data && <SkeletonTable columns={5} rows={8} />}
      {isError && <QueryErrorState subject="employee training records" onRetry={() => void refetch()} />}
      {data && data.data.length === 0 && <EmptyState icon="file-text" title="No training records found" />}
      {data && data.data.length > 0 && (
        <DataTable
          columns={columns}
          data={data.data}
          meta={data.meta}
          onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
          onRowClick={(row) => navigate(`/hr/employee-trainings/${row.id}`)}
        />
      )}
    </div>
  );
}
