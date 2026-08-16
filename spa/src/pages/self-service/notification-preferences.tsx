/**
 * Sprint 8 — Task 77. Per-user notification preferences.
 *
 * Rows = notification types, grouped by the chain they belong to.
 * Columns = in_app, email. Toggling a switch persists immediately via PUT.
 *
 * Desktop layout: a real table with a sticky header and per-column
 * enable/disable-all controls, plus a search box — 23 rows of switches is a
 * grid to scan, not a phone settings stack.
 */
import { useMemo, useState, type ChangeEvent } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { LuSearch } from '@/lib/icons';
import toast from 'react-hot-toast';
import { client } from '@/api/client';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { LinkButton } from '@/components/ui/LinkButton';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { Switch } from '@/components/ui/Switch';
import { Td, Th, tableCls, theadTrCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';

type NotificationTypeDef = { key: string; label: string; description: string };
type NotificationGroup = { title: string; hint: string; types: NotificationTypeDef[] };

type Channel = 'in_app' | 'email' | 'digest';
type Pref = { notification_type: string; channel: Channel; enabled: boolean };

// REC-06 — the daily digest is a single global opt-in row (type '*', channel
// 'digest') consumed by NotificationDigestService's scheduled 07:05 run.
const DIGEST_TYPE = '*';

/**
 * Default state when the user has no preference row for a channel. Mirrors
 * NotificationService: in-app fires unless explicitly disabled
 * (`isChannelEnabled`), while email and the digest are opt-IN and stay silent
 * until an enabled row exists (`isEmailChannelEnabled`). Showing email as ON
 * by default would promise mail the backend never sends.
 */
const CHANNEL_DEFAULT: Record<Channel, boolean> = {
 in_app: true,
 email: false,
 digest: false,
};

export default function NotificationPreferencesPage() {
 const qc = useQueryClient();
 const [search, setSearch] = useState('');

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['notification-preferences'],
 queryFn: () => client.get<{ data: Pref[] }>('/notification-preferences').then(r => r.data.data),
 });
 const { data: catalog } = useQuery({
 queryKey: ['notification-preferences', 'options'],
 queryFn: () => client.get<{ data: { groups: NotificationGroup[] } }>('/notification-preferences/options').then((r) => r.data.data),
 staleTime: 5 * 60 * 1000,
 });
 const catalogGroups = useMemo(() => catalog?.groups ?? [], [catalog?.groups]);
 const allTypes = catalogGroups.flatMap((group) => group.types);

 const upsert = useMutation({
 mutationFn: (preferences: Pref[]) =>
 client.put('/notification-preferences', { preferences }),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['notification-preferences'] });
 },
 onError: () => toast.error('Failed to save preferences. Please try again.'),
 });

 const isEnabled = (type: string, channel: Channel) =>
 data?.find(p => p.notification_type === type && p.channel === channel)?.enabled
 ?? CHANNEL_DEFAULT[channel];

 const onToggle = (type: string, channel: Channel, enabled: boolean) => {
 upsert.mutate([{ notification_type: type, channel, enabled }]);
 };

 // Switch is a controlled <input type="checkbox"> — onChange yields an event.
 const handleSwitch = (type: string, channel: Channel) =>
 (e: ChangeEvent<HTMLInputElement>) => onToggle(type, channel, e.target.checked);

 /** Flip an entire column in one PUT rather than 23 round-trips. */
 const setColumn = (channel: 'in_app' | 'email', enabled: boolean) => {
 upsert.mutate(
 allTypes.map((t) => ({ notification_type: t.key, channel, enabled })),
 );
 };

 const groups = useMemo(() => {
 const q = search.trim().toLowerCase();
 if (!q) return catalogGroups;
 return catalogGroups
 .map((g) => ({
 ...g,
 types: g.types.filter(
 (t) => t.label.toLowerCase().includes(q) || t.description.toLowerCase().includes(q),
 ),
 }))
 .filter((g) => g.types.length > 0);
 }, [catalogGroups, search]);

 const matchCount = groups.reduce((n, g) => n + g.types.length, 0);

 const enabledCount = (channel: 'in_app' | 'email') =>
 allTypes.filter((t) => isEnabled(t.key, channel)).length;

 return (
 <div>
 <PageHeader
 title="Notification Preferences"
 subtitle={
 data
 ? `${enabledCount('in_app')} in-app · ${enabledCount('email')} email of ${allTypes.length} types`
 : 'Choose which events reach you, and where'
 }
 backTo="/self-service/profile"
 backLabel="Profile"
 />

 <div className="px-5 py-4 space-y-4">
 {/* LOADING */}
 {isLoading && !data && (
 <div className="space-y-4">
 <SkeletonBlock className="h-16 rounded-md" />
 <SkeletonBlock className="h-96 rounded-md" />
 </div>
 )}

 {/* ERROR */}
 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Couldn't load your preferences"
 description="An error occurred while loading your notification settings. Please try again."
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}

 {data && (
 <>
 {/* REC-06 — daily email digest opt-in (global, all unread types). */}
 <Panel title="Daily email digest">
 <div className="flex items-start justify-between gap-4">
 <p className="text-xs text-muted max-w-2xl">
 One email each morning summarizing your unread notifications.
 Read state is left untouched, and this is independent of the
 per-event settings below.
 </p>
 <Switch
 checked={isEnabled(DIGEST_TYPE, 'digest')}
 onChange={handleSwitch(DIGEST_TYPE, 'digest')}
 aria-label="Enable daily email digest"
 />
 </div>
 </Panel>

 <Panel
 title="Per-event delivery"
 meta={search ? `${matchCount} of ${allTypes.length} shown` : `${allTypes.length} events`}
 noPadding
 actions={
 <div className="w-56">
 <Input
 type="search"
 fieldSize="sm"
 value={search}
 onChange={(e) => setSearch(e.target.value)}
 placeholder="Filter events…"
 aria-label="Filter notification types"
 prefix={<LuSearch size={12} />}
 />
 </div>
 }
 >
 {matchCount === 0 ? (
 <div className="px-4 py-6">
 <EmptyState
 size="compact"
 icon="search"
 title="No matching events"
 description="Try a different search term."
 action={<Button variant="secondary" size="sm" onClick={() => setSearch('')}>Clear</Button>}
 />
 </div>
 ) : (
 <div className="overflow-x-auto">
 <table className={tableCls}>
 <thead className="sticky top-0 z-10 bg-canvas">
 <tr className={theadTrCls}>
 <Th className="w-full">Event</Th>
 <Th align="center" className="w-28 whitespace-nowrap">In-app</Th>
 <Th align="center" className="w-28 whitespace-nowrap">Email</Th>
 </tr>
 <tr className="border-b border-default bg-subtle">
 <Td className="py-1.5 text-2xs text-muted">
 Apply to every event
 </Td>
 {(['in_app', 'email'] as const).map((channel) => (
 <Td key={channel} align="center" className="py-1.5">
 <span className="inline-flex items-center gap-1.5 text-2xs">
 <LinkButton
 onClick={() => setColumn(channel, true)}
 disabled={upsert.isPending}
 className="text-2xs"
 >
 All
 </LinkButton>
 <span className="text-text-subtle" aria-hidden="true">·</span>
 <LinkButton
 tone="muted"
 onClick={() => setColumn(channel, false)}
 disabled={upsert.isPending}
 className="text-2xs"
 >
 None
 </LinkButton>
 </span>
 </Td>
 ))}
 </tr>
 </thead>
 {groups.map((group) => (
 <tbody key={group.title}>
 <tr className="border-b border-subtle bg-subtle/60">
 <th
 colSpan={3}
 scope="colgroup"
 className="h-8 px-2.5 text-left text-2xs uppercase tracking-wider text-muted font-medium"
 >
 {group.title}
 <span className="ml-2 normal-case tracking-normal text-text-subtle font-normal">
 {group.hint}
 </span>
 </th>
 </tr>
 {group.types.map((row) => (
 <tr
 key={row.key}
 className={cn('border-b border-subtle hover:bg-subtle align-top')}
 >
 <Td className="py-2">
 <div className="text-sm text-primary">{row.label}</div>
 <div className="text-xs text-muted">{row.description}</div>
 </Td>
 <Td align="center" className="py-2">
 <Switch
 checked={isEnabled(row.key, 'in_app')}
 onChange={handleSwitch(row.key, 'in_app')}
 aria-label={`Enable in-app notifications for ${row.label}`}
 />
 </Td>
 <Td align="center" className="py-2">
 <Switch
 checked={isEnabled(row.key, 'email')}
 onChange={handleSwitch(row.key, 'email')}
 aria-label={`Enable email notifications for ${row.label}`}
 />
 </Td>
 </tr>
 ))}
 </tbody>
 ))}
 </table>
 </div>
 )}
 </Panel>

 <p className="text-xs text-muted">
 Changes save as you toggle. In-app delivery is on unless you turn
 it off; email and the digest are opt-in and stay silent until
 switched on here.
 </p>
 </>
 )}
 </div>
 </div>
 );
}
