import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { useAuthStore } from '@/stores/authStore';

/**
 * Placeholder dashboard — role-specific dashboards land in Sprint 8 (Tasks 72-73).
 */
export default function DashboardPage() {
  const user = useAuthStore((s) => s.user);

  return (
    <div>
      <PageHeader
        title={`Welcome${user ? `, ${user.name}` : ''}`}
        subtitle="Foundation sprint complete. Module dashboards land in upcoming sprints."
      />

      <div className="px-5 py-4 grid grid-cols-2 md:grid-cols-4 gap-3">
        <StatCard label="Active modules" value={user?.features.length ?? 0} />
        <StatCard label="Permissions" value={user?.permissions.length ?? 0} />
        <StatCard label="Role" value={user?.role?.name ?? '—'} />
        <StatCard label="Account" value={user?.is_active ? 'Active' : 'Inactive'} />
      </div>

      <div className="px-5 pb-6">
        <Panel title="Sprint 1 status">
          <p className="text-sm text-muted">
            Auth, RBAC, design system, layout shell, and shared services are wired and ready.
            Module pages are scaffolded behind <code className="font-mono text-xs">ModuleGuard</code>{' '}
            and will be enabled per the sprint plan.
          </p>
        </Panel>
      </div>
    </div>
  );
}
