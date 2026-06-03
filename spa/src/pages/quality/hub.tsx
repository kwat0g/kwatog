import { useQuery } from '@tanstack/react-query';
import { ClipboardCheck, AlertCircle, FileCheck, Search, FileText, BarChart } from 'lucide-react';
import { HubPage, HubCard, NavTile, type HubStat } from '@/components/hub';
import { Chip } from '@/components/ui/Chip';
import { Spinner } from '@/components/ui/Spinner';
import { Link } from 'react-router-dom';
import { dashboardsApi } from '@/api/dashboards';

export default function QualityHubPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['quality', 'hub'],
    queryFn: () => dashboardsApi.quality(),
    refetchInterval: 60_000,
  });

  const openNcrs = data?.kpis?.find((k: any) => k.label === 'Open NCRs')?.value ?? '0';
  const pendingInspections = data?.kpis?.find((k: any) => k.label === 'Pending Inspections')?.value ?? '0';
  const defectRate = data?.kpis?.find((k: any) => k.label === 'Defect Rate')?.value ?? '0';
  const inspectionsMtd = data?.kpis?.find((k: any) => k.label === 'Inspections MTD')?.value ?? '0';

  const stats: HubStat[] = [
    { label: 'Open NCRs', value: openNcrs, linkTo: '/quality/ncrs' },
    { label: 'Pending Inspections', value: pendingInspections, linkTo: '/quality/inspections' },
    { label: 'Defect Rate', value: `${defectRate}%` },
    { label: 'Inspections MTD', value: inspectionsMtd },
  ];

  const openNcrList = (data?.panels?.open_ncrs as any[]) ?? [];
  const inspectionQueue = (data?.panels?.inspection_queue as any[]) ?? [];

  return (
    <HubPage title="Quality Control" subtitle="Inspections, NCRs, and quality assurance" breadcrumbs={[{ label: 'Quality' }]} stats={isLoading ? undefined : stats}>
      {isLoading ? (
        <div className="flex justify-center py-12"><Spinner /></div>
      ) : (
        <>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <HubCard title="Open NCRs" icon={AlertCircle} viewAllHref="/quality/ncrs">
              {openNcrList.length === 0 ? (
                <p className="text-sm text-muted">No open NCRs.</p>
              ) : (
                <div className="space-y-2">
                  {openNcrList.slice(0, 5).map((ncr: any) => (
                    <div key={ncr.id} className="flex items-center justify-between text-sm">
                      <Link to={`/quality/ncrs/${ncr.id}`} className="text-accent hover:underline">{ncr.ncr_no}</Link>
                      <Chip variant={ncr.severity === 'critical' ? 'danger' : ncr.severity === 'major' ? 'warning' : 'info'} >{ncr.severity}</Chip>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>

            <HubCard title="Inspection Queue" icon={ClipboardCheck} viewAllHref="/quality/inspections">
              {inspectionQueue.length === 0 ? (
                <p className="text-sm text-muted">No pending inspections.</p>
              ) : (
                <div className="space-y-2">
                  {inspectionQueue.slice(0, 5).map((inspection: any) => (
                    <div key={inspection.id} className="flex items-center justify-between text-sm">
                      <Link to={`/quality/inspections/${inspection.id}`} className="text-accent hover:underline">{inspection.inspection_no}</Link>
                      <Chip variant={inspection.status === 'pending' ? 'warning' : 'neutral'} >{inspection.status}</Chip>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>
          </div>

          <div>
            <h3 className="text-xs font-medium text-muted uppercase tracking-wider mb-3">All Sections</h3>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
              <NavTile to="/quality/inspections" icon={ClipboardCheck} label="Inspections" description="Quality checks and tests" />
              <NavTile to="/quality/ncrs" icon={AlertCircle} label="NCRs" description="Non-conformance reports" />
              <NavTile to="/quality/inspection-specs" icon={FileCheck} label="Inspection Specs" description="Tolerance standards" />
              <NavTile to="/quality/traceability" icon={Search} label="Traceability" description="Lot and batch tracking" />
              <NavTile to="/quality/ncr-templates" icon={FileText} label="NCR Templates" description="Pre-defined NCR forms" />
              <NavTile to="/quality/analytics" icon={BarChart} label="Analytics Dashboard" description="Pareto and trends" />
            </div>
          </div>
        </>
      )}
    </HubPage>
  );
}
