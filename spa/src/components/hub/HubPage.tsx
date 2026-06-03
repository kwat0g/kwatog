import { type ReactNode } from 'react';
import { PageHeader } from '@/components/layout/PageHeader';
import { StatCard } from '@/components/ui/StatCard';
import { type BreadcrumbSegment } from '@/components/ui/Breadcrumb';

export interface HubStat {
  label: string;
  value: ReactNode;
  linkTo?: string;
  delta?: { value: string; direction: 'up' | 'down' | 'neutral' };
}

interface HubPageProps {
  title: string;
  subtitle?: string;
  breadcrumbs?: BreadcrumbSegment[];
  stats?: HubStat[];
  actions?: ReactNode;
  children: ReactNode;
}

export function HubPage({ title, subtitle, breadcrumbs, stats, actions, children }: HubPageProps) {
  return (
    <div>
      <PageHeader title={title} subtitle={subtitle} breadcrumbs={breadcrumbs} actions={actions} />
      {stats && stats.length > 0 && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 px-5 pt-5">
          {stats.map((stat) => (
            <StatCard key={stat.label} label={stat.label} value={stat.value} delta={stat.delta} linkTo={stat.linkTo} />
          ))}
        </div>
      )}
      <div className="px-5 py-5 space-y-5">{children}</div>
    </div>
  );
}
