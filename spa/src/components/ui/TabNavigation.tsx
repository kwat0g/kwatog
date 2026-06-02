/**
 * TabNavigation — full-width sticky tab bar for consolidated hub pages.
 *
 * Renders tabs based on the `tab` query parameter. Each tab navigates to the
 * specified path using React Router's `<Link>`. Deep links work:
 * `/hr/hub?tab=departments` activates the Departments tab.
 *
 * Usage:
 * ```tsx
 * <TabNavigation
 *   tabs={[
 *     { key: 'employees', label: 'Employees', to: '/hr/employees' },
 *     { key: 'departments', label: 'Departments', to: '/hr/departments' },
 *   ]}
 * />
 * ```
 */
import { useSearchParams } from 'react-router-dom';
import { Link } from 'react-router-dom';
import { cn } from '@/lib/cn';

export interface Tab {
  key: string;
  label: string;
  to: string;
}

interface TabNavigationProps {
  tabs: Tab[];
  /** Default tab key to highlight when no tab param is present. */
  defaultKey?: string;
  /** Optional className override. */
  className?: string;
}

export function TabNavigation({ tabs, defaultKey, className }: TabNavigationProps) {
  const [searchParams] = useSearchParams();
  const activeKey = searchParams.get('tab') ?? defaultKey ?? tabs[0]?.key;

  return (
    <div className={cn('sticky top-12 z-10 bg-canvas border-b border-default', className)}>
      <nav className="flex gap-0 overflow-x-auto" role="tablist" aria-label="Section tabs">
        {tabs.map((tab) => {
          const isActive = activeKey === tab.key;
          return (
            <Link
              key={tab.key}
              to={tab.to}
              role="tab"
              aria-selected={isActive}
              className={cn(
                'relative flex items-center px-4 py-2.5 text-sm whitespace-nowrap transition-colors duration-fast',
                isActive
                  ? 'text-primary font-medium'
                  : 'text-secondary hover:text-primary hover:bg-subtle',
              )}
            >
              {tab.label}
              {isActive && (
                <span
                  className="absolute bottom-0 left-2 right-2 h-0.5 bg-accent rounded-full"
                  aria-hidden
                />
              )}
            </Link>
          );
        })}
      </nav>
    </div>
  );
}
