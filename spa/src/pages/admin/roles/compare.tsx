import { useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftRight } from 'lucide-react';
import { rolesApi, type RoleCompareResult, type RolePermissionRow } from '@/api/admin/roles';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { PageHeader } from '@/components/layout/PageHeader';

/**
 * ADV4 — Side-by-side permission diff between two roles.
 *
 * Two role pickers (URL-driven via `?a=&b=`), three lanes: Only-A / Common /
 * Only-B, plus a swap button to invert the comparison and a stat strip.
 * Useful before cloning a role or auditing access drift between similar
 * roles (e.g. "Day Supervisor" vs. "Night Supervisor").
 */
export default function CompareRolesPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const a = searchParams.get('a') ?? '';
  const b = searchParams.get('b') ?? '';

  const roleList = useQuery({
    queryKey: ['admin', 'roles', 'all-for-compare'],
    queryFn: () => rolesApi.list({ per_page: 100, sort: 'name', direction: 'asc' }),
    staleTime: 60_000,
  });

  const compare = useQuery<RoleCompareResult>({
    queryKey: ['admin', 'roles', 'compare', a, b],
    queryFn: () => rolesApi.compare(a, b),
    enabled: !!a && !!b && a !== b,
  });

  const setA = (next: string) => setSearchParams({ a: next, b }, { replace: true });
  const setB = (next: string) => setSearchParams({ a, b: next }, { replace: true });
  const swap = () => setSearchParams({ a: b, b: a }, { replace: true });

  const grouped = useMemo(() => {
    const data = compare.data;
    if (!data) return null;
    const groupByModule = (rows: RolePermissionRow[]): Record<string, RolePermissionRow[]> =>
      rows.reduce<Record<string, RolePermissionRow[]>>((acc, r) => {
        (acc[r.module] ||= []).push(r);
        return acc;
      }, {});
    return {
      only_a: groupByModule(data.only_in_a),
      common: groupByModule(data.common),
      only_b: groupByModule(data.only_in_b),
    };
  }, [compare.data]);

  const differentCount = compare.data
    ? compare.data.only_in_a.length + compare.data.only_in_b.length
    : 0;

  return (
    <div>
      <PageHeader
        title="Compare roles"
        subtitle="Side-by-side permission diff. Useful before cloning a role or auditing access drift."
        backTo="/admin/users-roles"
        backLabel="Users & Roles"
        breadcrumbs={[
          { label: 'Admin', href: '/admin' },
          { label: 'Users & Roles', href: '/admin/users-roles' },
          { label: 'Roles', href: '/admin/roles' },
          { label: 'Compare' },
        ]}
      />

      <div className="px-5 py-4">
        {/* Pickers */}
        <Panel title="Roles to compare" className="mb-4">
          <div className="grid grid-cols-1 md:grid-cols-[1fr_auto_1fr] gap-3 items-end">
            <Select
              label="Role A"
              value={a}
              onChange={(e: { target: { value: string } }) => setA(e.target.value)}
            >
              <option value="">Select a role…</option>
              {(roleList.data?.data ?? []).map((r) => (
                <option key={r.id} value={r.id} disabled={r.id === b}>
                  {r.name} {r.is_system ? '(System)' : ''}
                </option>
              ))}
            </Select>
            <Button
              variant="secondary"
              size="sm"
              icon={<ArrowLeftRight size={14} />}
              onClick={swap}
              disabled={!a || !b}
              aria-label="Swap A and B"
            >
              Swap
            </Button>
            <Select
              label="Role B"
              value={b}
              onChange={(e: { target: { value: string } }) => setB(e.target.value)}
            >
              <option value="">Select a role…</option>
              {(roleList.data?.data ?? []).map((r) => (
                <option key={r.id} value={r.id} disabled={r.id === a}>
                  {r.name} {r.is_system ? '(System)' : ''}
                </option>
              ))}
            </Select>
          </div>
        </Panel>

        {!a || !b ? (
          <EmptyState
            icon="inbox"
            title="Pick two roles to compare"
            description="Select any two roles above to see which permissions they share and where they differ."
          />
        ) : a === b ? (
          <EmptyState
            icon="alert-circle"
            title="Pick two different roles"
            description="You selected the same role for both sides."
          />
        ) : compare.isLoading ? (
          <div className="flex items-center justify-center py-10 text-muted">
            <Spinner /> <span className="ml-2 text-sm">Loading comparison…</span>
          </div>
        ) : compare.isError ? (
          <EmptyState
            icon="alert-circle"
            title="Failed to load comparison"
            action={
              <Button variant="secondary" onClick={() => compare.refetch()}>
                Retry
              </Button>
            }
          />
        ) : compare.data ? (
          <>
            {/* Stat strip */}
            <Panel className="mb-4">
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <Stat
                  label={`${compare.data.role_a.name} permissions`}
                  value={compare.data.role_a.permissions_count}
                />
                <Stat
                  label={`${compare.data.role_b.name} permissions`}
                  value={compare.data.role_b.permissions_count}
                />
                <Stat label="Common" value={compare.data.common.length} tone={compare.data.common.length > 0 ? 'success' : 'neutral'} />
                <Stat
                  label="Different"
                  value={differentCount}
                  tone={differentCount > 0 ? 'warning' : 'neutral'}
                />
              </div>
            </Panel>

            {/* Three columns */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <DiffColumn
                title={`Only in ${compare.data.role_a.name}`}
                tone="warning"
                empty="Nothing exclusive to this role."
                grouped={grouped?.only_a ?? {}}
              />
              <DiffColumn
                title="Common to both"
                tone="neutral"
                empty="No shared permissions."
                grouped={grouped?.common ?? {}}
              />
              <DiffColumn
                title={`Only in ${compare.data.role_b.name}`}
                tone="info"
                empty="Nothing exclusive to this role."
                grouped={grouped?.only_b ?? {}}
              />
            </div>


          </>
        ) : null}
      </div>
    </div>
  );
}

function Stat({
  label,
  value,
  tone = 'neutral',
}: {
  label: string;
  value: number;
  tone?: 'success' | 'warning' | 'neutral';
}) {
  return (
    <div>
      <div className="text-2xs uppercase tracking-wider text-muted font-medium">{label}</div>
      <div className="flex items-center gap-2 mt-0.5">
        <span className="text-lg font-semibold tabular-nums">{value}</span>
        {tone !== 'neutral' && (
          <Chip variant={tone}>{tone === 'success' ? 'common' : 'different'}</Chip>
        )}
      </div>
    </div>
  );
}

function DiffColumn({
  title,
  tone,
  empty,
  grouped,
}: {
  title: string;
  tone: 'warning' | 'info' | 'neutral';
  empty: string;
  grouped: Record<string, RolePermissionRow[]>;
}) {
  const totalRows = Object.values(grouped).reduce((sum, r) => sum + r.length, 0);
  return (
    <Panel
      title={
        <span className="flex items-center gap-2">
          <span>{title}</span>
          <Chip variant={tone}>{totalRows}</Chip>
        </span>
      }
    >
      {totalRows === 0 ? (
        <p className="text-sm text-muted py-3">{empty}</p>
      ) : (
        <div className="flex flex-col gap-3">
          {Object.entries(grouped).map(([module, rows]) => (
            <div key={module} className="border border-default rounded-md">
              <div className="px-3 py-1.5 text-2xs uppercase tracking-wider text-muted font-medium border-b border-default">
                {module} · {rows.length}
              </div>
              <ul className="divide-y divide-subtle">
                {rows.map((p) => (
                  <li key={p.slug} className="px-3 py-1.5">
                    <div className="text-xs">{p.name}</div>
                    <div className="text-2xs font-mono text-muted">{p.slug}</div>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}
    </Panel>
  );
}
