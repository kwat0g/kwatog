import { useDeferredValue, useState } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LuPlus, LuSearch } from '@/lib/icons';
import { returnCasesApi } from '@/api/returnCases';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { formatDateTime } from '@/lib/formatDate';
import { usePermission } from '@/hooks/usePermission';
import type { ReturnCaseStatus } from '@/types/returnCases';
import { casePath, caseStatusVariant, newCasePath, realmFromPath } from './shared';

const STATUS_OPTIONS: Array<{ value: ReturnCaseStatus | ''; label: string }> = [
  { value: '', label: 'All statuses' },
  { value: 'submitted', label: 'Submitted' },
  { value: 'under_review', label: 'Under review' },
  { value: 'information_needed', label: 'Information needed' },
  { value: 'action_agreed', label: 'Action agreed' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'withdrawn', label: 'Withdrawn' },
];

export default function ReturnCaseListPage() {
  const location = useLocation();
  const navigate = useNavigate();
  const realm = realmFromPath(location.pathname);
  const { can } = usePermission();
  const [searchParams] = useSearchParams();
  const [search, setSearch] = useState(() => searchParams.get('search') ?? '');
  const [status, setStatus] = useState<ReturnCaseStatus | ''>('');
  const [page, setPage] = useState(1);
  const deferredSearch = useDeferredValue(search);
  const canCreate = realm === 'customer' || (realm === 'internal' && can('return_management.manage'));

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['return-cases', realm, page, deferredSearch, status],
    queryFn: () => returnCasesApi.list(realm, {
      page,
      per_page: 20,
      search: deferredSearch.trim() || undefined,
      status: status || undefined,
    }),
    placeholderData: (previous) => previous,
  });

  const title = realm === 'internal' ? 'Problem reports' : 'Problem reports';
  const emptyAction = canCreate ? (
    <Button variant="primary" icon={<LuPlus size={14} />} onClick={() => navigate(newCasePath(realm))}>
      Report a problem
    </Button>
  ) : undefined;

  return (
    <div>
      <PageHeader
        title={title}
        subtitle={data ? `${data.meta.total} reports` : 'Track quantity problems and agreed actions'}
        actions={canCreate ? (
          <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => navigate(newCasePath(realm))}>
            Report a problem
          </Button>
        ) : undefined}
      />

      <div className="px-5 py-4 space-y-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_220px]">
          <Input
            aria-label="Search problem reports"
            value={search}
            onChange={(event) => { setSearch(event.target.value); setPage(1); }}
            placeholder="Search report number, source, or party…"
            prefix={<LuSearch size={15} />}
          />
          <label className="flex flex-col gap-1 text-xs text-muted font-medium">
            Status
            <select
              value={status}
              onChange={(event) => { setStatus(event.target.value as ReturnCaseStatus | ''); setPage(1); }}
              className="h-8 rounded-md border border-default bg-canvas px-2 text-sm text-primary focus:outline-none focus:ring-[3px] focus:ring-accent/20 focus:border-accent"
            >
              {STATUS_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
          </label>
        </div>

        {isLoading && !data && <SkeletonTable columns={5} rows={7} />}
        {isError && !data && (
          <EmptyState
            icon="alert-circle"
            title="Could not load problem reports"
            description="Your reports are still saved. Check your connection and try again."
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}
        {data && data.data.length === 0 && (
          <EmptyState
            icon="inbox"
            title={deferredSearch || status ? 'No matching reports' : 'No problem reports yet'}
            description={deferredSearch || status ? 'Change the search or status filter.' : 'Reports keep the source documents, quantities, and follow-up together.'}
            action={emptyAction}
          />
        )}
        {data && data.data.length > 0 && (
          <div className="overflow-hidden rounded-md border border-default bg-canvas">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] border-collapse text-left text-sm">
                <thead className="bg-subtle text-xs text-muted">
                  <tr>
                    <th className="px-4 py-2.5 font-medium">Report</th>
                    <th className="px-4 py-2.5 font-medium">Party</th>
                    <th className="px-4 py-2.5 font-medium">Source</th>
                    <th className="px-4 py-2.5 font-medium">Status</th>
                    <th className="px-4 py-2.5 font-medium">Submitted</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-subtle">
                  {data.data.map((item) => (
                    <tr key={item.id} className="hover:bg-elevated/60 transition-colors">
                      <td className="px-4 py-3">
                        <Link to={casePath(realm, item.id)} className="font-mono text-link hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent rounded-sm">
                          {item.case_number}
                        </Link>
                        <div className="mt-0.5 max-w-[28rem] truncate text-xs text-muted">{item.description}</div>
                      </td>
                      <td className="px-4 py-3 text-secondary">{item.party?.name ?? '—'}</td>
                      <td className="px-4 py-3 text-secondary">{item.source?.label ?? '—'}</td>
                      <td className="px-4 py-3"><Chip variant={caseStatusVariant(item.status)}>{item.status_label}</Chip></td>
                      <td className="px-4 py-3 whitespace-nowrap font-mono text-xs tabular-nums text-muted">{item.created_at ? formatDateTime(item.created_at) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {data.meta.last_page > 1 && (
              <div className="flex items-center justify-between border-t border-default px-4 py-2.5 text-xs text-muted">
                <span>Page {data.meta.current_page} of {data.meta.last_page}</span>
                <div className="flex gap-2">
                  <Button size="xs" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}>Previous</Button>
                  <Button size="xs" disabled={page >= data.meta.last_page} onClick={() => setPage((value) => Math.min(data.meta.last_page, value + 1))}>Next</Button>
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
