import { Navigate, Route } from 'react-router-dom';
import OldWorkOrderDetailRedirect from '@/pages/maintenance/mobile/redirect';

export const maintenanceMobileRoutes = (
 <>
  <Route path="/maintenance/mobile" element={<Navigate to="/maintenance/work-orders" replace />} />
  <Route path="/maintenance/mobile/:mwoId" element={<OldWorkOrderDetailRedirect />} />
 </>
);
