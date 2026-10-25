import { useEffect, useState } from 'react';
import { useInfiniteQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { dssApi } from '@/api/dss';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';

export default function MasterHygieneAffectedRecords({
  recommendationId,
}: {
  recommendationId: string;
}) {
  const [query, setQuery] = useState('');
  const [search, setSearch] = useState('');
  useEffect(() => {
    const timer = window.setTimeout(() => setSearch(query.trim()), 250);
    return () => window.clearTimeout(timer);
  }, [query]);

  const records = useInfiniteQuery({
    queryKey: ['dss', 'affected-records', recommendationId, search],
    queryFn: ({ pageParam }) =>
      dssApi.affectedRecords(recommendationId, { cursor: pageParam, search }),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (page) => page.next_cursor ?? undefined,
    retry: false,
  });
  const firstPage = records.data?.pages[0];
  const rows = records.data?.pages.flatMap((page) => page.data) ?? [];

  return (
    <Panel
      title="Affected records"
      meta={
        firstPage && !firstPage.historical
          ? `${firstPage.total.toLocaleString()} current`
          : undefined
      }
      actions={
        <Button
          size="sm"
          variant="ghost"
          disabled={records.isFetching}
          onClick={() => void records.refetch()}
        >
          Refresh records
        </Button>
      }
    >
      {records.isLoading ? (
        <SkeletonTable columns={3} rows={5} />
      ) : records.isError ? (
        <div className="mt-3 flex items-center gap-2 text-sm text-secondary">
          <span>Could not load affected records.</span>
          <Button size="sm" variant="secondary" onClick={() => void records.refetch()}>
            Retry
          </Button>
        </div>
      ) : firstPage?.historical ? (
        <p className="mt-3 text-sm text-muted">
          This recommendation is historical; current records are not attached.
        </p>
      ) : (
        <>
          <p className="mb-3 text-xs text-muted">
            Live source records matching this master-data check.
          </p>
          <Input
            label="Search affected records"
            placeholder="Search code or name"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            fieldSize="sm"
          />
          {rows.length === 0 ? (
            <p className="mt-3 text-sm text-muted">
              {search
                ? 'No current records match this search.'
                : 'No current records match this check.'}
            </p>
          ) : (
            <>
              <div className="mt-3 overflow-x-auto">
                <table
                  aria-label="Affected master-data records"
                  className="w-full min-w-[28rem] text-left text-sm"
                >
                  <thead className="text-xs text-muted">
                    <tr className="border-b border-default">
                      <th className="py-2 pr-3 font-medium">Record</th>
                      <th className="py-2 pr-3 font-medium">Missing information</th>
                      <th className="py-2 text-right font-medium">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-subtle">
                    {rows.map((record, index) => (
                      <tr key={`${record.destination}:${record.reference}:${index}`}>
                        <td className="py-2 pr-3">
                          <div className="font-medium text-primary">{record.reference}</div>
                          {record.name && <div className="text-xs text-muted">{record.name}</div>}
                        </td>
                        <td className="py-2 pr-3 text-secondary">{record.requirement}</td>
                        <td className="py-2 text-right">
                          <Link className="text-link" to={record.destination}>
                            Open record
                          </Link>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {records.hasNextPage && (
                <Button
                  className="mt-3"
                  size="sm"
                  variant="secondary"
                  disabled={records.isFetchingNextPage}
                  onClick={() => void records.fetchNextPage()}
                >
                  {records.isFetchingNextPage ? 'Loading…' : 'Load more records'}
                </Button>
              )}
            </>
          )}
        </>
      )}
    </Panel>
  );
}
