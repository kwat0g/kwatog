import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface StatCardProps {
  label: string;
  value: ReactNode;
  delta?: { value: string; direction: 'up' | 'down' | 'neutral' };
  helper?: string;
  className?: string;
}

const deltaColor = {
  up: 'text-success',
  down: 'text-danger',
  neutral: 'text-muted',
} as const;

const deltaArrow = { up: '↑', down: '↓', neutral: '·' } as const;

export function StatCard({ label, value, delta, helper, className }: StatCardProps) {
  return (
    <div className={cn('p-3.5 bg-surface border border-default rounded-md', className)}>
      <div className="text-2xs uppercase tracking-wider text-text-subtle font-medium mb-1.5">{label}</div>
      <div className="text-2xl font-medium font-mono tabular-nums text-primary leading-tight">{value}</div>
      {delta && (
        <div className={cn('text-xs font-mono tabular-nums mt-1', deltaColor[delta.direction])}>
          {deltaArrow[delta.direction]} {delta.value}
        </div>
      )}
      {!delta && helper && <div className="text-xs text-muted mt-1">{helper}</div>}
    </div>
  );
}
