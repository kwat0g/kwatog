import { lazy, Suspense, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Outlet, useNavigate } from 'react-router-dom';
import { CircleHelp } from 'lucide-react';
import toast from 'react-hot-toast';
import { Topbar } from '@/components/layout/Topbar';
import { Sidebar } from '@/components/layout/Sidebar';
import { DevErrorPanel } from '@/components/dev/DevErrorPanel';
import { OfflineBanner } from '@/components/ui/OfflineBanner';
import { useAuthStore } from '@/stores/authStore';
import { PageActionsProvider } from '@/contexts/PageActionsContext';
import { useKeyboardShortcuts } from '@/hooks/useKeyboardShortcuts';
import { usePermissionSync } from '@/hooks/usePermissionSync';
import { usePageFocus } from '@/hooks/usePageFocus';
import { useRecentPageTracker } from '@/hooks/useRecentPageTracker';

const KeyboardShortcutHelp = lazy(() =>
 import('@/components/ui/KeyboardShortcutHelp').then((m) => ({ default: m.KeyboardShortcutHelp })),
);
import { RouteTransition } from '@/components/ui/RouteTransition';
import { Tooltip } from '@/components/ui/Tooltip';
import { Button } from '@/components/ui/Button';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { setFunctionalCurrency } from '@/lib/runtimeCurrency';

// Inner component so the keyboard-shortcut hook lives inside the
// PageActionsProvider (otherwise the dispatcher context is null).
function AppLayoutInner() {
 const navigate = useNavigate();
 const user = useAuthStore((s) => s.user);
 const permissions = useAuthStore((s) => s.permissions);
 const features = useAuthStore((s) => s.features);
 const logout = useAuthStore((s) => s.logout);
 const { helpOpen, setHelpOpen } = useKeyboardShortcuts();
 const { data: businessPolicies } = useQuery({ queryKey: ['business-policies'], queryFn: businessPoliciesApi.get });

 useEffect(() => {
 setFunctionalCurrency(businessPolicies?.functional_currency_code);
 }, [businessPolicies?.functional_currency_code]);

 // Listen for real-time permission and module toggle changes
 usePermissionSync();

 // Move focus to new page content after route transitions (a11y)
 usePageFocus();

 // Feed page visits into the ⌘K palette's Recent group
 useRecentPageTracker();

 const onLogout = async () => {
 await logout();
 toast.success('Signed out.');
 navigate('/login', { replace: true });
 };

 return (
 <div className="min-h-screen bg-canvas text-primary flex flex-col">
 <a
 href="#main-content"
 className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[100] focus:px-3 focus:py-1.5 focus:rounded-md focus:bg-accent focus:text-accent-fg focus:text-sm"
 >
 Skip to content
 </a>
 <OfflineBanner />
 <Topbar
 user={user ? { name: user.name, email: user.email } : null}
 onLogout={onLogout}
 rightExtras={
 <Tooltip content="Keyboard shortcuts (?)">
 <Button
 variant="ghost"
 size="sm"
 iconOnly
 icon={<CircleHelp size={14} />}
 aria-label="Keyboard shortcuts"
 onClick={() => setHelpOpen(true)}
 className="text-muted hover:text-primary"
 />
 </Tooltip>
 }
 />
 <div className="flex flex-1">
 <Sidebar permissions={permissions} features={features} roleSlug={user?.role?.slug} />
 <main id="main-content" tabIndex={-1} className="flex-1 min-w-0">
 {/* Route content stays immediate; RouteTransition is a semantic wrapper only. */}
 <RouteTransition>
 <Outlet />
 </RouteTransition>
 </main>
 </div>
 <DevErrorPanel />
 <Suspense fallback={null}>
 <KeyboardShortcutHelp open={helpOpen} onClose={() => setHelpOpen(false)} />
 </Suspense>
 </div>
 );
}

export function AppLayout() {
 return (
 <PageActionsProvider>
 <AppLayoutInner />
 </PageActionsProvider>
 );
}

export default AppLayout;
