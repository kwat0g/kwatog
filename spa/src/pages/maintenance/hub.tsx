import { useQuery } from '@tanstack/react-query';
import { Wrench, Clock, Activity, Calendar, Package } from 'lucide-react';
import { HubPage, HubCard, NavTile, type HubStat } from '@/components/hub';
import { Chip } from '@/components/ui/Chip';
import { Spinner } from '@/components/ui/Spinner';
import { Link } from 'react-router-dom';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { schedulesApi } from '@/api/maintenance/schedules';

export default function MaintenanceHubPage() {
  const { data: workOrders, isLoading: loadingWO } = useQuery({
    queryKey: ['maintenance', 'work-orders', 'hub'],
    queryFn: () => workOrdersApi.list({ per_page: 5, status: 'open' }),
    refetchInterval: 60_000,
  });

  const { data: schedules, isLoading: loadingSch } = useQuery({
    queryKey: ['maintenance', 'schedules', 'hub'],
    queryFn: () => schedulesApi.list({ per_page: 5 }),
    refetchInterval: 60_000,
  });

  const isLoading = loadingWO || loadingSch;

  const openWO = workOrders?.data?.filter((wo: any) => wo.status === 'open').length ?? 0;
  const overdueWO = workOrders?.data?.filter((wo: any) => wo.status === 'overdue').length ?? 0;
  const totalWO = workOrders?.meta?.total ?? 0;
  const upcomingPM = schedules?.data?.length ?? 0;

  const stats: HubStat[] = [
    { label: 'Open WOs', value: openWO, linkTo: '/maintenance/work-orders' },
    { label: 'Overdue', value: overdueWO },
    { label: 'Machines Monitored', value: totalWO },
    { label: 'Upcoming PM', value: upcomingPM },
  ];

  return (
    <HubPage title="Maintenance" subtitle="Equipment maintenance, work orders, and preventive schedules" breadcrumbs={[{ label: 'Maintenance' }]} stats={isLoading ? undefined : stats}>
      {isLoading ? (
        <div className="flex justify-center py-12"><Spinner /></div>
      ) : (
        <>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <HubCard title="Active Work Orders" icon={Wrench} viewAllHref="/maintenance/work-orders">
              {!workOrders?.data || workOrders.data.length === 0 ? (
                <p className="text-sm text-muted">No active work orders.</p>
              ) : (
                <div className="space-y-2">
                  {workOrders.data.slice(0, 5).map((wo: any) => (
                    <div key={wo.id} className="flex items-center justify-between text-sm">
                      <Link to={`/maintenance/work-orders/${wo.id}`} className="text-accent hover:underline">{wo.title || wo.id}</Link>
                      <Chip variant={wo.priority === 'high' ? 'danger' : wo.priority === 'medium' ? 'warning' : 'neutral'} >{wo.priority}</Chip>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>

            <HubCard title="Upcoming Schedules" icon={Calendar} viewAllHref="/maintenance/schedules">
              {!schedules?.data || schedules.data.length === 0 ? (
                <p className="text-sm text-muted">No upcoming schedules.</p>
              ) : (
                <div className="space-y-2">
                  {schedules.data.slice(0, 5).map((sch: any) => (
                    <div key={sch.id} className="flex items-center justify-between text-sm">
                      <span className="text-primary">{sch.title || 'PM Schedule'}</span>
                      <span className="font-mono tabular-nums text-muted text-xs">{sch.next_due_date}</span>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>
          </div>

          <div>
            <h3 className="text-xs font-medium text-muted uppercase tracking-wider mb-3">All Sections</h3>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
              <NavTile to="/maintenance/work-orders" icon={Wrench} label="Work Orders" description="Corrective and preventive tasks" />
              <NavTile to="/maintenance/schedules" icon={Calendar} label="Schedules" description="Preventive maintenance plans" />
              <NavTile to="/maintenance/machine-health" icon={Activity} label="Machine Health" description="Real-time condition monitoring" />
              <NavTile to="/maintenance/downtime" icon={Clock} label="Downtime Analytics" description="MTBF and MTTR reports" />
              <NavTile to="/assets" icon={Package} label="Assets" description="Fixed asset registry" />
              <NavTile to="/admin/depreciation" icon={Clock} label="Depreciation" description="Asset depreciation runs" />
            </div>
          </div>
        </>
      )}
    </HubPage>
  );
}
