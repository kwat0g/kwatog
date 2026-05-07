import { RoleDashboard } from '@/components/dashboard/RoleDashboard';
import { ChainBottleneckWidget } from '@/components/dashboard/ChainBottleneckWidget';
import { usePermission } from '@/hooks/usePermission';

export default function PpcDashboard() {
  const { can } = usePermission();
  return (
    <>
      <RoleDashboard role="ppc" />
      {/* Series C — Task C5. PPC sees production + inspection bottlenecks. */}
      {can('dashboard.view_bottlenecks') && (
        <div className="px-5 pb-6">
          <ChainBottleneckWidget audience="ppc_head" hideWhenEmpty />
        </div>
      )}
    </>
  );
}
