import { useQuery } from '@tanstack/react-query';
import { Users, Shield, FileText, Settings, Calendar, Table, Activity } from 'lucide-react';
import { HubPage, HubCard, NavTile, type HubStat } from '@/components/hub';
import { Spinner } from '@/components/ui/Spinner';
import { adminUsersApi } from '@/api/admin/users';
import { rolesApi } from '@/api/admin/roles';
import { auditLogsApi } from '@/api/admin/audit-logs';

export default function AdminHubPage() {
  const { data: users, isLoading: loadingUsers } = useQuery({
    queryKey: ['admin', 'users', 'hub'],
    queryFn: () => adminUsersApi.list({ per_page: 100 }),
    refetchInterval: 60_000,
  });

  const { data: roles, isLoading: loadingRoles } = useQuery({
    queryKey: ['admin', 'roles', 'hub'],
    queryFn: () => rolesApi.list(),
    refetchInterval: 60_000,
  });

  const { data: auditLogs, isLoading: loadingAudit } = useQuery({
    queryKey: ['admin', 'audit-logs', 'hub'],
    queryFn: () => auditLogsApi.list({ per_page: 5 }),
    refetchInterval: 60_000,
  });

  const isLoading = loadingUsers || loadingRoles || loadingAudit;

  const activeUsers = users?.data?.filter((u: any) => u.is_active).length ?? 0;
  const totalRoles = roles?.data?.length ?? 0;
  const auditEventsToday = auditLogs?.meta?.total ?? 0;
  const modulesEnabled = 17; // Static for now

  const stats: HubStat[] = [
    { label: 'Active Users', value: activeUsers, linkTo: '/admin/users' },
    { label: 'Roles', value: totalRoles, linkTo: '/admin/roles' },
    { label: 'Audit Events Today', value: auditEventsToday, linkTo: '/admin/audit-logs' },
    { label: 'Modules Enabled', value: modulesEnabled, linkTo: '/admin/settings' },
  ];

  const userStatusBreakdown = users?.data
    ? [
        { status: 'Active', count: users.data.filter((u: any) => u.is_active).length },
        { status: 'Inactive', count: users.data.filter((u: any) => !u.is_active).length },
      ]
    : [];

  return (
    <HubPage title="Admin" subtitle="System administration, users, roles, and audit logs" breadcrumbs={[{ label: 'Admin' }]} stats={isLoading ? undefined : stats}>
      {isLoading ? (
        <div className="flex justify-center py-12"><Spinner /></div>
      ) : (
        <>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <HubCard title="Recent Audit Activity" icon={FileText} viewAllHref="/admin/audit-logs">
              {!auditLogs?.data || auditLogs.data.length === 0 ? (
                <p className="text-sm text-muted">No recent audit activity.</p>
              ) : (
                <div className="space-y-2">
                  {auditLogs.data.slice(0, 5).map((log: any) => (
                    <div key={log.id} className="flex items-center justify-between text-sm">
                      <span className="text-primary truncate">{log.event}</span>
                      <span className="font-mono tabular-nums text-muted text-xs">{log.created_at ? new Date(log.created_at).toLocaleTimeString() : ''}</span>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>

            <HubCard title="User Status Breakdown" icon={Users}>
              {userStatusBreakdown.length === 0 ? (
                <p className="text-sm text-muted">No user data.</p>
              ) : (
                <div className="space-y-2">
                  {userStatusBreakdown.map((item: any, idx: number) => (
                    <div key={idx} className="flex items-center justify-between text-sm">
                      <span className="text-primary">{item.status}</span>
                      <span className="font-mono tabular-nums text-muted">{item.count}</span>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>
          </div>

          <div>
            <h3 className="text-xs font-medium text-muted uppercase tracking-wider mb-3">All Sections</h3>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
              <NavTile to="/admin/users" icon={Users} label="Users" description="User accounts and access" />
              <NavTile to="/admin/roles" icon={Shield} label="Roles" description="Role-based permissions" />
              <NavTile to="/admin/audit-logs" icon={FileText} label="Audit Logs" description="System activity tracking" />
              <NavTile to="/admin/settings" icon={Settings} label="Settings" description="System configuration" />
              <NavTile to="/admin/scheduled-exports" icon={Calendar} label="Scheduled Exports" description="Automated report exports" />
              <NavTile to="/admin/gov-tables" icon={Table} label="Gov Tables" description="SSS, PhilHealth, HDMF rates" />
              <NavTile to="/admin/activity-feed" icon={Activity} label="Activity Feed" description="Real-time event stream" />
            </div>
          </div>
        </>
      )}
    </HubPage>
  );
}
