import { useEffect, useState, useCallback, useRef } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import axios from 'axios';
import { customerPortalApi } from '@/api/b2b/customer';
import type { CustomerPortalUser } from '@/types/b2b';
import PortalLayout from './PortalLayout';
import { FullPageLoader } from '@/components/ui/Spinner';
import { PortalBootstrapError } from '@/components/ui/PortalBootstrapError';
import { useCustomerInvoiceRealtime } from '@/hooks/useCustomerInvoiceRealtime';
import { useCustomerOrderRealtime } from '@/hooks/useCustomerOrderRealtime';
import { useCustomerComplaintRealtime } from '@/hooks/useCustomerComplaintRealtime';
import { useCustomerPriceRequestRealtime } from '@/hooks/useCustomerPriceRequestRealtime';
import { useRealtimeSignal } from '@/hooks/useRealtimeSignal';
import { usePortalSessionSync } from '@/hooks/usePortalSessionSync';
import { publishAuthSessionSignal, serializeAuthSessionMutation } from '@/lib/authSessionSync';
import { clearPwaCaches } from '@/sw-register';
import { clearLocalAuthSession, useAuthStore } from '@/stores/authStore';

export default function CustomerPortalLayout() {
 const location = useLocation();
 const navigate = useNavigate();
 const currentPath = useRef(location.pathname);
 currentPath.current = location.pathname;
 const [user, setUser] = useState<CustomerPortalUser | null>(null);
 const [isLoading, setIsLoading] = useState(true);
 const [bootstrapError, setBootstrapError] = useState(false);
 const [retryCount, setRetryCount] = useState(0);

 const sessionChanging = useCallback(() => {
  setUser(null);
  setIsLoading(true);
  setBootstrapError(false);
 }, []);
 const sessionChanged = useCallback(() => setRetryCount((count) => count + 1), []);
 usePortalSessionSync('customer', sessionChanging, sessionChanged);

 useCustomerInvoiceRealtime(user?.customer_id);
 useCustomerOrderRealtime(user?.customer_id);
 useCustomerComplaintRealtime(user?.customer_id);
 useCustomerPriceRequestRealtime(user?.customer_id);
 useRealtimeSignal(
  'customer_catalog_changed',
  user?.customer_id ? `customer.catalog.${user.customer_id}` : '',
  '.customer.catalog.changed',
 );

 useEffect(() => {
 let cancelled = false;
 setIsLoading(true);
 setBootstrapError(false);
 customerPortalApi.me()
  .then((u) => {
  if (cancelled) return;
  if (u.must_change_password) {
  navigate('/portal/customer/change-password', { replace: true });
  return;
  }
  setUser(u);
  })
 .catch((error: unknown) => {
 if (cancelled) return;
 if (axios.isAxiosError(error) && error.response?.status === 401) {
  navigate('/sign-in', { state: { realm: 'customer', from: currentPath.current }, replace: true });
 } else {
 setBootstrapError(true);
 }
 })
 .finally(() => { if (!cancelled) setIsLoading(false); });
 return () => { cancelled = true; };
 }, [navigate, retryCount]);

 const handleLogout = useCallback(async () => {
  // The API invalidates the shared session container, including sibling guards.
  publishAuthSessionSignal('all', 'changing');
  clearLocalAuthSession();
  setUser(null);
  setIsLoading(true);
  setBootstrapError(false);
  const pwaCacheCleanup = clearPwaCaches();
  try {
   await serializeAuthSessionMutation(() => customerPortalApi.logout());
  } finally {
   publishAuthSessionSignal('all', 'changed');
   void useAuthStore.getState().bootstrap();
   if (!(await pwaCacheCleanup)) console.warn('Could not clear all Ogami service-worker caches during logout.');
   navigate('/sign-in', { state: { realm: 'customer' }, replace: true });
  }
 }, [navigate]);

 if (isLoading) return <FullPageLoader />;
 if (bootstrapError) {
 return (
 <PortalBootstrapError
 title="Customer portal unavailable"
 onRetry={() => setRetryCount((count) => count + 1)}
  onSignIn={() => navigate('/sign-in', { state: { realm: 'customer' }, replace: true })}
 />
 );
 }
 if (!user) return null;

 return (
 <PortalLayout
 type="customer"
 user={user}
 onLogout={handleLogout}
 title="Customer Portal"
 subtitle="Orders, Invoices & Account Details"
 >
 <Outlet context={user} />
 </PortalLayout>
 );
}
