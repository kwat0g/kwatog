import { useEffect, type ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { FullPageLoader } from '@/components/ui/Spinner';

interface GuestGuardProps {
  children: ReactNode;
}

/**
 * Restores a cookie-backed session before showing a guest-only auth page.
 *
 * Public routes do not bootstrap auth, so a user can visit the landing page
 * without losing their server session. When they return through /login, this
 * guard checks that existing session and sends them straight back to the ERP.
 */
export function GuestGuard({ children }: GuestGuardProps) {
  const { isAuthenticated, isLoading, user, bootstrap } = useAuthStore();

  useEffect(() => {
    if (!isAuthenticated && !user && isLoading) {
      void bootstrap();
    }
  }, [isAuthenticated, user, isLoading, bootstrap]);

  if (isLoading) return <FullPageLoader />;

  if (isAuthenticated) {
    return <Navigate to={user?.must_change_password ? '/change-password' : '/dashboard'} replace />;
  }

  return <>{children}</>;
}
