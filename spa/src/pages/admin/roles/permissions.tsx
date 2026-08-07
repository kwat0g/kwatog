import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import {
 Save,
 ChevronDown,
 ChevronRight,
 Lock,
 Search,
 CheckSquare,
 Square,
 Eye,
 RotateCcw,
 Sparkles,
 SlidersHorizontal,
 PlusCircle,
 MinusCircle,
 X,
 Check,
} from 'lucide-react';
import { rolesApi } from '@/api/admin/roles';
import { formatDateTime } from '@/lib/formatDate';
import { permissionsApi, type PermissionRow } from '@/api/admin/permissions';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { cn } from '@/lib/cn';
import { focusRingInset } from '@/lib/focus';

type StatusFilter = 'all' | 'granted' | 'ungranted' | 'modified';

function getActionBadge(slug: string) {
 const lower = slug.toLowerCase();
 if (lower.includes('delete') || lower.includes('destroy') || lower.includes('remove')) {
 return { label: 'DELETE', variant: 'danger' as const };
 }
 if (lower.includes('create') || lower.includes('add') || lower.includes('store')) {
 return { label: 'CREATE', variant: 'info' as const };
 }
 if (lower.includes('edit') || lower.includes('update') || lower.includes('modify')) {
 return { label: 'EDIT', variant: 'warning' as const };
 }
 if (lower.includes('view') || lower.includes('read') || lower.includes('index') || lower.includes('show')) {
 return { label: 'VIEW', variant: 'success' as const };
 }
 if (lower.includes('approve') || lower.includes('finalize') || lower.includes('post') || lower.includes('override')) {
 return { label: 'APPROVE', variant: 'purple' as const };
 }
 return { label: 'ACCESS', variant: 'neutral' as const };
}

export default function RolePermissionsPage() {
 const { id = '' } = useParams<{ id: string }>();
 const navigate = useNavigate();
 const queryClient = useQueryClient();

 const role = useQuery({
 queryKey: ['admin', 'role', id],
 queryFn: () => rolesApi.show(id),
 });
 const matrix = useQuery({
 queryKey: ['admin', 'permissions', 'matrix'],
 queryFn: permissionsApi.matrix,
 });

 const [selected, setSelected] = useState<Set<string>>(new Set());
 const [baseline, setBaseline] = useState<Set<string>>(new Set());
 const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
 const [search, setSearch] = useState('');
 const [moduleFilter, setModuleFilter] = useState<string>('all');
 const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
 const [showDiffDrawer, setShowDiffDrawer] = useState(false);

 // Initialize selection once role data lands.
 useEffect(() => {
 if (role.data?.permissions) {
 const slugs = role.data.permissions.map((p) => p.slug);
 setSelected(new Set(slugs));
 setBaseline(new Set(slugs));
 }
 }, [role.data]);

 const totalPermissions = useMemo(
 () => Object.values(matrix.data ?? {}).reduce((sum, arr) => sum + arr.length, 0),
 [matrix.data],
 );

 // Map of slug -> PermissionRow for quick metadata lookup in diffs
 const permissionBySlug = useMemo(() => {
 const map = new Map<string, PermissionRow>();
 if (!matrix.data) return map;
 for (const perms of Object.values(matrix.data)) {
 for (const p of perms) {
 map.set(p.slug, p);
 }
 }
 return map;
 }, [matrix.data]);

 // Diff calculation
 const diff = useMemo(() => {
 const addedSlugs: string[] = [];
 const removedSlugs: string[] = [];
 selected.forEach((s) => {
 if (!baseline.has(s)) addedSlugs.push(s);
 });
 baseline.forEach((s) => {
 if (!selected.has(s)) removedSlugs.push(s);
 });
 return {
 added: addedSlugs,
 removed: removedSlugs,
 total: addedSlugs.length + removedSlugs.length,
 };
 }, [selected, baseline]);

 const isSystem = !!role.data?.is_system;
 const isSystemAdmin = role.data?.slug === 'system_admin';

 const toggleSlug = (slug: string) => {
 if (isSystem) return;
 setSelected((prev) => {
 const next = new Set(prev);
 if (next.has(slug)) next.delete(slug);
 else next.add(slug);
 return next;
 });
 };

 const setModulePermissions = (module: string, mode: 'all' | 'none' | 'view_only') => {
 if (isSystem || !matrix.data?.[module]) return;
 const modulePerms = matrix.data[module];
 setSelected((prev) => {
 const next = new Set(prev);
 modulePerms.forEach((p) => {
 if (mode === 'all') {
 next.add(p.slug);
 } else if (mode === 'none') {
 next.delete(p.slug);
 } else if (mode === 'view_only') {
 const isView = ['view', 'read', 'index', 'show'].some((v) => p.slug.toLowerCase().includes(v));
 if (isView) next.add(p.slug);
 else next.delete(p.slug);
 }
 });
 return next;
 });
 };

 const toggleCollapsed = (module: string) => {
 setCollapsed((prev) => {
 const next = new Set(prev);
 if (next.has(module)) next.delete(module);
 else next.add(module);
 return next;
 });
 };

 const expandAllModules = () => setCollapsed(new Set());
 const collapseAllModules = () => {
 if (!matrix.data) return;
 setCollapsed(new Set(Object.keys(matrix.data)));
 };

 const applyPreset = (preset: 'read_only' | 'standard_editor' | 'full_access' | 'clear_all') => {
 if (isSystem || !matrix.data) return;
 setSelected((prev) => {
 const next = new Set(prev);
 for (const perms of Object.values(matrix.data)) {
 for (const p of perms) {
 const lower = p.slug.toLowerCase();
 if (preset === 'clear_all') {
 next.delete(p.slug);
 } else if (preset === 'full_access') {
 next.add(p.slug);
 } else if (preset === 'read_only') {
 const isView = ['view', 'read', 'index', 'show'].some((k) => lower.includes(k));
 if (isView) next.add(p.slug);
 else next.delete(p.slug);
 } else if (preset === 'standard_editor') {
 const isDestructive = ['delete', 'destroy', 'override', 'manage'].some((k) => lower.includes(k));
 if (!isDestructive) next.add(p.slug);
 else next.delete(p.slug);
 }
 }
 }
 return next;
 });
 toast.success(
 preset === 'read_only'
 ? 'Applied Read-Only preset.'
 : preset === 'standard_editor'
 ? 'Applied Standard Editor preset.'
 : preset === 'full_access'
 ? 'Granted all permissions.'
 : 'Cleared all permissions.',
 );
 };

 const resetToBaseline = () => {
 setSelected(new Set(baseline));
 toast.success('Reverted unsaved permission changes.');
 };

 const save = useMutation({
 mutationFn: () => rolesApi.syncPermissions(id, Array.from(selected)),
 onSuccess: () => {
 toast.success(
 diff.total === 0
 ? 'Permissions saved.'
 : `Permissions saved (${diff.added.length} added, ${diff.removed.length} removed).`,
 );
 setBaseline(new Set(selected));
 setShowDiffDrawer(false);
 queryClient.invalidateQueries({ queryKey: ['admin', 'role', id] });
 queryClient.invalidateQueries({ queryKey: ['admin', 'roles'] });
 },
 onError: (err: { response?: { data?: { message?: string } } }) => {
 toast.error(err?.response?.data?.message ?? 'Failed to save permissions.');
 },
 });

 // Filtered matrix computation
 const matrixData = matrix.data;
 const visibleMatrix = useMemo(() => {
 if (!matrixData) return {} as Record<string, PermissionRow[]>;
 const q = search.trim().toLowerCase();
 const out: Record<string, PermissionRow[]> = {};

 for (const [module, perms] of Object.entries(matrixData)) {
 if (moduleFilter !== 'all' && moduleFilter !== module) continue;

 const filtered = perms.filter((p) => {
 // Search query filter
 const matchesQuery = !q || p.slug.toLowerCase().includes(q) || p.name.toLowerCase().includes(q) || (p.description && p.description.toLowerCase().includes(q));
 if (!matchesQuery) return false;

 // Status filter
 const isSelected = selected.has(p.slug);
 const isBaseline = baseline.has(p.slug);
 const isModified = isSelected !== isBaseline;

 if (statusFilter === 'granted') return isSelected;
 if (statusFilter === 'ungranted') return !isSelected;
 if (statusFilter === 'modified') return isModified;
 return true;
 });

 if (filtered.length > 0) out[module] = filtered;
 }
 return out;
 }, [matrixData, search, moduleFilter, statusFilter, selected, baseline]);

 // Bulk toggle for all visible filtered permissions
 const visibleSlugs = useMemo(() => {
 const slugs: string[] = [];
 for (const perms of Object.values(visibleMatrix)) {
 for (const p of perms) slugs.push(p.slug);
 }
 return slugs;
 }, [visibleMatrix]);

 const allVisibleSelected = useMemo(
 () => visibleSlugs.length > 0 && visibleSlugs.every((s) => selected.has(s)),
 [visibleSlugs, selected],
 );

 const toggleAllVisible = () => {
 if (isSystem || visibleSlugs.length === 0) return;
 const shouldSelectAll = !allVisibleSelected;
 setSelected((prev) => {
 const next = new Set(prev);
 visibleSlugs.forEach((s) => (shouldSelectAll ? next.add(s) : next.delete(s)));
 return next;
 });
 };

 return (
 <div>
 <PageHeader
 title={role.data ? `${role.data.name} permissions` : 'Permissions'}
 subtitle={
 role.data ? (
 <span className="flex items-center gap-2 flex-wrap">
 <Chip variant={isSystem ? 'info' : 'neutral'}>{isSystem ? 'System' : 'Custom'}</Chip>
 <span className="text-muted">Configure & manage operational access control for this role.</span>
 {diff.total > 0 && (
 <button
 type="button"
 onClick={() => setShowDiffDrawer((v) => !v)}
 className="inline-flex items-center gap-1.5 cursor-pointer"
 >
 <Chip variant="warning">
 {diff.total} change{diff.total === 1 ? '' : 's'} unsaved (+{diff.added.length} / −{diff.removed.length})
 </Chip>
 </button>
 )}
 {role.data.last_modified_by && (
 <span className="text-2xs text-muted ml-2">
 Last modified by{' '}
 <span className="text-secondary">{role.data.last_modified_by}</span>
 {role.data.last_modified_at ? (
 <>
 {' '}on{' '}
 <span className="font-mono">{formatDateTime(role.data.last_modified_at)}</span>
 </>
 ) : null}
 </span>
 )}
 </span>
 ) : undefined
 }
 backTo="/admin/roles"
 backLabel="Roles"
 breadcrumbs={[
 { label: 'Admin', href: '/admin' },
 { label: 'Roles', href: '/admin/roles' },
 { label: role.data ? `${role.data.name} permissions` : 'Permissions' },
 ]}
 actions={
 <>
 {diff.total > 0 && (
 <Button
 variant="secondary"
 size="sm"
 icon={<RotateCcw size={14} />}
 onClick={resetToBaseline}
 disabled={isSystem || save.isPending}
 >
 Reset changes
 </Button>
 )}
 <Button variant="secondary" size="sm" onClick={() => navigate('/admin/roles')}>
 Cancel
 </Button>
 <Button
 variant="primary"
 size="sm"
 icon={<Save size={14} />}
 loading={save.isPending}
 disabled={diff.total === 0 || save.isPending || isSystem}
 onClick={() => save.mutate()}
 >
 {save.isPending ? 'Saving…' : 'Save changes'}
 </Button>
 </>
 }
 />

 <div className="px-5 py-4">
 {(role.isLoading || matrix.isLoading) && <SkeletonForm />}

 {(role.isError || matrix.isError) && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load"
 description="Could not load the role or permission matrix."
 action={
 <Button variant="secondary" onClick={() => window.location.reload()}>
 Retry
 </Button>
 }
 />
 )}

 {matrix.data && role.data && (
 <>
 {isSystem && (
 <Panel
 title={
 <span className="flex items-center gap-2 text-warning">
 <Lock size={14} /> System role — read-only
 </span>
 }
 className="mb-4"
 >
 <p className="text-sm text-secondary">
 {isSystemAdmin
 ? 'System Administrator always has every permission. Editing is disabled by design.'
 : 'This role is seeded by the system and cannot be edited. Use Clone to create a customizable copy.'}
 </p>
 <div className="mt-3">
 <Button
 variant="secondary"
 size="sm"
 onClick={() =>
 navigate('/admin/roles/create', { state: { cloneFrom: role.data.id } })
 }
 >
 Clone this role
 </Button>
 </div>
 </Panel>
 )}

 {/* Unsaved Changes Diff Drawer */}
 {diff.total > 0 && showDiffDrawer && (
 <Panel
 title={
 <div className="flex items-center justify-between">
 <span className="flex items-center gap-2 text-warning">
 <SlidersHorizontal size={15} /> Unsaved Permission Changes Summary ({diff.total})
 </span>
 <button
 type="button"
 onClick={() => setShowDiffDrawer(false)}
 className="text-muted hover:text-primary cursor-pointer p-1"
 >
 <X size={14} />
 </button>
 </div>
 }
 className="mb-4 border-warning/30 bg-warning/5"
 >
 <div className="grid gap-4 md:grid-cols-2">
 {diff.added.length > 0 && (
 <div>
 <h4 className="flex items-center gap-1.5 font-mono text-xs font-medium text-success uppercase tracking-wider mb-2">
 <PlusCircle size={14} /> Adding Permissions ({diff.added.length})
 </h4>
 <ul className="space-y-1.5 max-h-48 overflow-y-auto pr-1">
 {diff.added.map((slug) => {
 const item = permissionBySlug.get(slug);
 return (
 <li key={slug} className="flex items-center justify-between rounded bg-surface border border-subtle px-2.5 py-1 text-xs">
 <span className="font-medium text-primary">{item?.name || slug}</span>
 <span className="font-mono text-[10px] text-muted">{slug}</span>
 </li>
 );
 })}
 </ul>
 </div>
 )}
 {diff.removed.length > 0 && (
 <div>
 <h4 className="flex items-center gap-1.5 font-mono text-xs font-medium text-danger uppercase tracking-wider mb-2">
 <MinusCircle size={14} /> Removing Permissions ({diff.removed.length})
 </h4>
 <ul className="space-y-1.5 max-h-48 overflow-y-auto pr-1">
 {diff.removed.map((slug) => {
 const item = permissionBySlug.get(slug);
 return (
 <li key={slug} className="flex items-center justify-between rounded bg-surface border border-subtle px-2.5 py-1 text-xs">
 <span className="font-medium text-primary">{item?.name || slug}</span>
 <span className="font-mono text-[10px] text-muted">{slug}</span>
 </li>
 );
 })}
 </ul>
 </div>
 )}
 </div>
 </Panel>
 )}

 <Panel
 title={
 <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between w-full">
 <div className="flex items-center gap-3">
 <span className="font-display font-medium text-base text-primary">
 {selected.size} of {totalPermissions} granted
 </span>
 <div className="hidden sm:block h-3 w-px bg-border-default" />
 <div className="flex items-center gap-1.5 text-xs text-muted font-mono">
 <span>{Math.round((selected.size / (totalPermissions || 1)) * 100)}% coverage</span>
 </div>
 </div>

 {/* Preset Shortcuts */}
 {!isSystem && (
 <div className="flex items-center gap-1.5 flex-wrap">
 <span className="text-2xs font-mono uppercase tracking-wider text-muted mr-1 hidden md:inline">
 Presets:
 </span>
 <Button
 variant="secondary"
 size="sm"
 icon={<Eye size={12} />}
 onClick={() => applyPreset('read_only')}
 >
 Read-Only
 </Button>
 <Button
 variant="secondary"
 size="sm"
 icon={<Sparkles size={12} />}
 onClick={() => applyPreset('standard_editor')}
 >
 Editor
 </Button>
 <Button
 variant="secondary"
 size="sm"
 icon={<CheckSquare size={12} />}
 onClick={() => applyPreset('full_access')}
 >
 Grant All
 </Button>
 <Button
 variant="secondary"
 size="sm"
 icon={<Square size={12} />}
 onClick={() => applyPreset('clear_all')}
 >
 Clear All
 </Button>
 </div>
 )}
 </div>
 }
 >
 {/* Filter and Control Toolbar */}
 <div className="flex flex-col gap-3 mb-5 border-b border-default pb-4">
 <div className="grid gap-3 sm:grid-cols-12 items-center">
 <Input
 placeholder="Filter permissions by name, slug, or description…"
 value={search}
 onChange={(e) => setSearch(e.target.value)}
 aria-label="Search permissions"
 prefix={<Search size={14} className="text-muted" />}
 containerClassName="sm:col-span-6"
 />
 <Select
 value={moduleFilter}
 onChange={(e) => setModuleFilter(e.target.value)}
 aria-label="Module filter"
 containerClassName="sm:col-span-6 md:col-span-3"
 >
 <option value="all">All modules ({Object.keys(matrix.data).length})</option>
 {Object.entries(matrix.data).map(([m, perms]) => (
 <option key={m} value={m}>
 {m} ({perms.length})
 </option>
 ))}
 </Select>

 {/* Quick toggle list buttons */}
 <div className="sm:col-span-12 md:col-span-3 flex items-center justify-end gap-2">
 <Button
 variant="secondary"
 size="sm"
 onClick={expandAllModules}
 className="text-2xs"
 >
 Expand All
 </Button>
 <Button
 variant="secondary"
 size="sm"
 onClick={collapseAllModules}
 className="text-2xs"
 >
 Collapse All
 </Button>
 </div>
 </div>

 {/* Status Tabs */}
 <div className="flex items-center justify-between gap-2 flex-wrap pt-1">
 <div className="flex items-center gap-1.5">
 {(
 [
 { id: 'all', label: `All (${totalPermissions})` },
 { id: 'granted', label: `Granted (${selected.size})` },
 { id: 'ungranted', label: `Ungranted (${totalPermissions - selected.size})` },
 { id: 'modified', label: `Unsaved Changes (${diff.total})` },
 ] as const
 ).map((st) => (
 <button
 key={st.id}
 type="button"
 onClick={() => setStatusFilter(st.id)}
 className={cn(
 'rounded-full px-3 py-1 font-mono text-[11px] uppercase tracking-wider transition-colors cursor-pointer',
 statusFilter === st.id
 ? 'bg-accent text-accent-fg font-medium'
 : 'bg-subtle text-muted hover:text-primary hover:bg-elevated',
 )}
 >
 {st.label}
 </button>
 ))}
 </div>

 {/* Select All Visible toggle */}
 {!isSystem && visibleSlugs.length > 0 && (
 <Button
 variant="ghost"
 size="sm"
 onClick={toggleAllVisible}
 className="text-xs text-secondary hover:text-primary"
 >
 {allVisibleSelected ? <Square size={13} className="mr-1" /> : <CheckSquare size={13} className="mr-1" />}
 {allVisibleSelected ? 'Deselect visible' : `Select all visible (${visibleSlugs.length})`}
 </Button>
 )}
 </div>
 </div>

 {Object.keys(visibleMatrix).length === 0 && (
 <EmptyState
 icon="search"
 title="No matching permissions"
 description={
 statusFilter !== 'all'
 ? `No permissions found under '${statusFilter}' filter for the selected search & module.`
 : 'Try clearing the search query or selecting a different module.'
 }
 action={
 (search || moduleFilter !== 'all' || statusFilter !== 'all') && (
 <Button
 variant="secondary"
 size="sm"
 onClick={() => {
 setSearch('');
 setModuleFilter('all');
 setStatusFilter('all');
 }}
 >
 Reset filters
 </Button>
 )
 }
 />
 )}

 {/* Module Groups */}
 <div className="flex flex-col gap-4">
 {Object.entries(visibleMatrix).map(([module, perms]) => {
 const allSelected = perms.every((p) => selected.has(p.slug));
 const someSelected = perms.some((p) => selected.has(p.slug));
 const isCollapsed = collapsed.has(module);
 const moduleSelectedCount = perms.filter((p) => selected.has(p.slug)).length;
 const pct = Math.round((moduleSelectedCount / (perms.length || 1)) * 100);

 return (
 <div key={module} className="border border-default rounded-md overflow-hidden bg-surface ">
 {/* Module Header */}
 <div className="flex items-center justify-between px-4 py-3 bg-subtle/60 border-b border-default">
 <button
 type="button"
 onClick={() => toggleCollapsed(module)}
 className={cn('flex items-center gap-2 text-left font-medium text-sm text-primary cursor-pointer hover:text-accent', focusRingInset)}
 >
 {isCollapsed ? <ChevronRight size={16} className="text-muted" /> : <ChevronDown size={16} className="text-muted" />}
 <span className="font-display font-medium uppercase tracking-wider text-xs">
 {module}
 </span>
 <span className="text-2xs font-mono text-muted font-normal">
 ({moduleSelectedCount} of {perms.length} enabled)
 </span>
 </button>

 <div className="flex items-center gap-3">
 {/* Module Progress Bar */}
 <div className="hidden sm:flex items-center gap-2">
 <div className="w-20 h-1.5 rounded-full bg-border-default overflow-hidden">
 <div
 className="h-full bg-accent transition-all duration-300"
 style={{ width: `${pct}%` }}
 />
 </div>
 <span className="font-mono text-2xs tabular-nums text-muted">{pct}%</span>
 </div>

 {!isSystem && (
 <div className="flex items-center gap-1">
 <button
 type="button"
 title="Grant all in this module"
 onClick={() => setModulePermissions(module, 'all')}
 className="rounded px-2 py-0.5 font-mono text-[10px] uppercase tracking-wider border border-default-default bg-surface text-secondary hover:text-primary hover:bg-elevated transition-colors cursor-pointer"
 >
 All
 </button>
 <button
 type="button"
 title="Grant view-only permissions in this module"
 onClick={() => setModulePermissions(module, 'view_only')}
 className="rounded px-2 py-0.5 font-mono text-[10px] uppercase tracking-wider border border-default-default bg-surface text-secondary hover:text-primary hover:bg-elevated transition-colors cursor-pointer"
 >
 View Only
 </button>
 <button
 type="button"
 title="Clear all in this module"
 onClick={() => setModulePermissions(module, 'none')}
 className="rounded px-2 py-0.5 font-mono text-[10px] uppercase tracking-wider border border-default-default bg-surface text-secondary hover:text-primary hover:bg-elevated transition-colors cursor-pointer"
 >
 Clear
 </button>
 </div>
 )}

 <Checkbox
 checked={allSelected}
 onChange={() => setModulePermissions(module, allSelected ? 'none' : 'all')}
 disabled={isSystem}
 aria-label={`Toggle all ${module} permissions`}
 className={cn(someSelected && !allSelected && 'opacity-60')}
 />
 </div>
 </div>

 {/* Permission Rows */}
 {!isCollapsed && (
 <div className="divide-y divide-subtle/50">
 {perms.map((p, idx) => {
 const isGranted = selected.has(p.slug);
 const badge = getActionBadge(p.slug);
 const isModified = isGranted !== baseline.has(p.slug);

 return (
 <div
 key={p.slug}
 onClick={() => toggleSlug(p.slug)}
 className={cn(
 'flex items-center justify-between px-4 py-3.5 transition-all duration-fast cursor-pointer select-none border-b border-subtle/70 relative rounded-sm',
 idx % 2 === 1
 ? (isGranted ? 'bg-[var(--bg-row-hover)]/40 hover:bg-[var(--bg-row-hover)]' : 'bg-[var(--bg-zebra-even)] hover:bg-[var(--bg-row-hover)]')
 : (isGranted ? 'bg-[var(--bg-zebra-odd)] hover:bg-[var(--bg-row-hover)]' : 'bg-[var(--bg-surface)] hover:bg-[var(--bg-zebra-even)]'),
 isGranted && 'outline outline-2 outline-accent -outline-offset-2 z-10 ',
 isModified && 'bg-warning/15 outline outline-2 outline-warning -outline-offset-2 z-10',
 )}
 >
 <div className="flex items-start gap-3 min-w-0 pr-4">
 <div className="pt-0.5 shrink-0">
 <Chip variant={badge.variant} className="text-[9px] font-mono font-medium px-1.5 py-0.2">
 {badge.label}
 </Chip>
 </div>

 <div className="min-w-0">
 <div className="flex items-center gap-2 flex-wrap">
 <span className={cn('text-sm font-medium', isGranted ? 'text-primary' : 'text-secondary')}>
 {p.name}
 </span>
 <span className="font-mono text-xs text-muted">
 {p.slug}
 </span>
 {isModified && (
 <span className="font-mono text-[9px] uppercase tracking-wider text-warning font-medium">
 {isGranted ? '+ Added' : '− Removed'}
 </span>
 )}
 </div>

 {p.description && (
 <p className="text-xs text-muted mt-0.5 leading-relaxed">
 {p.description}
 </p>
 )}
 </div>
 </div>

 <div className="shrink-0 flex items-center gap-2">
 {isGranted ? (
 <span className="hidden sm:inline-flex items-center gap-1 font-mono text-[10px] uppercase text-success font-medium mr-2">
 <Check size={12} /> Granted
 </span>
 ) : (
 <span className="hidden sm:inline-flex items-center gap-1 font-mono text-[10px] uppercase text-muted font-normal mr-2">
 Off
 </span>
 )}
 <Checkbox
 checked={isGranted}
 onChange={() => toggleSlug(p.slug)}
 disabled={isSystem}
 aria-label={`Toggle ${p.name}`}
 />
 </div>
 </div>
 );
 })}
 </div>
 )}
 </div>
 );
 })}
 </div>
 </Panel>
 </>
 )}
 </div>
 </div>
 );
}
