import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { permissionsApi } from '@/api/admin/permissions';
import { rolesApi } from '@/api/admin/roles';
import { Input } from '@/components/ui/Input';
import { Chip } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';

export default function PermissionSearchPage() {
  const [search, setSearch] = useState('');

  const matrix = useQuery({
    queryKey: ['admin', 'permissions', 'matrix'],
    queryFn: permissionsApi.matrix,
  });

  const roles = useQuery({
    queryKey: ['admin', 'roles'],
    queryFn: () => rolesApi.list(),
  });

  // Flatten all permissions for search
  const allPermissions = useMemo(() => {
    if (!matrix.data) return [];
    return Object.entries(matrix.data).flatMap(([module, perms]) =>
      perms.map(p => ({ ...p, module }))
    );
  }, [matrix.data]);

  // Filter by search
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return allPermissions.slice(0, 50); // show first 50 when no search
    return allPermissions.filter(
      p => p.slug.toLowerCase().includes(q) || p.name.toLowerCase().includes(q)
    );
  }, [allPermissions, search]);

  // For each filtered permission, find which roles have it
  const rolesWithPermission = useMemo(() => {
    if (!roles.data) return new Map<string, string[]>();
    const map = new Map<string, string[]>();
    for (const perm of filtered) {
      const rolesList = roles.data.data
        .filter((r) => r.permissions?.some((rp) => rp.slug === perm.slug))
        .map((r) => r.name);
      map.set(perm.slug, rolesList);
    }
    return map;
  }, [filtered, roles.data]);

  const isLoading = matrix.isLoading || roles.isLoading;
  const isError = matrix.isError || roles.isError;
  const refetchAll = () => { matrix.refetch(); roles.refetch(); };

  return (
    <div>
      <PageHeader
        title="Permission Search"
        subtitle="Find which roles have a specific permission"
        breadcrumbs={[
          { label: 'Admin', href: '/admin' },
          { label: 'Roles', href: '/admin/roles' },
          { label: 'Permission Search' },
        ]}
        backTo="/admin/roles"
        backLabel="Roles"
      />
      <div className="px-5 py-4">
        <div className="max-w-md mb-4">
          <Input
            placeholder="Search permissions (e.g. inventory.view, payroll.create)..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            aria-label="Search permissions"
          />
        </div>

        {isLoading && (
          <div className="flex items-center justify-center py-10">
            <Spinner />
          </div>
        )}

        {isError && (
          <EmptyState icon="alert-circle" title="Failed to load permissions" action={<Button variant="secondary" onClick={refetchAll}>Retry</Button>} />
        )}

        {!isLoading && !isError && filtered.length === 0 && (
          <EmptyState icon="search" title="No permissions found" description="Try a different search term." />
        )}

        {!isLoading && filtered.length > 0 && (
          <Panel title={`${filtered.length} permission${filtered.length === 1 ? '' : 's'} found`}>
            <div className="divide-y divide-default">
              {filtered.map((perm) => {
                const rolesList = rolesWithPermission.get(perm.slug) ?? [];
                return (
                  <div key={perm.slug} className="py-3">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <div className="text-sm font-medium">{perm.name}</div>
                        <div className="text-xs font-mono text-muted">{perm.slug}</div>
                        {perm.description && (
                          <div className="text-xs text-muted mt-0.5">{perm.description}</div>
                        )}
                      </div>
                      <Chip variant="neutral">{perm.module}</Chip>
                    </div>
                    {rolesList.length > 0 && (
                      <div className="mt-2 flex flex-wrap gap-1.5">
                        {rolesList.map((roleName) => (
                          <Chip key={roleName} variant="info">{roleName}</Chip>
                        ))}
                      </div>
                    )}
                    {rolesList.length === 0 && (
                      <div className="mt-1 text-xs text-muted">No roles have this permission</div>
                    )}
                  </div>
                );
              })}
            </div>
          </Panel>
        )}
      </div>
    </div>
  );
}
