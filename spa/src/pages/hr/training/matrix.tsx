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

const STATUS_COLORS: Record<TrainingMatrixCellStatus, string> = {
  trained: 'bg-success-bg border-success',
  expired: 'bg-danger-bg border-danger',
  gap:     'bg-muted border-default',
};

const STATUS_LABELS: Record<TrainingMatrixCellStatus, string> = {
  trained: 'Trained',
  expired: 'Expired',
  gap:     'Gap',
};

function levelLabel(level: string | null): string {
  if (!level) return '';
  return level.charAt(0).toUpperCase() + level.slice(1);
}

function cellTooltipContent(cell: TrainingMatrixCell, skillName: string): string {
  const parts = [skillName, STATUS_LABELS[cell.status]];
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

  // Group skills by category for header display
  const skillCategories = useMemo(() => {
    if (!data?.skills) return [];
    const groups: { category: string; skills: typeof data.skills }[] = [];
    let currentCategory = '';
    let currentGroup: typeof data.skills = [];
    for (const skill of data.skills) {
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
  }, [data?.skills]);

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
        <div className="px-5 py-8">
          <EmptyState
            icon="alert-circle"
            title="Failed to load training matrix"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        </div>
      )}

      {/* Empty state */}
      {data && data.rows.length === 0 && (
        <div className="px-5 py-8">
          <EmptyState
            icon="grid-3x3"
            title="No data"
            description="No active employees or skills found for the selected filters."
          />
        </div>
      )}

      {/* Matrix grid */}
      {data && data.rows.length > 0 && data.skills.length > 0 && (
        <div className="px-5 py-4 overflow-x-auto">
          <table className="w-full border-collapse text-xs">
            {/* Category header row */}
            {skillCategories.length > 1 && (
              <thead>
                <tr>
                  <th className="sticky left-0 z-20 bg-canvas" />
                  <th className="sticky left-0 z-20 bg-canvas" />
                  {skillCategories.map((group) => (
                    <th
                      key={group.category}
                      colSpan={group.skills.length}
                      className="px-1 py-1 text-center text-2xs uppercase tracking-wider text-muted font-medium border-b border-default bg-canvas"
                    >
                      {group.category}
                    </th>
                  ))}
                </tr>
              </thead>
            )}
            <thead>
              <tr>
                <th className="sticky left-0 z-20 bg-canvas px-2 py-2 text-left font-medium text-muted min-w-[180px]">
                  Employee
                </th>
                <th className="sticky left-[180px] z-20 bg-canvas px-2 py-2 text-left font-medium text-muted min-w-[120px]">
                  Department
                </th>
                {data.skills.map((skill) => (
                  <th
                    key={skill.id}
                    className="px-1 py-2 text-center font-medium text-muted min-w-[80px] border-b border-default"
                  >
                    <div className="writing-mode-vertical whitespace-nowrap -rotate-45 origin-bottom-left h-12 flex items-end">
                      <span className="truncate max-w-[100px]" title={skill.name}>
                        {skill.name}
                      </span>
                    </div>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.rows.map((row) => (
                <tr key={row.employee_id} className="hover:bg-elevated/50 transition-colors">
                  <td className="sticky left-0 z-10 bg-canvas px-2 py-1.5 border-b border-subtle min-w-[180px]">
                    <button
                      type="button"
                      className="text-accent hover:underline text-left cursor-pointer"
                      onClick={() => navigate(`/hr/employees/${row.employee_id}`)}
                    >
                      {row.employee_name}
                    </button>
                  </td>
                  <td className="sticky left-[180px] z-10 bg-canvas px-2 py-1.5 border-b border-subtle text-muted min-w-[120px]">
                    {row.department || '—'}
                  </td>
                  {row.cells.map((cell, idx) => (
                    <td
                      key={data.skills[idx].id}
                      className="px-0.5 py-0.5 border-b border-subtle text-center"
                    >
                      <Tooltip
                        side="bottom"
                        content={cellTooltipContent(cell, data.skills[idx].name)}
                      >
                        <span
                          className={cn(
                            'inline-block w-full h-7 rounded border cursor-default transition-colors',
                            STATUS_COLORS[cell.status],
                          )}
                          aria-label={`${data.skills[idx].name}: ${STATUS_LABELS[cell.status]}`}
                        >
                          {cell.level && (
                            <span className="text-[9px] font-mono leading-7 text-primary/70">
                              {cell.level.charAt(0).toUpperCase()}
                            </span>
                          )}
                        </span>
                      </Tooltip>
                    </td>
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
              <span className="w-4 h-4 rounded border bg-muted border-default" />
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
