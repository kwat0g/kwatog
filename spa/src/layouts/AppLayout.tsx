import { Outlet, useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Topbar } from '@/components/layout/Topbar';
import { Sidebar } from '@/components/layout/Sidebar';
import { DevErrorPanel } from '@/components/dev/DevErrorPanel';
import { useAuthStore } from '@/stores/authStore';

export function AppLayout() {
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const permissions = useAuthStore((s) => s.permissions);
  const features = useAuthStore((s) => s.features);
  const logout = useAuthStore((s) => s.logout);

  const onLogout = async () => {
    await logout();
    toast.success('Signed out.');
    navigate('/login', { replace: true });
  };

  return (
    <div className="min-h-screen bg-canvas text-primary flex flex-col">
      <Topbar user={user ? { name: user.name, email: user.email } : null} onLogout={onLogout} />
      <div className="flex flex-1">
        <Sidebar permissions={permissions} features={features} />
        <main className="flex-1 min-w-0">
          <Outlet />
        </main>
      </div>
      <DevErrorPanel />
    </div>
  );
}

export default AppLayout;
