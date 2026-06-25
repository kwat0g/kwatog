/** Performance Reviews list page. */
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { performanceReviewsApi, reviewCyclesApi, type ReviewListParams } from '@/api/hr/performance-reviews';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { useAuthStore } from '@/stores/authStore';
import type { PerformanceReview, ReviewStatus } from '@/types/performance-reviews';

const STATUS_CHIP: Record<ReviewStatus, 'success' | 'warning' | 'info' | 'neutral'> = {
  pending: 'warning',
  in_progress: 'info',
  submitted: 'success',
  acknowledged: 'success',
};

export default function PerformanceReviewsPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const user = useAuthStore((s) => s.user);
  const [filters, setFilters] = useState<ReviewListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['performance-reviews', filters],
    queryFn: () => performanceReviewsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const { data: cyclesResp } = useQuery({
    queryKey: ['performance-cycles', { per_page: 50 }],
    queryFn: () => reviewCyclesApi.list({ per_page: 50 }),
  });
  const cycles = cyclesResp?.data ?? [];

  const acknowledgeMutation = useMutation({
    mutationFn: (id: string) => performanceReviewsApi.acknowledge(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['performance-reviews'] });
      toast.success('Review acknowledged.');
    },
    onError: () => toast.error('Failed to acknowledge review.'),
  });

  const columns: Column<PerformanceReview>[] = [
    {
      key: 'employee', header: 'Employee',
      cell: (r) => <span>{r.employee.first_name} {r.employee.last_name}</span>,
    },
    {
      key: 'reviewer', header: 'Reviewer',
      cell: (r) => <span>{r.reviewer.first_name} {r.reviewer.last_name}</span>,
    },
    {
      key: 'cycle', header: 'Cycle',
      cell: (r) => <span className="text-sm text-muted">{r.cycle.name}</span>,
    },
    {
      key: 'status', header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status.replace('_', ' ')}</Chip>,
    },
    {
      key: 'overall_score', header: 'Score', align: 'right',
      cell: (r) => r.overall_score
        ? <span className="font-mono tabular-nums">{r.overall_score}</span>
        : <span className="text-muted">--</span>,
    },
    {
      key: 'overall_rating', header: 'Rating',
      cell: (r) => r.overall_rating
        ? <Chip variant="info">{r.overall_rating}</Chip>
        : <span className="text-muted">--</span>,
    },
    {
      key: 'actions', header: '', align: 'right',
      cell: (r) => {
        const isReviewer = user?.employee?.id === r.reviewer.id;
        const isEmployee = user?.employee?.id === r.employee.id;
        return (
          <div className="flex items-center gap-1 justify-end">
            {isReviewer && (r.status === 'pending' || r.status === 'in_progress') && (
              <Button variant="primary" size="sm" onClick={() => navigate(`/hr/performance-reviews/${r.id}/submit`)}>
                Submit
              </Button>
            )}
            {isEmployee && r.status === 'submitted' && (
              <Button variant="ghost" size="sm" onClick={() => acknowledgeMutation.mutate(r.id)}
                disabled={acknowledgeMutation.isPending}>
                Acknowledge
              </Button>
            )}
          </div>
        );
      },
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'pending', label: 'Pending' },
        { value: 'in_progress', label: 'In progress' },
        { value: 'submitted', label: 'Submitted' },
        { value: 'acknowledged', label: 'Acknowledged' },
      ],
    },
    {
      key: 'cycle_id', label: 'Cycle', type: 'select',
      options: [
        { value: '', label: 'All cycles' },
        ...cycles.map((c) => ({ value: c.id, label: c.name })),
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Performance Reviews"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'review' : 'reviews'}` : undefined}
        breadcrumbs={[{ label: 'HR', href: '/hr' }, { label: 'Performance Reviews' }]}
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by employee or reviewer..."
      />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load reviews"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState icon="clipboard-list" title="No reviews"
          description="Performance reviews will appear here once a cycle is active and reviews are assigned." />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
        </div>
      )}
    </div>
  );
}
