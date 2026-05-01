import { PageHeader } from '@/components/layout/PageHeader';
import { EmptyState } from '@/components/ui/EmptyState';

/**
 * Stub — permission matrix lands in Task 10.
 */
export default function RolePermissionsPage() {
  return (
    <div>
      <PageHeader title="Permissions" backTo="/admin/roles" backLabel="Roles" />
      <EmptyState icon="lock" title="Coming in Task 10" description="Permission matrix will appear here." />
    </div>
  );
}
