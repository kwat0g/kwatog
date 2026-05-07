import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Save, ChevronDown, ChevronRight, Lock } from 'lucide-react';
import { rolesApi } from '@/api/admin/roles';
import { permissionsApi } from '@/api/admin/permissions';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Spinner } from '@/components/ui/Spinner';
import { PageHeader } from '@/components/layout/PageHeader';
import { cn } from '@/lib/cn';

/**
 * Series R — Task R1.
 *
 * Per-role permission editor. Improvements over the prior version:
 *   - `is_system` roles are read-only; banner explains why and offers Clone.
 *   - Diff counter ("3 changes unsaved") drives Save button visibility.
 *   - Module / search filters scope visible permissions client-side.
 *   - On save, the diff count resets so the badge disappears.
 *
 * The backend RoleService::syncPermissions logs the {added, removed} diff
 * to audit_logs whether or not the UI shows a count.
 */
export default function RolePermissionsPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const role = useQuery({
    queryKey: ['admin', 'role', id],
    queryFn: () => rolesApi.show(id),
  });
  const matrix = useQuery({
    queryKey: ['admin', 'permissions', 'matrix'],
    queryFn: permissionsApi.matrix,
  });

  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [baseline, setBaseline] = useState<Set<string>>(new Set());
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
  const [search, setSearch] = useState('');
  const [moduleFilter, setModuleFilter] = useState<string>('all');

  // Initialize selection once role data lands.
  useEffect(() => {
    if (role.data?.permissions) {
      const slugs = role.data.permissions.map((p) => p.slug);
      setSelected(new Set(slugs));
      setBaseline(new Set(slugs));
    }
  }, [role.data]);

  const totalPermissions = useMemo(
    () => Object.values(matrix.data ?? {}).reduce((sum, arr) => sum + arr.length, 0),
    [matrix.data],
  );

  // Diff count between current selection and the persisted baseline.
  const diff = useMemo(() => {
    let added = 0;
    let removed = 0;
    selected.forEach((s) => {
      if (!baseline.has(s)) added++;
    });
    baseline.forEach((s) => {
      if (!selected.has(s)) removed++;
    });
    return { added, removed, total: added + removed };
  }, [selected, baseline]);

  const isSystem = !!role.data?.is_system;
  const isSystemAdmin = role.data?.slug === 'system_admin';

  const toggleSlug = (slug: string) => {
    if (isSystem) return;
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(slug)) next.delete(slug);
      else next.add(slug);
      return next;
    });
  };

  const toggleModule = (module: string, all: boolean) => {
    if (isSystem) return;
    const slugs = matrix.data?.[module]?.map((p) => p.slug) ?? [];
    setSelected((prev) => {
      const next = new Set(prev);
      slugs.forEach((s) => (all ? next.add(s) : next.delete(s)));
      return next;
    });
  };

  const toggleCollapsed = (module: string) => {
    setCollapsed((prev) => {
      const next = new Set(prev);
      if (next.has(module)) next.delete(module);
      else next.add(module);
      return next;
    });
  };

  const save = useMutation({
    mutationFn: () => rolesApi.syncPermissions(id, Array.from(selected)),
    onSuccess: () => {
      toast.success(
        diff.total === 0
          ? 'Permissions saved.'
          : `Permissions saved (${diff.added} added, ${diff.removed} removed).`,
      );
      setBaseline(new Set(selected));
      queryClient.invalidateQueries({ queryKey: ['admin', 'role', id] });
      queryClient.invalidateQueries({ queryKey: ['admin', 'roles'] });
    },
    onError: (err: { response?: { data?: { message?: string } } }) => {
      toast.error(err?.response?.data?.message ?? 'Failed to save permissions.');
    },
  });

  // Build the visible matrix (filter applied) without mutating the source.
  const matrixData = matrix.data;
  const visibleMatrix = useMemo(() => {
    if (!matrixData) return {} as Record<string, never[]>;
    const q = search.trim().toLowerCase();
    const out: Record<string, typeof matrixData[string]> = {};
    for (const [module, perms] of Object.entries(matrixData)) {
      if (moduleFilter !== 'all' && moduleFilter !== module) continue;
      const filtered = perms.filter(
        (p) => !q || p.slug.toLowerCase().includes(q) || p.name.toLowerCase().includes(q),
      );
      if (filtered.length > 0) out[module] = filtered;
    }
    return out;
  }, [matrixData, search, moduleFilter]);

  return (
    <div>
      <PageHeader
        title={role.data ? `${role.data.name} permissions` : 'Permissions'}
        subtitle={
          role.data ? (
            <span className="flex items-center gap-2">
              <Chip variant={isSystem ? 'info' : 'neutral'}>{isSystem ? 'System' : 'Custom'}</Chip>
              <span className="text-muted">Toggle which actions this role can perform.</span>
              {diff.total > 0 && (
                <Chip variant="warning">
                  {diff.total} change{diff.total === 1 ? '' : 's'} unsaved
                </Chip>
              )}
            </span>
          ) : undefined
        }
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
              disabled={diff.total === 0 || save.isPending || isSystem}
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
          <>
            {isSystem && (
              <Panel
                title={
                  <span className="flex items-center gap-2 text-warning">
                    <Lock size={14} /> System role — read-only
                  </span>
                }
                className="mb-4"
              >
                <p className="text-sm text-secondary">
                  {isSystemAdmin
                    ? 'System Administrator always has every permission. Editing is disabled by design.'
                    : 'This role is seeded by the system and cannot be edited. Use Clone to create a customizable copy.'}
                </p>
                <div className="mt-3">
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() =>
                      navigate('/admin/roles/create', { state: { cloneFrom: role.data.id } })
                    }
                  >
                    Clone this role
                  </Button>
                </div>
              </Panel>
            )}

            <Panel
              title={
                <span className="flex items-center gap-3">
                  <span>{selected.size} of {totalPermissions} permissions</span>
                  {diff.total > 0 && (
                    <span className="text-xs font-mono tabular-nums text-muted">
                      +{diff.added} −{diff.removed}
                    </span>
                  )}
                </span>
              }
            >
              <div className="flex items-center gap-3 mb-3">
                <div className="flex-1 max-w-sm">
                  <Input
                    placeholder="Search permissions…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    aria-label="Search permissions"
                  />
                </div>
                <select
                  value={moduleFilter}
                  onChange={(e) => setModuleFilter(e.target.value)}
                  aria-label="Module filter"
                  className="h-8 px-3 rounded-md border border-default bg-canvas text-sm"
                >
                  <option value="all">All modules</option>
                  {Object.keys(matrix.data).map((m) => (
                    <option key={m} value={m}>
                      {m}
                    </option>
                  ))}
                </select>
              </div>

              {Object.keys(visibleMatrix).length === 0 && (
                <EmptyState
                  icon="search"
                  title="No permissions match"
                  description="Try clearing the search or pick a different module."
                />
              )}

              <div className="flex flex-col gap-3">
                {Object.entries(visibleMatrix).map(([module, perms]) => {
                  const allSelected = perms.every((p) => selected.has(p.slug));
                  const someSelected = perms.some((p) => selected.has(p.slug));
                  const isCollapsed = collapsed.has(module);
                  const moduleSelectedCount = perms.filter((p) => selected.has(p.slug)).length;

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
                            {moduleSelectedCount}/{perms.length}
                          </span>
                          <Checkbox
                            checked={allSelected}
                            onChange={() => toggleModule(module, !allSelected)}
                            disabled={isSystem}
                            aria-label={`Toggle all ${module} permissions`}
                            className={cn(someSelected && !allSelected && 'opacity-60')}
                          />
                        </span>
                      </button>
                      {!isCollapsed && (
                        <ul className="border-t border-default divide-y divide-[var(--border-subtle)]">
                          {perms.map((p) => (
                            <li
                              key={p.slug}
                              className="flex items-center justify-between px-3 py-2 hover:bg-subtle"
                            >
                              <div>
                                <div className="text-sm">{p.name}</div>
                                <div className="text-xs font-mono text-muted">{p.slug}</div>
                                {p.description && (
                                  <div className="text-xs text-muted mt-0.5">{p.description}</div>
                                )}
                              </div>
                              <Checkbox
                                checked={selected.has(p.slug)}
                                onChange={() => toggleSlug(p.slug)}
                                disabled={isSystem}
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
          </>
        )}
      </div>
    </div>
  );
}
