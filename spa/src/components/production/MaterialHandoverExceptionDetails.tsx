import { productionDateTime as formatDateTime } from '@/components/production/scheduleDates';
import type { MaterialHandoverException } from '@/types/production';

export function MaterialHandoverExceptionDetails({
  exception,
}: {
  exception?: MaterialHandoverException | null;
}) {
  if (!exception) return null;
  return (
    <div className="mt-2 space-y-1 rounded-md border border-warning p-2 text-xs">
      <p className="font-medium text-warning-fg">Warehouse confirmation pending at recording</p>
      <p>{exception.reason}</p>
      <p className="text-muted">
        Recorded by {exception.manager_name ?? 'Production manager'} ·{' '}
        {formatDateTime(exception.recorded_at)} · {exception.sources.length} pending material lines
      </p>
    </div>
  );
}
