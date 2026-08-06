import { type ReactNode } from 'react';
import { usePermission } from '@/hooks/usePermission';
import { EmptyState } from '@/components/ui/EmptyState';

interface PermissionGuardProps {
 permission?: string;
 anyOf?: string[];
 children: ReactNode;
}

export function PermissionGuard({ permission, anyOf, children }: PermissionGuardProps) {
 const { can } = usePermission();
 const allowed = permission ? can(permission) : Boolean(anyOf?.some(can));

 if (!allowed) {
 return (
 <div className="px-5 py-10">
 <EmptyState
 icon="lock"
 title="Forbidden"
 description="You do not have permission to view this page."
 />
 </div>
 );
 }

 return <>{children}</>;
}
