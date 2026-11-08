import { usePermission } from '@/hooks/usePermission';
import { useFeature } from '@/hooks/useFeature';
import { useRealtimeSignal } from './useRealtimeSignal';

/** Refresh Finance aggregates through each viewer's existing API permissions. */
export function useFinanceDashboardRealtime(): void {
  const { can } = usePermission();
  const accountingEnabled = useFeature('accounting');
  const channel = accountingEnabled && can('dashboard.accounting.view')
    ? 'dashboard.finance'
    : '';

  useRealtimeSignal('finance_dashboard_changed', channel, '.finance.dashboard.changed');
}
