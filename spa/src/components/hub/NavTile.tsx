import { Link } from 'react-router-dom';
import { type LucideIcon } from 'lucide-react';

interface NavTileProps {
  to: string;
  icon: LucideIcon;
  label: string;
  description: string;
}

export function NavTile({ to, icon: Icon, label, description }: NavTileProps) {
  return (
    <Link
      to={to}
      className="rounded-md border border-default bg-canvas p-3 hover:border-accent hover:shadow-sm transition-all group"
    >
      <Icon size={18} className="text-muted group-hover:text-accent mb-2" />
      <p className="text-sm font-medium text-primary">{label}</p>
      <p className="text-xs text-muted mt-0.5 line-clamp-1">{description}</p>
    </Link>
  );
}
