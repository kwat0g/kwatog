import { Outlet } from 'react-router-dom';
import { useDriverDeliveryRealtime } from '@/hooks/useDriverDeliveryRealtime';

export function DriverRealtimeBoundary() {
  useDriverDeliveryRealtime();
  return <Outlet />;
}
