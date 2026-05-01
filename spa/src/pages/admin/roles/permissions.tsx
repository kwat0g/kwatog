import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Save, ChevronDown, ChevronRight } from 'lucide-react';
import { rolesApi } from '@/api/admin/roles';
import { permissionsApi } from '@/api/admin/permissions';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { Spinner } from '@/components/ui/Spinner';
import { PageHeader } from '@/components/layout/PageHeader';
import { cn } from '@/lib/cn';

export default function RolePermissionsPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const role = useQuery({ queryKey: ['admin', 'role', id], queryFn: () => rolesApi.show(id) });
  const matrix = useQuery({ queryKey: ['admin', 'permissions', 'matrix'], queryFn: permissionsApi.matrix });

  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
  const [dirty, setDirty] = useState(false);

  // Initialize selection once role data lands.
  useEffect(() => {
    if (role.data?.permissions) {
      setSelected(new Set(role.data.permissions.map((p) => p.slug)));
      setDirty(false);
    }
  }, [role.data]);

  const totalPermissions = useMemo(
    () => Object.values(matrix.data ?? {}).reduce((sum, arr) => sum + arr.length, 0),
    [matrix.data],
  );

  const toggleSlug = (slug: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(slug) ? next.delete(slug) : next.add(slug);
      return next;
    });
    setDirty(true);
  };

  const toggleModule = (module: string, all: boolean) => {
    const slugs = matrix.data?.[module]?.map((p) => p.slug) ?? [];
    setSelected((prev) => {
      const next = new Set(prev);
      slugs.forEach((s) => (all ? next.add(s) : next.delete(s)));
      return next;
    });
    setDirty(true);
  };

  const toggleCollapsed = (module: string) => {
    setCollapsed((prev) => {
      const next = new Set(prev);
      next.has(module) ? next.delete(module) : next.add(module);
      return next;
    });
  };

  const save = useMutation({
    mutationFn: () => rolesApi.syncPermissions(id, Array.from(selected)),
    onSuccess: () => {
      toast.success('Permissions updated.');
      setDirty(false);
      queryClient.invalidateQueries({ queryKey: ['admin', 'role', id] });
      queryClient.invalidateQueries({ queryKey: ['admin', 'roles'] });
    },
  });

  const isSystemAdmin = role.data?.slug === 'system_admin';

  return (
    <div>
      <PageHeader
        title={role.data ? `${role.data.name} permissions` : 'Permissions'}
        subtitle={role.data ? <>Toggle which actions this role can perform.</> : undefined}
        backTo="/admin/roles"
        backLabel="Roles"
        actions={
          <>
            <Button variant="secondary" size="sm" onClick={() => navigate('/admin/roles')}>
              Cancel
            </Button>
            <Button
              variant="primary"
              size="sm"
              icon={<Save size={14} />}
              loading={save.isPending}
              disabled={!dirty || save.isPending || isSystemAdmin}
              onClick={() => save.mutate()}
            >
              {save.isPending ? 'Saving…' : 'Save changes'}
            </Button>
          </>
        }
      />

      <div className="px-5 py-4">
        {(role.isLoading || matrix.isLoading) && (
          <div className="flex items-center justify-center py-10 text-muted">
            <Spinner /> <span className="ml-2 text-sm">Loading permissions…</span>
          </div>
        )}

        {(role.isError || matrix.isError) && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load"
            description="Could not load the role or permission matrix."
            action={
              <Button variant="secondary" onClick={() => window.location.reload()}>
                Retry
              </Button>
            }
          />
        )}

        {matrix.data && role.data && (
          <Panel
            title={`${selected.size} of ${totalPermissions} permissions`}
            meta={isSystemAdmin && (
              <span className="text-warning">System Admin always has all permissions — editing is disabled.</span>
            )}
          >
            <div className="flex flex-col gap-3">
              {Object.entries(matrix.data).map(([module, perms]) => {
                const allSelected = perms.every((p) => selected.has(p.slug));
                const someSelected = perms.some((p) => selected.has(p.slug));
                const isCollapsed = collapsed.has(module);
                return (
                  <div key={module} className="border border-default rounded-md">
                    <button
                      type="button"
                      onClick={() => toggleCollapsed(module)}
                      className="w-full flex items-center justify-between px-3 py-2 text-left text-sm"
                    >
                      <span className="flex items-center gap-2 font-medium uppercase tracking-wide text-2xs text-muted">
                        {isCollapsed ? <ChevronRight size={12} /> : <ChevronDown size={12} />}
                        {module}
                      </span>
                      <span className="flex items-center gap-3">
                        <span className="text-xs font-mono tabular-nums text-muted">
                          {perms.filter((p) => selected.has(p.slug)).length}/{perms.length}
                        </span>
                        <Checkbox
                          checked={allSelected}
                          onChange={() => toggleModule(module, !allSelected)}
                          disabled={isSystemAdmin}
                          aria-label={`Toggle all ${module} permissions`}
                          // Indicate intermediate state without an indeterminate prop.
                          className={cn(someSelected && !allSelected && 'opacity-60')}
                        />
                      </span>
                    </button>
                    {!isCollapsed && (
                      <ul className="border-t border-default divide-y divide-[var(--border-subtle)]">
                        {perms.map((p) => (
                          <li key={p.slug} className="flex items-center justify-between px-3 py-2 hover:bg-subtle">
                            <div>
                              <div className="text-sm">{p.name}</div>
                              <div className="text-xs font-mono text-muted">{p.slug}</div>
                            </div>
                            <Checkbox
                              checked={selected.has(p.slug)}
                              onChange={() => toggleSlug(p.slug)}
                              disabled={isSystemAdmin}
                              aria-label={`Toggle ${p.name}`}
                            />
                          </li>
                        ))}
                      </ul>
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
