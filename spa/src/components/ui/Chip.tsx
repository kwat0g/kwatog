import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type ChipVariant =
  | 'success'
  | 'warning'
  | 'danger'
  | 'info'
  | 'neutral'
  | 'purple';

interface ChipProps {
  variant?: ChipVariant;
  className?: string;
  children: ReactNode;
}

const variants: Record<ChipVariant, string> = {
  success: 'bg-success-bg text-success-fg',
  warning: 'bg-warning-bg text-warning-fg',
  danger:  'bg-danger-bg  text-danger-fg',
  info:    'bg-info-bg    text-info-fg',
  purple:  'bg-purple-bg  text-purple-fg',
  neutral: 'bg-elevated   text-muted',
};

export function Chip({ variant = 'neutral', className, children }: ChipProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center px-1.5 py-0.5 rounded-sm text-xs font-medium leading-tight whitespace-nowrap',
        variants[variant],
        className,
      )}
    >
      {children}
    </span>
  );
}

/**
 * Maps a status string to a chip variant.
 * Source: docs/DESIGN-SYSTEM.md status → variant table.
 */
export function chipVariantForStatus(status: string | null | undefined): ChipVariant {
  switch (status) {
    case 'completed':
    case 'approved':
    case 'active':
    case 'passed':
    case 'running':
    case 'paid':
    case 'delivered':
    case 'confirmed':
      return 'success';
    case 'in_production':
    case 'in_progress':
    case 'processing':
    case 'scheduled':
    case 'on_leave':
    case 'in_transit':
      return 'info';
    case 'pending':
    case 'pending_dept':
    case 'pending_hr':
    case 'draft':
    case 'queued':
    case 'idle':
    case 'setup':
    case 'partial':
    case 'partially_received':
      return 'warning';
    case 'rejected':
    case 'failed':
    case 'breakdown':
    case 'overdue':
    case 'urgent':
    case 'material_short':
    case 'terminated':
    case 'unpaid':
      return 'danger';
    case 'cancelled':
    case 'inactive':
    case 'closed':
    case 'resigned':
    case 'retired':
    default:
      return 'neutral';
  }
}
