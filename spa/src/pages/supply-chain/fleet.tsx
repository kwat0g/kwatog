/** Sprint 7 — Task 67 — Fleet vehicle list. */
import { useQuery } from '@tanstack/react-query';
import { vehiclesApi } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { Vehicle } from '@/types/supplyChain';

const STATUS_CHIP: Record<string, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  available: 'success',
  in_use: 'info',
  maintenance: 'warning',
  retired: 'neutral',
};

export default function FleetPage() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['supply-chain', 'vehicles'],
    queryFn: () => vehiclesApi.list({ per_page: 100 }),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Vehicle>[] = [
    { key: 'plate', header: 'Plate',
      cell: (r) => <span className="font-mono">{r.plate_number}</span> },
    { key: 'name', header: 'Name', cell: (r) => r.name },
    { key: 'type', header: 'Type', cell: (r) => <Chip variant="neutral">{r.vehicle_type}</Chip> },
    { key: 'capacity', header: 'Capacity (kg)', align: 'right',
      cell: (r) => <NumCell>{r.capacity_kg ?? '—'}</NumCell> },
    { key: 'status', header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status] ?? 'neutral'}>{r.status.replace('_', ' ')}</Chip> },
  ];

  return (
    <div>
      <PageHeader title="Fleet" subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'vehicle' : 'vehicles'}` : undefined} />
      {isLoading && !data && <SkeletonTable columns={5} rows={5} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load fleet"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState icon="truck" title="No vehicles" description="Vehicles seeded by VehicleSeeder will appear here." />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta} />
        </div>
      )}
    </div>
  );
}
