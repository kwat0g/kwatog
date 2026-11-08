import { useRealtimeStatus } from '@/lib/realtime/context';

const labels = {
  live: 'Live',
  reconnecting: 'Reconnecting',
  offline: 'Offline',
} as const;

const dotClasses = {
  live: 'bg-success',
  reconnecting: 'bg-warning',
  offline: 'bg-danger',
} as const;

export function RealtimeStatusIndicator() {
  const { state, lastUpdatedAt } = useRealtimeStatus();
  const lastUpdated = lastUpdatedAt
    ? new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(lastUpdatedAt)
    : null;
  const label = labels[state];

  return (
    <span
      role="status"
      aria-live="polite"
      aria-label={`Real-time updates: ${label}${lastUpdated ? `. Last updated ${lastUpdated}` : ''}`}
      title={lastUpdated ? `Last updated ${lastUpdated}` : `Real-time updates: ${label}`}
      className="inline-flex h-7 items-center gap-1.5 whitespace-nowrap rounded-md px-2 text-xs text-secondary"
    >
      <span aria-hidden="true" className={`h-1.5 w-1.5 rounded-full ${dotClasses[state]}`} />
      <span>{label}</span>
    </span>
  );
}
