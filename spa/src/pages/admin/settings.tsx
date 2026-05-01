import { PageHeader } from '@/components/layout/PageHeader';
import { EmptyState } from '@/components/ui/EmptyState';

/**
 * Stub — settings + feature toggles land in Task 12.
 */
export default function SettingsPage() {
  return (
    <div>
      <PageHeader title="Settings" subtitle="Company information and feature toggles" />
      <EmptyState icon="inbox" title="Coming in Task 12" description="Application settings will appear here." />
    </div>
  );
}
