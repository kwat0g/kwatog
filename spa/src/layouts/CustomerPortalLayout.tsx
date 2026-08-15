import { useEffect, useState, useCallback } from 'react';
import { Outlet, useNavigate } from 'react-router-dom';
import axios from 'axios';
import { customerPortalApi } from '@/api/b2b/customer';
import { queryClient } from '@/lib/queryClient';
import type { CustomerPortalUser } from '@/types/b2b';
import PortalLayout from './PortalLayout';
import { FullPageLoader } from '@/components/ui/Spinner';
import { PortalBootstrapError } from '@/components/ui/PortalBootstrapError';

export default function CustomerPortalLayout() {
 const navigate = useNavigate();
 const [user, setUser] = useState<CustomerPortalUser | null>(null);
 const [isLoading, setIsLoading] = useState(true);
 const [bootstrapError, setBootstrapError] = useState(false);
 const [retryCount, setRetryCount] = useState(0);

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
 navigate('/portal/customer/login', { replace: true });
 } else {
 setBootstrapError(true);
 }
 })
 .finally(() => { if (!cancelled) setIsLoading(false); });
 return () => { cancelled = true; };
 }, [navigate, retryCount]);

 const handleLogout = useCallback(async () => {
 try { await customerPortalApi.logout(); } finally { queryClient.clear(); navigate('/portal/customer/login', { replace: true }); }
 }, [navigate]);

 if (isLoading) return <FullPageLoader />;
 if (bootstrapError) {
 return (
 <PortalBootstrapError
 title="Customer portal unavailable"
 onRetry={() => setRetryCount((count) => count + 1)}
 onSignIn={() => navigate('/portal/customer/login', { replace: true })}
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
 <Outlet />
 </PortalLayout>
 );
}
