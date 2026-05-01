import { Link, useMatches } from 'react-router-dom';
import { ChevronRight } from 'lucide-react';

interface CrumbHandle {
  label?: string | ((data: unknown) => string);
}

export function Breadcrumbs() {
  const matches = useMatches();
  const crumbs = matches
    .filter((m) => Boolean((m.handle as CrumbHandle | null)?.label))
    .map((m) => {
      const handle = m.handle as CrumbHandle;
      const label = typeof handle.label === 'function' ? handle.label(m.data) : handle.label!;
      return { label, to: m.pathname };
    });

  if (crumbs.length === 0) return null;

  return (
    <nav aria-label="Breadcrumb" className="flex items-center gap-1 text-sm">
      {crumbs.map((c, i) => {
        const last = i === crumbs.length - 1;
        return (
          <span key={c.to} className="flex items-center gap-1">
            {i > 0 && <ChevronRight size={12} className="text-text-subtle" />}
            {last ? (
              <span className="text-primary font-medium">{c.label}</span>
            ) : (
              <Link to={c.to} className="text-muted hover:text-primary">
                {c.label}
              </Link>
            )}
          </span>
        );
      })}
    </nav>
  );
}
