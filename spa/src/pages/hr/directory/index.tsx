import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LayoutGrid, List as ListIcon, Search } from 'lucide-react';
import { directoryApi } from '@/api/hr/directory';
import { Avatar } from '@/components/ui/Avatar';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { cn } from '@/lib/cn';
import type { DirectoryEmployee } from '@/types/directory';

type ViewMode = 'grid' | 'list';

export default function EmployeeDirectoryPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [search, setSearch] = useState('');
  const [view, setView] = useState<ViewMode>('grid');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'directory', search],
    queryFn: () => directoryApi.list({ search: search || undefined, per_page: 200 }),
    placeholderData: (prev) => prev,
  });

  // Group by department for grid view (visual grouping only).
  const grouped = useMemo(() => {
    const map = new Map<string, DirectoryEmployee[]>();
    for (const e of data?.data ?? []) {
      const key = e.department?.name ?? 'Unassigned';
      if (!map.has(key)) map.set(key, []);
      map.get(key)!.push(e);
    }
    return Array.from(map.entries()).sort(([a], [b]) => a.localeCompare(b));
  }, [data]);

  const canViewFull = can('hr.employees.view');

  return (
    <div>
      <PageHeader
        title="Employee directory"
        subtitle={data ? `${data.meta.total} employee${data.meta.total === 1 ? '' : 's'}` : undefined}
        actions={
          <div className="flex items-center gap-1 border border-default rounded-md p-0.5 bg-canvas">
            <button
              type="button"
              aria-label="Grid view"
              onClick={() => setView('grid')}
              className={cn(
                'p-1.5 rounded-sm',
                view === 'grid' ? 'bg-elevated text-primary' : 'text-muted hover:bg-elevated',
              )}
            >
              <LayoutGrid size={14} />
            </button>
            <button
              type="button"
              aria-label="List view"
              onClick={() => setView('list')}
              className={cn(
                'p-1.5 rounded-sm',
                view === 'list' ? 'bg-elevated text-primary' : 'text-muted hover:bg-elevated',
              )}
            >
              <ListIcon size={14} />
            </button>
          </div>
        }
      />

      {/* Search */}
      <div className="px-5 py-3 border-b border-default">
        <div className="relative max-w-md">
          <Search
            size={14}
            className="absolute left-2.5 top-1/2 -translate-y-1/2 text-muted pointer-events-none"
          />
          <Input
            placeholder="Search by name, position, employee no…"
            value={search}
            onChange={(e: { target: { value: string } }) => setSearch(e.target.value)}
            className="pl-7"
          />
        </div>
      </div>

      {isLoading && !data && (
        <div className="px-5 py-4 grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-3">
          {Array.from({ length: 8 }).map((_, i) => (
            <div key={i} className="h-32 bg-elevated rounded-md animate-pulse" />
          ))}
        </div>
      )}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load directory"
          description="Something went wrong."
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="users"
          title={search ? `No matches for “${search}”` : 'No employees yet'}
          description={search ? 'Try a different name or department.' : 'Once HR adds employees, they appear here.'}
        />
      )}

      {data && data.data.length > 0 && view === 'grid' && (
        <div className="px-5 py-4 space-y-6">
          {grouped.map(([deptName, employees]) => (
            <section key={deptName}>
              <h2 className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
                {deptName} <span className="text-subtle font-mono tabular-nums">({employees.length})</span>
              </h2>
              <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                {employees.map((e) => (
                  <button
                    key={e.id}
                    type="button"
                    onClick={() => canViewFull && navigate(`/hr/employees/${e.id}`)}
                    className={cn(
                      'bg-canvas border border-default rounded-md p-3 text-left transition-colors',
                      canViewFull ? 'hover:bg-elevated cursor-pointer' : 'cursor-default',
                    )}
                  >
                    <div className="flex items-start gap-3">
                      <Avatar name={e.full_name} src={e.photo_path ?? undefined} size="lg" />
                      <div className="flex-1 min-w-0">
                        <div className="font-medium text-sm truncate">{e.full_name}</div>
                        <div className="text-xs text-muted truncate">{e.position?.title ?? '—'}</div>
                        <div className="mt-1.5 flex items-center gap-1.5">
                          {e.status && (
                            <Chip variant={chipVariantForStatus(e.status)}>
                              {e.status.replace('_', ' ')}
                            </Chip>
                          )}
                          <span className="text-2xs font-mono tabular-nums text-muted">{e.employee_no}</span>
                        </div>
                        {e.email && (
                          <div className="text-2xs text-muted mt-1 truncate">{e.email}</div>
                        )}
                      </div>
                    </div>
                  </button>
                ))}
              </div>
            </section>
          ))}
        </div>
      )}

      {data && data.data.length > 0 && view === 'list' && (
        <div className="px-5 py-4">
          <div className="border border-default rounded-md overflow-hidden">
            <table className="w-full border-collapse text-xs">
              <thead className="bg-subtle">
                <tr>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Name</th>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Position</th>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Department</th>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Email</th>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Mobile</th>
                  <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Status</th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((e) => (
                  <tr
                    key={e.id}
                    onClick={() => canViewFull && navigate(`/hr/employees/${e.id}`)}
                    className={cn(
                      'h-8 border-t border-subtle',
                      canViewFull ? 'hover:bg-subtle cursor-pointer' : '',
                    )}
                  >
                    <td className="px-2.5">
                      <div className="font-medium">{e.full_name}</div>
                      <div className="text-2xs text-muted font-mono">{e.employee_no}</div>
                    </td>
                    <td className="px-2.5 text-secondary">{e.position?.title ?? '—'}</td>
                    <td className="px-2.5 text-secondary">{e.department?.name ?? '—'}</td>
                    <td className="px-2.5 font-mono">{e.email ?? '—'}</td>
                    <td className="px-2.5 font-mono tabular-nums">{e.mobile_number ?? '—'}</td>
                    <td className="px-2.5">
                      {e.status && (
                        <Chip variant={chipVariantForStatus(e.status)}>
                          {e.status.replace('_', ' ')}
                        </Chip>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
