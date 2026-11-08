import { usePermission } from '@/hooks/usePermission';
import { useRealtimeSignal } from './useRealtimeSignal';

/** Refresh the authorized PPC snapshot after any of its aggregate sources change. */
export function usePpcDashboardRealtime(): void {
  const { can } = usePermission();
  const channel = can('dashboard.ppc.view') ? 'dashboard.ppc' : '';

  useRealtimeSignal('ppc_dashboard_changed', channel, '.ppc.dashboard.changed');
}
