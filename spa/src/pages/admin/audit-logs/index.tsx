import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { auditLogsApi, type AuditLogEntry, type AuditLogParams } from '@/api/admin/audit-logs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime } from '@/lib/formatDate';

const actionVariant = {
  created: 'success',
  updated: 'info',
  deleted: 'danger',
} as const;

const columns: Column<AuditLogEntry>[] = [
  {
    key: 'created_at',
    header: 'When',
    cell: (row) => <NumCell>{formatDateTime(row.created_at)}</NumCell>,
    align: 'left',
  },
  {
    key: 'action',
    header: 'Action',
    cell: (row) => <Chip variant={actionVariant[row.action] ?? 'neutral'}>{row.action}</Chip>,
  },
  {
    key: 'model',
    header: 'Record',
    cell: (row) => (
      <StackedCell
        primary={row.model_type}
        secondary={row.model_id ? <span className="font-mono">#{row.model_id}</span> : null}
      />
    ),
  },
  {
    key: 'user',
    header: 'By',
    cell: (row) =>
      row.user ? (
        <StackedCell primary={row.user.name} secondary={<span className="text-muted">{row.user.email}</span>} />
      ) : (
        <span className="text-muted">System</span>
      ),
  },
  {
    key: 'ip',
    header: 'IP',
    cell: (row) => <NumCell className="text-muted">{row.ip_address ?? '—'}</NumCell>,
  },
];

export default function AuditLogsPage() {
  const [filters, setFilters] = useState<AuditLogParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'audit-logs', filters],
    queryFn: () => auditLogsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  return (
    <div>
      <PageHeader
        title="Audit logs"
        subtitle={data ? `${data.meta.total.toLocaleString()} entries` : undefined}
      />

      <FilterBar
        values={filters}
        onSearch={(model_type) => setFilters((f) => ({ ...f, model_type, page: 1 }))}
        searchPlaceholder="Filter by record type…"
        filters={[
          {
            key: 'action',
            label: 'Action',
            type: 'select',
            options: [
              { value: 'created', label: 'Created' },
              { value: 'updated', label: 'Updated' },
              { value: 'deleted', label: 'Deleted' },
            ],
          },
        ]}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
      />

      <div className="px-5 py-4">
        {isLoading && !data && <SkeletonTable columns={5} rows={10} />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load audit logs"
            action={
              <Button variant="secondary" onClick={() => window.location.reload()}>
                Retry
              </Button>
            }
          />
        )}

        {data && data.data.length === 0 && (
          <EmptyState
            icon="file-question"
            title="No audit log entries"
            description="Activity will appear here as users create, update, and delete records."
          />
        )}

        {data && data.data.length > 0 && (
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            getRowId={(row) => String(row.id)}
          />
        )}
      </div>
    </div>
  );
}
