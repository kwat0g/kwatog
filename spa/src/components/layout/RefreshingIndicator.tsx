// Series X / Task X5 — small "Refreshing…" pill rendered in the page header
// while a TanStack Query refetches in the background. Pages do NOT flash
// back to a skeleton; instead the user sees this subtle indicator.

import { Spinner } from '@/components/ui/Spinner';
import { useIsRefreshing } from '@/hooks/useIsRefreshing';

interface Props {
  /** queryKey prefix to watch — same shape as `useQuery({ queryKey })`. */
  queryKey: readonly unknown[];
  /** Override the label (default "Refreshing…"). */
  label?: string;
}

export function RefreshingIndicator({ queryKey, label = 'Refreshing…' }: Props) {
  const refreshing = useIsRefreshing(queryKey);
  if (!refreshing) return null;
  return (
    <span className="inline-flex items-center gap-1.5 text-2xs text-muted ml-2 align-middle">
      <Spinner size="sm" className="text-muted" />
      {label}
    </span>
  );
}
