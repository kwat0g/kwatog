import { type ReactNode } from 'react';
import { usePermission } from '@/hooks/usePermission';
import { NotFoundState } from '@/pages/error/NotFound';

interface PermissionGuardProps {
 permission?: string;
 anyOf?: string[];
 approvalAnyOf?: string[];
 standalone?: boolean;
 children: ReactNode;
}

export function PermissionGuard({ permission, anyOf, approvalAnyOf, standalone = false, children }: PermissionGuardProps) {
 const { can, canApprove } = usePermission();
 const allowed = Boolean((permission && can(permission)) || anyOf?.some(can) || approvalAnyOf?.some(canApprove));

 if (!allowed) {
 // The backend deliberately answers permission middleware with 403, but a
 // page the current user cannot discover must not reveal whether the route or
 // its records exist. Keep page-level denial visually identical to a 404.
 return standalone ? <main><NotFoundState fullPage={false} /></main> : <NotFoundState fullPage={false} />;
 }

 return <>{children}</>;
}
