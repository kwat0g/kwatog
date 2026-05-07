import { RoleDashboard } from '@/components/dashboard/RoleDashboard';
import { ChainBottleneckWidget } from '@/components/dashboard/ChainBottleneckWidget';
import { usePermission } from '@/hooks/usePermission';

export default function AccountingDashboard() {
  const { can } = usePermission();
  return (
    <>
      <RoleDashboard role="accounting" />
      {/* Series C — Task C5. Finance sees AR / AP bottlenecks. */}
      {can('dashboard.view_bottlenecks') && (
        <div className="px-5 pb-6">
          <ChainBottleneckWidget audience="finance_officer" hideWhenEmpty />
        </div>
      )}
    </>
  );
}
