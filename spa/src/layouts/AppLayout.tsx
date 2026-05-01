import { Outlet } from 'react-router-dom';
import { Topbar } from '@/components/layout/Topbar';
import { Sidebar } from '@/components/layout/Sidebar';

interface AppLayoutProps {
  user?: { name: string; email: string } | null;
  permissions?: Set<string>;
  features?: Set<string>;
  onLogout?: () => void;
}

export function AppLayout({ user, permissions, features, onLogout }: AppLayoutProps) {
  return (
    <div className="min-h-screen bg-canvas text-primary flex flex-col">
      <Topbar user={user} onLogout={onLogout} />
      <div className="flex flex-1">
        <Sidebar permissions={permissions} features={features} />
        <main className="flex-1 min-w-0">
          <Outlet />
        </main>
      </div>
    </div>
  );
}

export default AppLayout;
