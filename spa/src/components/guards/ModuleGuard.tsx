import { type ReactNode } from 'react';
import { Outlet } from 'react-router-dom';
import { Lock } from 'lucide-react';
import { useFeature } from '@/hooks/useFeature';
import { EmptyState } from '@/components/ui/EmptyState';

interface ModuleGuardProps {
  module: string;
  children?: ReactNode;
}

export function ModuleGuard({ module, children }: ModuleGuardProps) {
  const enabled = useFeature(module);

  if (!enabled) {
    return (
      <div className="px-5 py-10">
        <EmptyState
          icon="lock"
          title="Module disabled"
          description={`The "${module}" module is not currently enabled for your organization.`}
        />
      </div>
    );
  }

  return <>{children ?? <Outlet />}</>;
}
