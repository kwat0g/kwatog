/** Sprint 8 — Task 69. Maintenance schedules list. */
import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { schedulesApi, type ScheduleListParams } from '@/api/maintenance/schedules';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import type { MaintenanceSchedule } from '@/types/maintenance';

export default function MaintenanceSchedulesListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const qc = useQueryClient();
  const [filters, setFilters] = useState<ScheduleListParams>({ page: 1, per_page: 25 });

  const handleDelete = async (scheduleId: string) => {
    if (!confirm('Delete this schedule? This cannot be undone.')) return;
    try {
      await schedulesApi.destroy(scheduleId);
      qc.invalidateQueries({ queryKey: ['maintenance', 'schedules'] });
      toast.success('Schedule deleted.');
    } catch {
      toast.error('Failed to delete schedule.');
    }
  };

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['maintenance', 'schedules', filters],
    queryFn: () => schedulesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<MaintenanceSchedule>[] = [
    {
      key: 'description',
      header: 'Schedule',
      cell: (r) => (
        <div>
          <div className="text-sm">{r.description}</div>
          <div className="text-xs text-muted">
            {r.maintainable
              ? <><span className="font-mono">{r.maintainable.code ?? '—'}</span> · {r.maintainable_type}</>
              : <span>{r.maintainable_type}</span>}
          </div>
        </div>
      ),
    },
    {
      key: 'interval',
      header: 'Interval',
      cell: (r) => <span className="font-mono tabular-nums">{r.interval_value} {r.interval_type}</span>,
    },
    {
      key: 'last_performed_at',
      header: 'Last performed',
      align: 'right',
      cell: (r) => <NumCell>{r.last_performed_at ? formatDate(r.last_performed_at) : '—'}</NumCell>,
    },
    {
      key: 'next_due_at',
      header: 'Next due',
      align: 'right',
      cell: (r) => <NumCell>{r.next_due_at ? formatDate(r.next_due_at) : '—'}</NumCell>,
    },
    {
      key: 'is_active',
      header: 'Active',
      cell: (r) => <Chip variant={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'Active' : 'Disabled'}</Chip>,
    },
    ...(can('maintenance.schedules.manage') ? [{
      key: 'actions' as const,
      header: '',
      align: 'right' as const,
      cell: (r: MaintenanceSchedule) => (
        <div className="flex justify-end gap-1">
          <button
            onClick={(e) => { e.stopPropagation(); navigate(`/maintenance/schedules/${r.id}/edit`); }}
            className="p-1.5 text-muted hover:text-text hover:bg-elevated rounded-sm"
            aria-label="Edit"
          >
            <Pencil size={13} />
          </button>
          <button
            onClick={(e) => { e.stopPropagation(); handleDelete(r.id); }}
            className="p-1.5 text-muted hover:text-danger hover:bg-elevated rounded-sm"
            aria-label="Delete"
          >
            <Trash2 size={13} />
          </button>
        </div>
      ),
    }] : []),
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'maintainable_type', label: 'Target', type: 'select',
      options: [{ value: '', label: 'All' }, { value: 'machine', label: 'Machine' }, { value: 'mold', label: 'Mold' }],
    },
    {
      key: 'interval_type', label: 'Interval', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'hours', label: 'Hours' },
        { value: 'days', label: 'Days' },
        { value: 'shots', label: 'Shots (mold only)' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Maintenance schedules"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'schedule' : 'schedules'}` : undefined}
        actions={
          can('maintenance.schedules.manage') ? (
            <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/maintenance/schedules/create')}>
              New schedule
            </Button>
          ) : undefined
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by description…"
      />
      {isLoading && !data && <SkeletonTable columns={5} rows={6} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load schedules"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState icon="calendar" title="No maintenance schedules"
          description="Create a preventive schedule for a machine or mold; the system materialises a WO when due."
          action={can('maintenance.schedules.manage') ? (
            <Button variant="primary" onClick={() => navigate('/maintenance/schedules/create')}>New schedule</Button>
          ) : undefined}
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onRowClick={(r) => navigate(`/maintenance/schedules/${r.id}`)} />
        </div>
      )}
    </div>
  );
}
