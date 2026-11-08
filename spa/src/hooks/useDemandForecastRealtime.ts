import { usePermission } from '@/hooks/usePermission';
import { useRealtimeList } from './useRealtimeRecord';

/** Refresh forecast lists and derived forecast views through their authorized APIs. */
export function useDemandForecastRealtime(): void {
  const { can } = usePermission();
  useRealtimeList('demand_forecast', can('forecasting.view') ? 'visible' : undefined);
}
