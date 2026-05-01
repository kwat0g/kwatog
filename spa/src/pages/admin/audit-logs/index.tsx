import { PageHeader } from '@/components/layout/PageHeader';
import { EmptyState } from '@/components/ui/EmptyState';

/**
 * Stub — audit log viewer lands in Task 11.
 */
export default function AuditLogsPage() {
  return (
    <div>
      <PageHeader title="Audit logs" subtitle="System activity history" />
      <EmptyState icon="file-question" title="Coming in Task 11" description="Audit log entries will appear here." />
    </div>
  );
}
