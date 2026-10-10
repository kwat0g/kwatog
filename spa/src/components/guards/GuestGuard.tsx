import { useEffect, useRef, type ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { SessionRetry } from './SessionRetry';
import { useAuthStore } from '@/stores/authStore';
import { SkeletonLoginPage } from '@/components/ui/Skeleton';

interface GuestGuardProps {
 children: ReactNode;
}

/**
 * Restores a cookie-backed session before showing a guest-only auth page.
 *
 * Public routes do not bootstrap auth, so a user can visit the landing page
 * without losing their server session. When they return through /sign-in, this
 * guard checks that existing session and sends them straight back to the ERP.
 */
export function GuestGuard({ children }: GuestGuardProps) {
  const location = useLocation();
 const attemptedBootstrap = useRef(false);
  const { isAuthenticated, isLoading, user, bootstrap, bootstrapError } = useAuthStore();
  const hasPortalIntent = (location.state as { realm?: string } | null)?.realm != null;

 useEffect(() => {
 if (!attemptedBootstrap.current && !isAuthenticated && !user && isLoading && !bootstrapError) {
 attemptedBootstrap.current = true;
 void bootstrap();
 }
 }, [isAuthenticated, user, isLoading, bootstrap, bootstrapError]);

 if (isLoading) return <SkeletonLoginPage />;

  if (bootstrapError) return <SessionRetry />;

  if (isAuthenticated && !hasPortalIntent) {
 return <Navigate to={user?.must_change_password ? '/change-password' : '/dashboard'} replace />;
 }

 return <>{children}</>;
}
