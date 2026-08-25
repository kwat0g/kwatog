/** Sprint 8 — Task 77 + Sprint P4. Notifications page.
 *
 * Polished into a grouped, filterable list:
 * - Filter chips: All / Unread / Approvals / Alerts / System
 * - Rows grouped by date bucket: Today / Yesterday / Earlier this
 * week / Older
 * - Each row shows a type icon (per `lib/notificationMeta`), title,
 * optional message, and a relative time. Unread rows have a 2px
 * indigo left border. Click navigates to `data.link_to` and marks
 * the row read in the same call.
 * - Per-row dismiss, "Clear read" in the header, and Load more paging.
 */
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { LuCheck, LuEye, LuTrash2, LuX } from '@/lib/icons';
import toast from 'react-hot-toast';
import { notificationsApi, type NotificationRow } from '@/api/notifications';
import { Button } from '@/components/ui/Button';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { PageHeader } from '@/components/layout/PageHeader';
import { cn } from '@/lib/cn';
import { focusRingInset } from '@/lib/focus';
import {
 bucketLabel,
 dateBucket,
 notificationMeta,
 timeAgo,
 type NotificationGroup,
} from '@/lib/notificationMeta';

type FilterKey = 'all' | 'unread' | NotificationGroup;

interface FilterDef { key: FilterKey; label: string }

const FILTERS: FilterDef[] = [
 { key: 'all', label: 'All' },
 { key: 'unread', label: 'Unread' },
 { key: 'approvals', label: 'Approvals' },
 { key: 'alerts', label: 'Alerts' },
 { key: 'system', label: 'System' },
];

const PAGE_SIZE = 50;

export default function NotificationsListPage() {
 const qc = useQueryClient();
 const navigate = useNavigate();
 const [filter, setFilter] = useState<FilterKey>('all');
 const unreadOnly = filter === 'unread';

 const resetPaging = (next: FilterKey) => setFilter(next);

 const {
 data,
 isLoading,
 isError,
 isFetchingNextPage,
 hasNextPage,
 fetchNextPage,
 refetch,
 } = useInfiniteQuery({
 queryKey: ['notifications', { filter, unreadOnly }],
 queryFn: ({ pageParam }) =>
 notificationsApi.list({ per_page: PAGE_SIZE, page: pageParam, unread_only: unreadOnly }),
 initialPageParam: 1,
 getNextPageParam: (lastPage) =>
 lastPage.meta.current_page < lastPage.meta.last_page
 ? lastPage.meta.current_page + 1
 : undefined,
 });

 const rows = useMemo(() => data?.pages.flatMap((page) => page.data) ?? [], [data]);
 const meta = data?.pages.at(-1)?.meta;

 const invalidate = () => qc.invalidateQueries({ queryKey: ['notifications'] });

 const markRead = useMutation({
 mutationFn: (id: string) => notificationsApi.markRead(id),
 onSuccess: invalidate,
 onError: () => toast.error('Could not mark that notification read.'),
 });
 const markAll = useMutation({
 mutationFn: () => notificationsApi.markAllRead(),
 onSuccess: invalidate,
 onError: () => toast.error('Could not mark all read.'),
 });
 const dismiss = useMutation({
 mutationFn: (id: string) => notificationsApi.remove(id),
 onSuccess: invalidate,
 onError: () => toast.error('Could not dismiss that notification.'),
 });
 const clearRead = useMutation({
 mutationFn: () => notificationsApi.clearRead(),
 onSuccess: (result) => {
 invalidate();
 toast.success(
 result.deleted === 1
 ? 'Cleared 1 read notification.'
 : `Cleared ${result.deleted} read notifications.`,
 );
 },
 onError: () => toast.error('Could not clear read notifications.'),
 });

 // Apply group filter client-side (filter chips other than All / Unread).
 const visibleRows = useMemo(() => {
 if (filter === 'all' || filter === 'unread') return rows;
 return rows.filter((n) => notificationMeta(n.type).group === filter);
 }, [filter, rows]);

 // Group rows into Today / Yesterday / Earlier / Older buckets.
 const grouped = useMemo(() => {
 const buckets: Record<ReturnType<typeof dateBucket>, NotificationRow[]> = {
 today: [],
 yesterday: [],
 this_week: [],
 older: [],
 };
 for (const row of visibleRows) {
 buckets[dateBucket(row.created_at)].push(row);
 }
 return buckets;
 }, [visibleRows]);

 const handleClickRow = (n: NotificationRow) => {
 if (!n.read_at) markRead.mutate(n.id);
 const link = (n.data?.link_to as string | undefined) ?? null;
 if (link) navigate(link);
 };

 const loadedCount = rows.length;
 const readCount = (meta?.total ?? 0) - (meta?.unread_count ?? 0);

 return (
 <div>
 <PageHeader
 title="Notifications"
 subtitle={
 meta
 ? `${meta.unread_count} unread of ${meta.total} total`
 : undefined
 }
 actions={
 <div className="flex items-center gap-2">
 <Button
 variant="ghost"
 size="sm"
 icon={<LuTrash2 size={14} />}
 onClick={() => clearRead.mutate()}
 loading={clearRead.isPending}
 disabled={readCount <= 0}
 >
 Clear read
 </Button>
 <Button
 variant="secondary"
 size="sm"
 icon={<LuCheck size={14} />}
 onClick={() => markAll.mutate()}
 loading={markAll.isPending}
 disabled={(meta?.unread_count ?? 0) === 0}
 >
 Mark all read
 </Button>
 </div>
 }
 />

 {/* Filter chips */}
 <div className="px-5 py-3 border-b border-default flex items-center">
 <SegmentedControl
 size="sm"
 label="Notification filter"
 value={filter}
 onChange={resetPaging}
 options={FILTERS.map((f) => ({
 value: f.key,
 label: f.label,
 // Only the unread tab carries a number, and only when there is one.
 count: f.key === 'unread' && (meta?.unread_count ?? 0) > 0
 ? meta?.unread_count
 : undefined,
 }))}
 />
 </div>

 <div className="px-5 py-4">
 {/* ─── LOADING ─── */}
 {isLoading && !data && (
 <div className="space-y-2">
 {[1, 2, 3, 4].map((i) => (
 <SkeletonBlock key={i} className="h-12 rounded-md" />
 ))}
 </div>
 )}

 {/* ─── ERROR ─── */}
 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load notifications"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}

 {/* ─── EMPTY ─── */}
 {data && visibleRows.length === 0 && (
 <EmptyState
 icon="bell"
 title={
 filter === 'unread'
 ? 'No unread notifications'
 : filter === 'all'
 ? 'You have no notifications'
 : `No ${FILTERS.find((f) => f.key === filter)?.label.toLowerCase()} notifications`
 }
 />
 )}

 {/* ─── DATA ─── */}
 {data && visibleRows.length > 0 && (
 <div className="space-y-5">
 {(['today', 'yesterday', 'this_week', 'older'] as const).map((bucket) => {
 const rows = grouped[bucket];
 if (rows.length === 0) return null;
 return (
 <section key={bucket}>
 <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
 {bucketLabel(bucket)} · <span className="font-mono tabular-nums">{rows.length}</span>
 </div>
 <ul className="rounded-md border border-default divide-y divide-subtle bg-canvas">
 {rows.map((n) => {
 const meta = notificationMeta(n.type);
 const Icon = meta.icon;
 const title = (n.data?.title as string | undefined) ?? meta.label;
 const message = (n.data?.message as string | undefined) ?? '';
 const isUnread = !n.read_at;
 return (
 <li key={n.id}>
 <div className={cn('w-full flex items-stretch', isUnread && 'border-l-2 border-accent')}>
 <button
 type="button"
 onClick={() => handleClickRow(n)}
 aria-label={message ? `${title}: ${message}` : title}
 className={cn(
 'min-w-0 flex-1 text-left px-3 py-2.5 flex items-start gap-3 hover:bg-elevated transition-colors duration-fast cursor-pointer',
 focusRingInset,
 )}
 >
 <span
 className={cn(
 'shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-md',
 isUnread ? 'bg-accent text-accent-fg' : 'bg-elevated text-muted',
 )}
 >
 <Icon size={13} />
 </span>
 <span className="min-w-0 flex-1">
 <span className="block text-sm">
 <span className={cn(isUnread && 'font-medium text-primary')}>{title}</span>
 <span className="ml-2 text-2xs uppercase tracking-wider text-muted">
 {meta.label}
 </span>
 </span>
 {message && (
 <span className="block text-xs text-muted mt-0.5">{message}</span>
 )}
 <span className="block text-2xs text-muted font-mono tabular-nums mt-0.5">
 {timeAgo(n.created_at)}
 </span>
 </span>
 </button>
 <span className="ml-auto shrink-0 flex items-center gap-0.5 px-2">
 {isUnread && (
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuEye size={14} />}
 aria-label="Mark as read"
 onClick={() => markRead.mutate(n.id)}
 className="text-muted hover:text-primary"
 />
 )}
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuX size={14} />}
 aria-label="Dismiss notification"
 onClick={() => dismiss.mutate(n.id)}
 className="text-muted hover:text-danger-fg"
 />
 </span>
 </div>
 </li>
 );
 })}
 </ul>
 </section>
 );
 })}

 {/* ─── PAGING ─── */}
 {hasNextPage && (
 <div className="flex flex-col items-center gap-1.5 pt-1">
 <Button
 variant="secondary"
 size="sm"
 onClick={() => void fetchNextPage()}
 loading={isFetchingNextPage}
 disabled={isFetchingNextPage}
 >
 Load more
 </Button>
 <span className="text-2xs text-muted font-mono tabular-nums">
 {loadedCount} of {meta?.total}
 </span>
 </div>
 )}
 </div>
 )}
 </div>

 </div>
 );
}
