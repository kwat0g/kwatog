import { useState, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { trainingMatrixApi } from '@/api/hr/training-matrix';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Select } from '@/components/ui/Select';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { Tooltip } from '@/components/ui/Tooltip';
import { PageHeader } from '@/components/layout/PageHeader';
import { cn } from '@/lib/cn';
import type { TrainingMatrixCell, TrainingMatrixCellStatus } from '@/types/hr';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { LinkButton } from '@/components/ui/LinkButton';

const STATUS_COLORS: Record<TrainingMatrixCellStatus, string> = {
  trained: 'bg-success-bg border-success',
  expired: 'bg-danger-bg border-danger',
  gap:     'bg-subtle border-default',
};

function levelLabel(level: string | null): string {
  if (!level) return '';
  return level.charAt(0).toUpperCase() + level.slice(1);
}

function cellTooltipContent(cell: TrainingMatrixCell, skillName: string, statusLabels: Map<string, string>): string {
  const parts = [skillName, statusLabels.get(cell.status) ?? cell.status];
  if (cell.level) parts.push(`Level: ${levelLabel(cell.level)}`);
  if (cell.expiry_date) parts.push(`Expires: ${cell.expiry_date}`);
  return parts.join(' · ');
}

export default function TrainingMatrixPage() {
  const [departmentId, setDepartmentId] = useState<string>('');
  const navigate = useNavigate();

  const { data: departments } = useQuery({
    queryKey: ['departments-tree'],
    queryFn: () => departmentsApi.tree(),
    staleTime: 5 * 60 * 1000,
  });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['training-matrix', departmentId],
    queryFn: () => trainingMatrixApi.index(
      departmentId ? { department_id: departmentId } : undefined,
    ),
  });
  const skills = data?.skills;
  const statusLabels = new Map((data?.status_options ?? []).map((option) => [option.value, option.label]));

  // Group skills by category for header display
  const skillCategories = useMemo(() => {
    if (!skills) return [];
    const groups: { category: string; skills: typeof skills }[] = [];
    let currentCategory = '';
    let currentGroup: typeof skills = [];
    for (const skill of skills) {
      const cat = skill.category || 'Uncategorized';
      if (cat !== currentCategory) {
        if (currentGroup.length > 0) {
          groups.push({ category: currentCategory, skills: currentGroup });
        }
        currentCategory = cat;
        currentGroup = [skill];
      } else {
        currentGroup.push(skill);
      }
    }
    if (currentGroup.length > 0) {
      groups.push({ category: currentCategory, skills: currentGroup });
    }
    return groups;
  }, [skills]);

  const coveragePct = data?.summary
    ? data.summary.total_employees * data.summary.total_skills > 0
      ? Math.round(
          (data.summary.trained_count /
            (data.summary.total_employees * data.summary.total_skills)) *
            100,
        )
      : 0
    : 0;

  return (
    <div>
      <PageHeader
        title="Training matrix"
        subtitle="Employee skill competence heatmap (IATF 16949)"
        backTo="/hr/employees"
        backLabel="Employees"
        refreshingQueryKey={['training-matrix', departmentId]}
      />

      {/* Filters */}
      <div className="px-5 py-3 border-b border-default flex flex-wrap items-center gap-3">
        <Select
          value={departmentId}
          onChange={(e) => setDepartmentId(e.target.value)}
          containerClassName="w-56"
        >
          <option value="">All departments</option>
          {departments?.map((d) => (
            <option key={d.id} value={d.id}>{d.name}</option>
          ))}
        </Select>
      </div>

      {/* Summary stats */}
      {data?.summary && (
        <div className="px-5 pt-4 grid grid-cols-2 sm:grid-cols-5 gap-3">
          <StatCard label="Employees" value={data.summary.total_employees} />
          <StatCard label="Skills" value={data.summary.total_skills} />
          <StatCard
            label="Trained"
            value={data.summary.trained_count}
            helper={`${coveragePct}% coverage`}
          />
          <StatCard label="Gaps" value={data.summary.gap_count} />
          <StatCard label="Expired" value={data.summary.expired_count} />
        </div>
      )}

      {/* Loading skeleton */}
      {isLoading && (
        <div className="px-5 py-4">
          <SkeletonBlock className="h-8 w-full mb-2 rounded" />
          {Array.from({ length: 8 }).map((_, i) => (
            <SkeletonBlock key={i} className="h-8 w-full mb-1 rounded" />
          ))}
        </div>
      )}

      {/* Error state */}
      {isError && (
        <div className="px-5 py-5">
          <EmptyState
            icon="alert-circle"
            title="Failed to load training matrix"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        </div>
      )}

      {/* Empty state */}
      {data && data.rows.length === 0 && (
        <div className="px-5 py-5">
          <EmptyState
            icon="grid"
            title="No data"
            description="No active employees or skills found for the selected filters."
          />
        </div>
      )}

      {/* Matrix grid */}
      {data && data.rows.length > 0 && data.skills.length > 0 && (
        <div className="px-5 py-4 overflow-x-auto">
          <table className={tableCls}>
            {/* Category header row */}
            {skillCategories.length > 1 && (
              <thead>
                <tr className={theadTrCls}>
                  <Th className="sticky left-0 z-20 bg-canvas" />
                  <Th className="sticky left-0 z-20 bg-canvas" />
                  {skillCategories.map((group) => (
                    <Th align="center" className="border-b border-default bg-canvas" key={group.category} colSpan={group.skills.length}>
                      {group.category}
                    </Th>
                  ))}
                </tr>
              </thead>
            )}
            <thead>
              <tr className={theadTrCls}>
                <Th className="sticky left-0 z-20 bg-canvas min-w-[180px]">
                  Employee
                </Th>
                <Th className="sticky left-[180px] z-20 bg-canvas min-w-[120px]">
                  Department
                </Th>
                {data.skills.map((skill) => (
                  <Th align="center" className="min-w-[80px] border-b border-default" key={skill.id}>
                    <div className="writing-mode-vertical whitespace-nowrap -rotate-45 origin-bottom-left h-12 flex items-end">
                      <span className="truncate max-w-[100px]" title={skill.name}>
                        {skill.name}
                      </span>
                    </div>
                  </Th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.rows.map((row) => (
                <tr key={row.employee_id} className={trCls}>
                  <Td className="sticky left-0 z-10 bg-canvas border-b border-subtle min-w-[180px]">
                    <LinkButton
                      className="text-left"
                      onClick={() => navigate(`/hr/employees/${row.employee_id}`)}
                    >
                      {row.employee_name}
                    </LinkButton>
                  </Td>
                  <Td className="sticky left-[180px] z-10 bg-canvas border-b border-subtle text-muted min-w-[120px]">
                    {row.department || '—'}
                  </Td>
                  {row.cells.map((cell, idx) => (
                    <Td align="center" className="border-b border-subtle" key={data.skills[idx].id}>
                      <Tooltip
                        side="bottom"
                        content={cellTooltipContent(cell, data.skills[idx].name, statusLabels)}
                      >
                        <span
                          className={cn(
                            'inline-block w-full h-7 rounded border cursor-default transition-colors',
                            STATUS_COLORS[cell.status],
                          )}
                          aria-label={`${data.skills[idx].name}: ${statusLabels.get(cell.status) ?? cell.status}`}
                        >
                          {cell.level && (
                            <span className="text-2xs font-mono leading-7 text-primary/70">
                              {cell.level.charAt(0).toUpperCase()}
                            </span>
                          )}
                        </span>
                      </Tooltip>
                    </Td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>

          {/* Legend */}
          <div className="flex items-center gap-5 mt-4 text-xs text-muted">
            <div className="flex items-center gap-1.5">
              <span className="w-4 h-4 rounded border bg-success-bg border-success" />
              Trained
            </div>
            <div className="flex items-center gap-1.5">
              <span className="w-4 h-4 rounded border bg-danger-bg border-danger" />
              Expired
            </div>
            <div className="flex items-center gap-1.5">
              <span className="w-4 h-4 rounded border bg-subtle border-default" />
              Gap
            </div>
            <div className="text-muted ml-2">
              Cell letter = proficiency: N(ovice) C(ompetent) P(roficient) E(xpert) T(rainer)
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
