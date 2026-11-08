import { useRealtimeSignal } from './useRealtimeSignal';
import { usePermission } from './usePermission';
import { useFeature } from './useFeature';

const CHANNEL = 'production.dashboard';

/** Shared refresh path for authorized production dashboard and widget consumers. */
export function useProductionDashboardRealtime(): void {
  const { can, canAll } = usePermission();
  const productionEnabled = useFeature('production');
  const canReadChannel = productionEnabled && (
    canAll('production.view', 'production.dashboard.view')
    || can('dashboard.plant_manager.view')
    || can('dashboard.ppc.view')
    || can('dashboard.company.view')
  );
  const channel = canReadChannel ? CHANNEL : '';

  useRealtimeSignal('production_dashboard_changed', channel, '.chain.refresh');
  useRealtimeSignal('production_dashboard_changed', channel, '.output.recorded');
  useRealtimeSignal('production_dashboard_changed', channel, '.sales_order.confirmed');
  useRealtimeSignal('production_dashboard_changed', channel, '.mrp.plan_generated');
  useRealtimeSignal('production_dashboard_changed', channel, '.mold.shot_limit_nearing');
  useRealtimeSignal('production_dashboard_changed', channel, '.mold.shot_limit_reached');
  useRealtimeSignal('production_dashboard_changed', channel, '.work_order.status_changed');
  useRealtimeSignal(
    'plant_manager_dashboard_changed',
    productionEnabled && can('dashboard.plant_manager.view') ? 'dashboard.plant_manager' : '',
    '.dashboard.plant_manager.changed',
  );
}
