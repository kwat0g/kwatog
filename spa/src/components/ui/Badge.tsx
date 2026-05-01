import { type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface BadgeProps {
  children: ReactNode;
  variant?: 'accent' | 'warning' | 'danger' | 'neutral';
  className?: string;
}

const variants = {
  accent:  'bg-accent text-accent-fg',
  warning: 'bg-warning-bg text-warning-fg',
  danger:  'bg-danger text-white',
  neutral: 'bg-elevated text-muted',
} as const;

export function Badge({ children, variant = 'accent', className }: BadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full text-[10px] font-medium leading-none',
        variants[variant],
        className,
      )}
    >
      {children}
    </span>
  );
}
