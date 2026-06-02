import { useEffect, useState, useCallback } from 'react';
import { Outlet, useNavigate } from 'react-router-dom';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type { SupplierPortalUser } from '@/types/b2b';
import PortalLayout from './PortalLayout';
import { FullPageLoader } from '@/components/ui/Spinner';

export default function SupplierPortalLayout() {
  const navigate = useNavigate();
  const [user, setUser] = useState<SupplierPortalUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    supplierPortalApi.me()
      .then((u) => { if (!cancelled) setUser(u); })
      .catch(() => { if (!cancelled) navigate('/portal/supplier/login', { replace: true }); })
      .finally(() => { if (!cancelled) setIsLoading(false); });
    return () => { cancelled = true; };
  }, [navigate]);

  const handleLogout = useCallback(async () => {
    try { await supplierPortalApi.logout(); } finally { navigate('/portal/supplier/login', { replace: true }); }
  }, [navigate]);

  if (isLoading) return <FullPageLoader />;
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
