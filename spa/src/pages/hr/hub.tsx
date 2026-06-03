import { useQuery } from '@tanstack/react-query';
import { Users, Building, Briefcase, BookUser, UserX, FileEdit, DollarSign } from 'lucide-react';
import { HubPage, HubCard, NavTile, type HubStat } from '@/components/hub';
import { Chip } from '@/components/ui/Chip';
import { Spinner } from '@/components/ui/Spinner';
import { Link } from 'react-router-dom';
import { dashboardsApi } from '@/api/dashboards';
import { profileUpdateRequestsApi } from '@/api/hr/profile-update-requests';

export default function HrHubPage() {
  const { data, isLoading: loadingDash } = useQuery({
    queryKey: ['hr', 'hub'],
    queryFn: () => dashboardsApi.hr(),
    refetchInterval: 60_000,
  });

  const { data: profileRequests, isLoading: loadingReqs } = useQuery({
    queryKey: ['hr', 'profile-requests', 'hub'],
    queryFn: () => profileUpdateRequestsApi.list({ per_page: 5, status: 'pending' }),
    refetchInterval: 60_000,
  });

  const isLoading = loadingDash || loadingReqs;

  const totalEmployees = data?.kpis?.find((k: any) => k.label === 'Total Employees')?.value ?? '0';
  const activeEmployees = data?.kpis?.find((k: any) => k.label === 'Active')?.value ?? '0';
  const onLeave = data?.kpis?.find((k: any) => k.label === 'On Leave')?.value ?? '0';
  const pendingRequests = profileRequests?.meta?.total ?? 0;

  const stats: HubStat[] = [
    { label: 'Total Employees', value: totalEmployees, linkTo: '/hr/employees' },
    { label: 'Active', value: activeEmployees },
    { label: 'On Leave', value: onLeave, linkTo: '/hr/leaves' },
    { label: 'Pending Requests', value: pendingRequests, linkTo: '/hr/profile-requests' },
  ];

  const departmentHeadcount = (data?.panels?.department_headcount as any[]) ?? [];

  return (
    <HubPage title="Human Resources" subtitle="Employee management, departments, and HR operations" breadcrumbs={[{ label: 'HR' }]} stats={isLoading ? undefined : stats}>
      {isLoading ? (
        <div className="flex justify-center py-12"><Spinner /></div>
      ) : (
        <>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <HubCard title="Department Headcount" icon={Building} viewAllHref="/hr/departments">
              {departmentHeadcount.length === 0 ? (
                <p className="text-sm text-muted">No department data.</p>
              ) : (
                <div className="space-y-2">
                  {departmentHeadcount.slice(0, 5).map((dept: any, idx: number) => (
                    <div key={idx} className="flex items-center justify-between text-sm">
                      <span className="text-primary">{dept.name}</span>
                      <span className="font-mono tabular-nums text-muted">{dept.count}</span>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>

            <HubCard title="Recent Profile Requests" icon={FileEdit} viewAllHref="/hr/profile-requests">
              {!profileRequests?.data || profileRequests.data.length === 0 ? (
                <p className="text-sm text-muted">No pending profile requests.</p>
              ) : (
                <div className="space-y-2">
                  {profileRequests.data.slice(0, 5).map((req: any) => (
                    <div key={req.id} className="flex items-center justify-between text-sm">
                      <Link to={`/hr/profile-requests/${req.id}`} className="text-accent hover:underline truncate">{req.field_name || 'Profile Update'}</Link>
                      <Chip variant={req.status === 'pending' ? 'warning' : req.status === 'approved' ? 'success' : 'danger'} >{req.status}</Chip>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>
          </div>

          <div>
            <h3 className="text-xs font-medium text-muted uppercase tracking-wider mb-3">All Sections</h3>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
              <NavTile to="/hr/employees" icon={Users} label="Employees" description="Employee master data" />
              <NavTile to="/hr/departments" icon={Building} label="Departments" description="Organizational units" />
              <NavTile to="/hr/positions" icon={Briefcase} label="Positions" description="Job titles and roles" />
              <NavTile to="/hr/directory" icon={BookUser} label="Directory" description="Employee lookup" />
              <NavTile to="/hr/separations" icon={UserX} label="Separations" description="Resignation and clearance" />
              <NavTile to="/hr/profile-update-requests" icon={FileEdit} label="Profile Requests" description="Employee data change requests" />
              <NavTile to="/hr/loans" icon={DollarSign} label="Loans" description="Company loans and cash advances" />
            </div>
          </div>
        </>
      )}
    </HubPage>
  );
}
