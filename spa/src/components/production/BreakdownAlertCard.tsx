/**
 * Sprint 6 — Task 56. Inline alert chip for the dashboard alerts panel.
 * Severity drives the dot color; clicking the link opens the related record.
 */
import { Link } from 'react-router-dom';
import type { ReactNode } from 'react';

interface Props {
  type: string;
  severity: string;
  message: ReactNode;
  link: string;
  time?: string;
}

const dotClass: Record<string, string> = {
  danger: 'bg-danger',
  warning: 'bg-warning',
  info: 'bg-accent',
};

export function BreakdownAlertCard({ type, severity, message, link, time }: Props) {
  return (
    <Link
      to={link}
      className="flex items-start gap-2 py-1.5 hover:bg-subtle rounded-sm px-2 -mx-2 transition-colors"
    >
      <span className={`mt-1 inline-block h-2 w-2 rounded-full ${dotClass[severity] ?? 'bg-elevated'}`} aria-hidden />
      <div className="flex-1 min-w-0">
        <div className="text-xs text-primary truncate">{message}</div>
        <div className="text-2xs font-mono text-muted">
          {type}{time ? ` · ${time}` : ''}
        </div>
      </div>
    </Link>
  );
}
