import { useQuery } from '@tanstack/react-query';
import { GitBranch, Package, Cpu, Settings } from 'lucide-react';
import { HubPage, HubCard, NavTile, type HubStat } from '@/components/hub';
import { Chip } from '@/components/ui/Chip';
import { Spinner } from '@/components/ui/Spinner';
import { Link } from 'react-router-dom';
import { mrpPlansApi } from '@/api/mrp/mrpPlans';
import { moldsApi } from '@/api/mrp/molds';

export default function MrpHubPage() {
  const { data: plans, isLoading: loadingPlans } = useQuery({
    queryKey: ['mrp', 'plans', 'hub'],
    queryFn: () => mrpPlansApi.list({ per_page: 5 }),
    refetchInterval: 60_000,
  });

  const { data: molds, isLoading: loadingMolds } = useQuery({
    queryKey: ['mrp', 'molds', 'hub'],
    queryFn: () => moldsApi.list({ per_page: 5, nearing_limit: true }),
    refetchInterval: 60_000,
  });

  const isLoading = loadingPlans || loadingMolds;

  const activePlans = plans?.meta?.total ?? 0;
  const totalBoms = 0; // Would need dedicated endpoint
  const machines = 0; // Would need dedicated endpoint
  const moldAlerts = molds?.meta?.total ?? 0;

  const stats: HubStat[] = [
    { label: 'Active Plans', value: activePlans, linkTo: '/mrp/plans' },
    { label: 'Total BOMs', value: totalBoms, linkTo: '/mrp/boms' },
    { label: 'Machines', value: machines, linkTo: '/mrp/machines' },
    { label: 'Mold Alerts', value: moldAlerts, linkTo: '/mrp/molds' },
  ];

  return (
    <HubPage title="MRP / Manufacturing" subtitle="Material planning, BOMs, and production scheduling" breadcrumbs={[{ label: 'MRP' }]} stats={isLoading ? undefined : stats}>
      {isLoading ? (
        <div className="flex justify-center py-12"><Spinner /></div>
      ) : (
        <>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <HubCard title="Active MRP Plans" icon={GitBranch} viewAllHref="/mrp/plans">
              {!plans?.data || plans.data.length === 0 ? (
                <p className="text-sm text-muted">No active MRP plans.</p>
              ) : (
                <div className="space-y-2">
                  {plans.data.slice(0, 5).map((plan: any) => (
                    <div key={plan.id} className="flex items-center justify-between text-sm">
                      <Link to={`/mrp/plans/${plan.id}`} className="text-accent hover:underline">{plan.name || plan.id}</Link>
                      <Chip variant={plan.status === 'completed' ? 'success' : plan.status === 'in_progress' ? 'info' : 'warning'} >{plan.status}</Chip>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>

            <HubCard title="Mold Shot Warnings" icon={Settings} viewAllHref="/mrp/molds">
              {!molds?.data || molds.data.length === 0 ? (
                <p className="text-sm text-muted">No molds nearing shot limit.</p>
              ) : (
                <div className="space-y-2">
                  {molds.data.slice(0, 5).map((mold: any) => (
                    <div key={mold.id} className="flex items-center justify-between text-sm">
                      <Link to={`/mrp/molds/${mold.id}`} className="text-accent hover:underline">{mold.mold_no}</Link>
                      <span className="font-mono tabular-nums text-muted text-xs">{mold.current_shot_count} / {mold.max_shot_count}</span>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>
          </div>

          <div>
            <h3 className="text-xs font-medium text-muted uppercase tracking-wider mb-3">All Sections</h3>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
              <NavTile to="/mrp/plans" icon={GitBranch} label="Plans" description="MRP calculation runs" />
              <NavTile to="/mrp/boms" icon={Package} label="BOMs" description="Bill of materials" />
              <NavTile to="/mrp/machines" icon={Cpu} label="Machines" description="Production equipment" />
              <NavTile to="/mrp/molds" icon={Settings} label="Molds" description="Injection molds and tooling" />
            </div>
          </div>
        </>
      )}
    </HubPage>
  );
}
