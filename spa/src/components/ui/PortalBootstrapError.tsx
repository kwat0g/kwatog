import { LuTriangleAlert } from '@/lib/icons';
import { Button } from './Button';

interface PortalBootstrapErrorProps {
 title: string;
 onRetry: () => void;
 onSignIn: () => void;
}

export function PortalBootstrapError({ title, onRetry, onSignIn }: PortalBootstrapErrorProps) {
 return (
 <div className="min-h-screen flex items-center justify-center bg-canvas px-5">
 <div className="max-w-md text-center">
 <LuTriangleAlert className="mx-auto mb-3 h-6 w-6 text-warning-fg" aria-hidden="true" />
 <h1 className="text-lg font-medium text-primary">{title}</h1>
 <p className="mt-2 text-sm text-muted">
 We could not load your portal session. Check your connection and try again.
 </p>
 <div className="mt-5 flex items-center justify-center gap-2">
 <Button variant="primary" onClick={onRetry}>Try again</Button>
 <Button variant="ghost" onClick={onSignIn}>Sign in</Button>
 </div>
 </div>
 </div>
 );
}
