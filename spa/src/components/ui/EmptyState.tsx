import { type ReactNode } from 'react';
import {
  AlertCircle,
  Inbox,
  Search,
  Users,
  FileQuestion,
  Lock,
  type LucideIcon,
} from 'lucide-react';
import { cn } from '@/lib/cn';

const ICONS: Record<string, LucideIcon> = {
  'alert-circle': AlertCircle,
  inbox: Inbox,
  search: Search,
  users: Users,
  'file-question': FileQuestion,
  lock: Lock,
};

interface EmptyStateProps {
  icon?: keyof typeof ICONS;
  title: string;
  description?: string;
  action?: ReactNode;
  className?: string;
}

export function EmptyState({ icon = 'inbox', title, description, action, className }: EmptyStateProps) {
  const Icon = ICONS[icon] ?? Inbox;
  return (
    <div className={cn('flex flex-col items-center justify-center py-12 px-6 text-center', className)}>
      <div className="w-10 h-10 rounded-full bg-elevated flex items-center justify-center mb-3 text-muted">
        <Icon size={20} />
      </div>
      <h3 className="text-md font-medium text-primary mb-1">{title}</h3>
      {description && <p className="text-sm text-muted max-w-md mb-4">{description}</p>}
      {action}
    </div>
  );
}
