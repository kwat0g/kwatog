/* eslint-disable @typescript-eslint/no-explicit-any */
/**
 * S1 — Admin Users & Roles Hub
 *
 * Supporting/admin feature hub. Each tab shows real inline data so users
 * get immediate value without extra navigation. The full pages are still
 * accessible via deep links from the tab content.
 */
import { useSearchParams } from 'react-router-dom';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { adminUsersApi } from '@/api/admin/users';
import { rolesApi } from '@/api/admin/roles';
import { permissionsApi } from '@/api/admin/permissions';
import { auditLogsApi } from '@/api/admin/audit-logs';
import { PageHeader } from '@/components/layout/PageHeader';
import { TabNavigation, type Tab } from '@/components/ui/TabNavigation';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';

const TABS: Tab[] = [
  { key: 'users', label: 'Users', to: '/admin/users-roles?tab=users' },
  { key: 'roles', label: 'Roles', to: '/admin/users-roles?tab=roles' },
  { key: 'permissions', label: 'Permissions', to: '/admin/users-roles?tab=permissions' },
  { key: 'audit', label: 'Audit', to: '/admin/users-roles?tab=audit' },
];

export default function AdminUsersRolesHubPage() {
  const [searchParams] = useSearchParams();
  const activeTab = searchParams.get('tab') ?? 'users';

  return (
    <div>
      <PageHeader title="Users & Roles" subtitle="Administration" />
      <TabNavigation tabs={TABS} defaultKey="users" />
      <div className="px-5 py-4">
        {activeTab === 'users' && <UsersTab />}
        {activeTab === 'roles' && <RolesTab />}
        {activeTab === 'permissions' && <PermissionsTab />}
        {activeTab === 'audit' && <AuditTab />}
      </div>
    </div>
  );
}

/* ─── Users Tab ───────────────────────────────────────── */

function UsersTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin-hub', 'users'],
    queryFn: () => adminUsersApi.list({ per_page: 10 }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;
  if (isError || !data?.data?.length) {
    return <EmptyState icon="users" title="No users found" description="Create a user to get started." />;
  }

  return (
    <div className="space-y-4">
      <Panel title="Users" actions={<Link to="/admin/users" className="text-sm text-accent hover:underline">View all →</Link>}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-default text-left">
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Name</th>
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Email</th>
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Role</th>
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Status</th>
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Created</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((u: any) => (
                <tr key={u.id} className="h-8 border-b border-subtle last:border-0 hover:bg-subtle">
                  <td className="px-2.5">
                    <Link to={`/admin/users/${u.id}`} className="text-accent hover:underline font-medium">
                      {u.name}
                    </Link>
                  </td>
                  <td className="px-2.5 text-secondary">{u.email}</td>
                  <td className="px-2.5">{u.role?.name ?? <span className="text-subtle">—</span>}</td>
                  <td className="px-2.5">
                    <Chip variant={u.is_active === false ? 'danger' : 'success'} >
                      {u.is_active === false ? 'Inactive' : 'Active'}
                    </Chip>
                  </td>
                  <td className="px-2.5 text-secondary">{u.created_at?.slice(0, 10)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
      <div className="flex gap-3">
        <Link to="/admin/users" className="text-sm text-accent hover:underline">Manage all users →</Link>
        <Link to="/admin/users/create" className="text-sm text-accent hover:underline">Create user →</Link>
      </div>
    </div>
  );
}

/* ─── Roles Tab ───────────────────────────────────────── */

function RolesTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin-hub', 'roles'],
    queryFn: () => rolesApi.list({ per_page: 20 }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;
  if (isError || !data?.data?.length) {
    return <EmptyState icon="shield" title="No roles found" />;
  }

  return (
    <div className="space-y-4">
      <Panel title="Roles" actions={<Link to="/admin/roles" className="text-sm text-accent hover:underline">View all →</Link>}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-default text-left">
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Name</th>
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Slug</th>
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Permissions</th>
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Type</th>
                <th className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium">Last Modified</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((r: any) => (
                <tr key={r.id} className="h-8 border-b border-subtle last:border-0 hover:bg-subtle">
                  <td className="px-2.5">
                    <Link to={`/admin/roles/${r.id}/permissions`} className="text-accent hover:underline font-medium">
                      {r.name}
                    </Link>
                  </td>
                  <td className="px-2.5 text-secondary font-mono">{r.slug}</td>
                  <td className="px-2.5 font-mono tabular-nums">{r.permissions_count ?? '—'}</td>
                  <td className="px-2.5">
                    <Chip variant={r.is_system ? 'info' : 'neutral'} >
                      {r.is_system ? 'System' : 'Custom'}
                    </Chip>
                  </td>
                  <td className="px-2.5 text-secondary">
                    {r.last_modified_by ? `${r.last_modified_by} · ${r.last_modified_at?.slice(0, 10)}` : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
      <div className="flex gap-3">
        <Link to="/admin/roles" className="text-sm text-accent hover:underline">Manage roles →</Link>
        <Link to="/admin/roles/create" className="text-sm text-accent hover:underline">Create role →</Link>
        <Link to="/admin/roles/compare" className="text-sm text-accent hover:underline">Compare roles →</Link>
      </div>
    </div>
  );
}

/* ─── Permissions Tab ──────────────────────────────────── */

function PermissionsTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin-hub', 'permissions'],
    queryFn: () => permissionsApi.matrix(),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;
  if (isError || !data || !Object.keys(data).length) {
    return <EmptyState icon="shield" title="No permissions found" />;
  }

  const modules = Object.entries(data).sort(([a], [b]) => a.localeCompare(b));
  const totalPerms = modules.reduce((sum, [, perms]) => sum + perms.length, 0);

  return (
    <Panel title="Permissions by Module" actions={<Link to="/admin/roles" className="text-sm text-accent hover:underline">Manage via roles →</Link>}>
      <div className="mb-3 text-xs text-text-subtle">{totalPerms} total permissions across {modules.length} modules</div>
      <div className="grid grid-cols-2 gap-3">
        {modules.map(([mod, perms]) => (
          <div key={mod} className="rounded-md border border-default p-3">
            <div className="flex items-center justify-between mb-2">
              <span className="text-sm font-medium capitalize">{mod.replace(/_/g, ' ')}</span>
              <Chip variant="neutral">{perms.length}</Chip>
            </div>
            <ul className="space-y-0.5">
              {perms.slice(0, 8).map((p) => (
                <li key={p.slug} className="text-xs text-secondary font-mono truncate" title={p.name}>
                  {p.slug}
                </li>
              ))}
              {perms.length > 8 && (
                <li className="text-2xs text-text-subtle">+{perms.length - 8} more…</li>
              )}
            </ul>
          </div>
        ))}
      </div>
    </Panel>
  );
}

/* ─── Audit Tab ────────────────────────────────────────── */

function AuditTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin-hub', 'audit'],
    queryFn: () => auditLogsApi.list({ per_page: 10 }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;
  if (isError || !data?.data?.length) {
    return <EmptyState icon="search" title="No audit log entries" />;
  }

  return (
    <div className="space-y-4">
      <Panel title="Recent Activity" actions={<Link to="/admin/audit-logs" className="text-sm text-accent hover:underline">View all →</Link>}>
        <div className="space-y-2">
          {data.data.slice(0, 10).map((entry: any) => (
            <div key={entry.id} className="flex items-center gap-3 py-1.5 border-b border-default last:border-0">
              <Chip
                variant={entry.action === 'deleted' ? 'danger' : entry.action === 'created' ? 'success' : 'warning'}
                
              >
                {entry.action}
              </Chip>
              <span className="text-sm flex-1 truncate">
                <span className="font-medium">{entry.user?.name ?? 'System'}</span>
                <span className="text-text-subtle"> on </span>
                <span className="font-mono text-xs">{entry.model_type?.split('\\').pop()}</span>
              </span>
              <span className="text-2xs text-text-subtle whitespace-nowrap">
                {entry.created_at?.slice(0, 16).replace('T', ' ')}
              </span>
            </div>
          ))}
        </div>
      </Panel>
      <div className="flex gap-3">
        <Link to="/admin/audit-logs" className="text-sm text-accent hover:underline">View audit logs →</Link>
        <Link to="/admin/activity" className="text-sm text-accent hover:underline">Activity feed →</Link>
      </div>
    </div>
  );
}
