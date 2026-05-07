import { RoleDashboard } from '@/components/dashboard/RoleDashboard';
import { ChainBottleneckWidget } from '@/components/dashboard/ChainBottleneckWidget';
import { usePermission } from '@/hooks/usePermission';

export default function PlantManagerDashboard() {
  const { can } = usePermission();
  return (
    <>
      <RoleDashboard role="plantManager" />
      {/* Series C — Task C5. Plant Manager sees ALL chain bottlenecks. */}
      {can('dashboard.view_bottlenecks') && (
        <div className="px-5 pb-6">
          <ChainBottleneckWidget hideWhenEmpty />
        </div>
      )}
    </>
  );
}
