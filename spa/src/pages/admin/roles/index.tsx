import { PageHeader } from '@/components/layout/PageHeader';
import { EmptyState } from '@/components/ui/EmptyState';

/**
 * Stub — full role list lands in Task 10.
 */
export default function RolesIndexPage() {
  return (
    <div>
      <PageHeader title="Roles" subtitle="Manage roles and their permissions" />
      <EmptyState icon="lock" title="Coming in Task 10" description="Role and permission management will appear here." />
    </div>
  );
}
