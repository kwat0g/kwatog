import { type ReactNode } from 'react';
import { usePermission } from '@/hooks/usePermission';
import { NotFoundState } from '@/pages/error/NotFound';

interface PermissionGuardProps {
 permission?: string;
 anyOf?: string[];
 children: ReactNode;
}

export function PermissionGuard({ permission, anyOf, children }: PermissionGuardProps) {
 const { can } = usePermission();
 const allowed = permission ? can(permission) : Boolean(anyOf?.some(can));

 if (!allowed) {
 // The backend deliberately answers permission middleware with 403, but a
 // page the current user cannot discover must not reveal whether the route or
 // its records exist. Keep page-level denial visually identical to a 404.
 return <NotFoundState fullPage={false} />;
 }

 return <>{children}</>;
}
