import { useEffect, useState, useCallback } from 'react';
import { Outlet, useNavigate } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import type { CustomerPortalUser } from '@/types/b2b';
import PortalLayout from './PortalLayout';
import { FullPageLoader } from '@/components/ui/Spinner';

export default function CustomerPortalLayout() {
  const navigate = useNavigate();
  const [user, setUser] = useState<CustomerPortalUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    customerPortalApi.me()
      .then((u) => { if (!cancelled) setUser(u); })
      .catch(() => { if (!cancelled) navigate('/portal/customer/login', { replace: true }); })
      .finally(() => { if (!cancelled) setIsLoading(false); });
    return () => { cancelled = true; };
  }, [navigate]);

  const handleLogout = useCallback(async () => {
    try { await customerPortalApi.logout(); } finally { navigate('/portal/customer/login', { replace: true }); }
  }, [navigate]);

  if (isLoading) return <FullPageLoader />;
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
