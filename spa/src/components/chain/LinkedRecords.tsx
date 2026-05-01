import { Link } from 'react-router-dom';
import { type LinkedGroup } from '@/types/chain';
import { Chip } from '@/components/ui/Chip';
import { cn } from '@/lib/cn';

interface LinkedRecordsProps {
  groups: LinkedGroup[];
  className?: string;
}

export function LinkedRecords({ groups, className }: LinkedRecordsProps) {
  return (
    <aside className={cn('flex flex-col gap-3', className)}>
      {groups.map((group) => (
        <section key={group.label}>
          <div className="text-2xs uppercase tracking-wider text-text-subtle font-medium mb-1.5">
            {group.label}
          </div>
          <ul className="flex flex-col gap-1.5">
            {group.items.map((item) => (
              <li key={item.id} className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  {item.href ? (
                    <Link to={item.href} className="text-sm font-mono tabular-nums text-primary hover:text-accent">
                      {item.id}
                    </Link>
                  ) : (
                    <span className="text-sm font-mono tabular-nums text-primary">{item.id}</span>
                  )}
                  {item.meta && <div className="text-xs text-muted leading-tight">{item.meta}</div>}
                </div>
                {item.chip && <Chip variant={item.chip.variant}>{item.chip.text}</Chip>}
              </li>
            ))}
          </ul>
        </section>
      ))}
    </aside>
  );
}
