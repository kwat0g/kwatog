import { useEffect, useState, useCallback } from 'react';
import { Outlet, useNavigate } from 'react-router-dom';
import axios from 'axios';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { queryClient } from '@/lib/queryClient';
import type { SupplierPortalUser } from '@/types/b2b';
import PortalLayout from './PortalLayout';
import { FullPageLoader } from '@/components/ui/Spinner';
import { PortalBootstrapError } from '@/components/ui/PortalBootstrapError';

export default function SupplierPortalLayout() {
 const navigate = useNavigate();
 const [user, setUser] = useState<SupplierPortalUser | null>(null);
 const [isLoading, setIsLoading] = useState(true);
 const [bootstrapError, setBootstrapError] = useState(false);
 const [retryCount, setRetryCount] = useState(0);

 useEffect(() => {
 let cancelled = false;
 setIsLoading(true);
 setBootstrapError(false);
 supplierPortalApi.me()
  .then((u) => {
  if (cancelled) return;
  if (u.must_change_password) {
  navigate('/portal/supplier/change-password', { replace: true });
  return;
  }
  setUser(u);
  })
 .catch((error: unknown) => {
 if (cancelled) return;
 if (axios.isAxiosError(error) && error.response?.status === 401) {
 navigate('/portal/supplier/login', { replace: true });
 } else {
 setBootstrapError(true);
 }
 })
 .finally(() => { if (!cancelled) setIsLoading(false); });
 return () => { cancelled = true; };
 }, [navigate, retryCount]);

 const handleLogout = useCallback(async () => {
 try { await supplierPortalApi.logout(); } finally { queryClient.clear(); navigate('/portal/supplier/login', { replace: true }); }
 }, [navigate]);

 if (isLoading) return <FullPageLoader />;
 if (bootstrapError) {
 return (
 <PortalBootstrapError
 title="Supplier portal unavailable"
 onRetry={() => setRetryCount((count) => count + 1)}
 onSignIn={() => navigate('/portal/supplier/login', { replace: true })}
 />
 );
 }
 if (!user) return null;

 return (
 <PortalLayout
 type="supplier"
 user={user}
 onLogout={handleLogout}
 title="Supplier Portal"
 subtitle="Purchase Orders, Invoices & Deliveries"
 >
 <Outlet />
 </PortalLayout>
 );
}
