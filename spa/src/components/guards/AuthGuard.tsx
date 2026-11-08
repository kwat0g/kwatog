import { Fragment, useEffect, useRef, type ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { SessionRetry } from './SessionRetry';
import { useAuthStore } from '@/stores/authStore';
import { useSidebarStore } from '@/stores/sidebarStore';
import { FullPageLoader } from '@/components/ui/Spinner';
import { realtimeIdentityKey } from '@/lib/realtime/identity';

interface AuthGuardProps {
 children: ReactNode;
}

export function AuthGuard({ children }: AuthGuardProps) {
 const { isAuthenticated, isLoading, user, bootstrap, bootstrapError } = useAuthStore();
 const location = useLocation();
 const attemptedBootstrap = useRef(false);

 useEffect(() => {
 if (!attemptedBootstrap.current && !isAuthenticated && !user && isLoading && !bootstrapError) {
 attemptedBootstrap.current = true;
 void bootstrap().then(() => {
 const authedUser = useAuthStore.getState().user;
 if (authedUser) {
 useSidebarStore.getState().init(authedUser.sidebar_collapsed, authedUser.sidebar_peek, authedUser.sidebar_expand_all);
 }
 });
 }
 }, [isAuthenticated, user, isLoading, bootstrap, bootstrapError]);

 if (isLoading) return <FullPageLoader />;

 if (bootstrapError) return <SessionRetry />;

 if (!isAuthenticated) {
  return <Navigate to="/sign-in" state={{ from: location.pathname + location.search + location.hash }} replace />;
 }

 if (user?.must_change_password && location.pathname !== '/change-password') {
 return <Navigate to="/change-password" state={{ from: location.pathname + location.search + location.hash }} replace />;
 }

 // Clearing QueryClient removes cached queries, but mounted observers can also
 // retain placeholder data from their previous query key. Remount the guarded
 // page tree whenever the server-derived access scope changes so no previous
 // identity's placeholder or component-local record copy survives the switch.
 return <Fragment key={realtimeIdentityKey(user)}>{children}</Fragment>;
}
