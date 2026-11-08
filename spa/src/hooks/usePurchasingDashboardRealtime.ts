import { usePermission } from '@/hooks/usePermission';
import { useRealtimeSignal } from './useRealtimeSignal';

/** Authorized aggregate refresh for stockout and demand-risk dashboard inputs. */
export function usePurchasingDashboardRealtime(): void {
  const { can } = usePermission();
  const channel = can('dashboard.purchasing.view')
    ? 'dashboard.purchasing'
    : '';

  useRealtimeSignal(
    'purchasing_dashboard_changed',
    channel,
    '.purchasing.dashboard.changed',
  );

  useRealtimeSignal(
    'purchasing_dashboard_changed',
    channel,
    '.forecast.demand-forecast.changed',
  );
}
