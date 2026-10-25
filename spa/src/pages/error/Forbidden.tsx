/**
 * WS-C — 403 page rendered by PermissionGuard / RequirePermission when the
 * user is authenticated but lacks the route-level permission.
 */
import { Link } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';
import { Button } from '@/components/ui/Button';

interface ForbiddenPageProps {
  permission?: string;
}

export default function ForbiddenPage({ permission }: ForbiddenPageProps) {
  return (
    <div className="px-5 py-10 flex flex-col items-center text-center gap-3">
      <span className="h-10 w-10 rounded-full bg-elevated text-muted inline-flex items-center justify-center">
        <ShieldAlert size={20} />
      </span>
      <h1 className="text-lg font-medium">Access denied</h1>
      <p className="text-xs text-muted max-w-md">
        You don&apos;t have permission to view this page.{' '}
        {permission ? (
          <>
            Required permission: <span className="font-mono text-primary">{permission}</span>.
            Ask your administrator to assign it to your role.
          </>
        ) : (
          'Ask your administrator if you believe this is in error.'
        )}
      </p>
      <Link to="/dashboard">
        <Button variant="secondary" size="sm">Back to dashboard</Button>
      </Link>
    </div>
  );
}
