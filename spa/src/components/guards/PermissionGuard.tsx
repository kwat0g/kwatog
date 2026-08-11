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
 // A denial in a 72-item permission-gated nav is the most common failure in
 // the product, and "Forbidden" alone gives the user no next step. Name the
 // permission so they can quote it, and say who grants it.
 const required = permission ?? anyOf?.join(' or ');
 return (
 <div className="px-5 py-10">
 <EmptyState
 icon="lock"
 title="You don't have access to this page"
 description={
 <>
 Ask your department head or a system administrator to grant it.
 {required && (
 <>
 {' '}
 Required permission: <span className="font-mono text-secondary">{required}</span>
 </>
 )}
 </>
 }
 />
 </div>
 );
 }

 return <>{children}</>;
}
