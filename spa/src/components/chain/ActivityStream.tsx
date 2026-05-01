import { type ActivityItem } from '@/types/chain';
import { cn } from '@/lib/cn';

interface ActivityStreamProps {
  items: ActivityItem[];
  className?: string;
}

const dotColor = {
  success: 'bg-success',
  info: 'bg-info',
  warning: 'bg-warning',
  danger: 'bg-danger',
  neutral: 'bg-strong',
} as const;

export function ActivityStream({ items, className }: ActivityStreamProps) {
  return (
    <ol className={cn('flex flex-col', className)}>
      {items.map((item, i) => (
        <li key={i} className="flex items-start gap-2.5 py-1.5">
          <span className={cn('h-1.5 w-1.5 rounded-full mt-1.5 shrink-0', dotColor[item.dot])} />
          <div className="min-w-0">
            <div className="text-xs text-primary">{item.text}</div>
            <div className="text-2xs font-mono tabular-nums text-muted mt-0.5">{item.time}</div>
          </div>
        </li>
      ))}
    </ol>
  );
}
