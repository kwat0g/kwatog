import { portalNotificationsApi } from '@/api/b2b/notifications';
import { customerPortalApi } from '@/api/b2b/customer';
import { supplierPortalApi } from '@/api/b2b/supplier';
import type { CustomerPortalUser, SupplierPortalUser } from '@/types/b2b';
import { useQuery } from '@tanstack/react-query';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import NotificationsListPage from '@/pages/notifications';

export default function PortalNotificationsPage({ type }: { type: 'supplier' | 'customer' }) {
  const {
    data: user,
    isLoading,
    isError,
    refetch,
  } = useQuery<SupplierPortalUser | CustomerPortalUser>({
    queryKey: ['portal', type, 'notification-reader'],
    queryFn: async () => (type === 'supplier' ? supplierPortalApi.me() : customerPortalApi.me()),
  });
  if (isLoading) return <SkeletonDetail />;
  if (isError || !user)
    return (
      <EmptyState
        title="Could not load notifications"
        icon="alert-circle"
        action={<Button onClick={() => void refetch()}>Retry</Button>}
      />
    );
  return (
    <NotificationsListPage
      api={portalNotificationsApi[type]}
      queryKey={['portal', type, user.id, 'notifications']}
      linkPrefix={`/portal/${type}`}
    />
  );
}
