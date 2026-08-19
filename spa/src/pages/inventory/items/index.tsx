import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { LuArchiveRestore, LuListChecks, LuPencil, LuPlus, LuTrash2 } from '@/lib/icons';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { itemsApi, type ItemListParams } from '@/api/inventory/items';
import { wasReportedGlobally } from '@/api/client';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, NumCell, type BulkAction, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { Modal } from '@/components/ui/Modal';
import { ItemCategoriesManager } from '@/pages/inventory/categories';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { reportMutationError } from '@/lib/formErrors';
import { showUndoToast } from '@/lib/undoToast';
import type { Item } from '@/types/inventory';

import { ListEmptyState } from '@/components/ui/ListEmptyState';
const stockChip = (status: 'ok' | 'low' | 'critical') => ({
  ok: 'success' as const, low: 'warning' as const, critical: 'danger' as const }[status]);

const DEFAULT_FILTERS: ItemListParams = {
  page: 1, per_page: 25, is_active: 'true',
};

// ─── Bulk archive / restore plumbing ──────────────────────────────
//
// There is no server-side bulk endpoint for items, so a batch is a fan-out of
// single-row calls. Two separate limits, doing two different things — an earlier
// version of this comment conflated them:
//
// • LIMIT bounds the request COUNT. Fifty is comfortably inside the
//   authenticated `api` limiter (300/min — the 60/min figure is the guest
//   bucket, `api/config/rate_limits.php`) while leaving room for the refetch and
//   whatever else the session is doing, and it bounds how long the batch runs.
// • CONCURRENCY bounds how many are IN FLIGHT, which is not the same thing and
//   does not lower the rate: fifty requests are fifty requests whether sent
//   four-wide or all at once. What it buys is fewer simultaneous write
//   transactions against the same tables, and it stays under the browser's own
//   per-origin connection cap instead of queueing behind it.
const BULK_LIMIT = 50;
const BULK_CONCURRENCY = 4;

/**
 * The user-facing reason a single row failed, or null when there isn't one.
 *
 * Null is a real answer, not a gap. `err.message` on an Axios rejection is
 * "Request failed with status code 422" — see the `reportMutationError` docblock
 * for why that must never reach a screen. And when the interceptor has already
 * named the cause (429, offline, timeout, 403) the row still counts as failed,
 * but quoting a second sentence for it would contradict the first.
 */
function rowFailureReason(err: unknown): string | null {
  if (wasReportedGlobally(err)) return null;
  if (err instanceof AxiosError) {
    return (err.response?.data as { message?: string } | undefined)?.message ?? null;
  }
  return null;
}

interface BatchOutcome {
  /** Ids that succeeded — the exact set an undo has to reverse. */
  ok: string[];
  failed: Array<{ reason: string | null }>;
}

/** Apply `task` to every id with bounded concurrency; never rejects. */
async function runBatch(ids: string[], task: (id: string) => Promise<unknown>): Promise<BatchOutcome> {
  const ok: string[] = [];
  const failed: Array<{ reason: string | null }> = [];
  for (let i = 0; i < ids.length; i += BULK_CONCURRENCY) {
    const slice = ids.slice(i, i + BULK_CONCURRENCY);
    const settled = await Promise.allSettled(slice.map(task));
    settled.forEach((result, j) => {
      if (result.status === 'fulfilled') ok.push(slice[j]);
      else failed.push({ reason: rowFailureReason(result.reason) });
    });
  }
  return { ok, failed };
}

/** "Archived 7 of 10. 3 failed: …" — never a bare count of the successes. */
function partialMessage(verb: string, { ok, failed }: BatchOutcome): string {
  const reason = failed.find((f) => f.reason)?.reason;
  return `${verb} ${ok.length} of ${ok.length + failed.length}. ${failed.length} failed${
    reason ? `: ${reason}` : ' — open a failed row to see why.'
  }`;
}


export default function ItemsListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('inventory.items.manage');
  // Categories folded into this modal (2026-08-08) — the standalone
  // /inventory/categories route was removed. Stock Levels + Movements shortcuts
  // were dropped: both live one click away in the sidebar.
  const [showCategories, setShowCategories] = useState(false);
  const [filters, setFilters] = useUrlFilters<ItemListParams>(DEFAULT_FILTERS);
 const [confirmDelete, setConfirmDelete] = useState<Item | null>(null);
 const [confirmRestore, setConfirmRestore] = useState<Item | null>(null);
 const [bulkConfirm, setBulkConfirm] = useState<{ mode: 'archive' | 'restore'; rows: Item[] } | null>(null);
 const [scope, setScope] = useState<ArchiveScope>('active');

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['inventory', 'items', filters, { trashed: archiveToTrashed(scope) }],
 queryFn: () => itemsApi.list({ ...filters, trashed: archiveToTrashed(scope) }),
 placeholderData: (prev) => prev });
 const { data: itemOptions } = useQuery({
 queryKey: ['inventory', 'items', 'options'],
 queryFn: itemsApi.options,
 staleTime: 5 * 60 * 1000 });
 const stockStatusLabels = new Map((itemOptions?.stock_statuses ?? []).map((status) => [status.value, status.label]));
 const stockStatusLabel = (value: string) => stockStatusLabels.get(value) ?? value.replaceAll('_', ' ');

 const del = useMutation({
 mutationFn: (id: string) => itemsApi.delete(id),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['inventory', 'items'] });
 toast.success('Item archived.');
 setConfirmDelete(null);
 },
 onError: (e: AxiosError<{ message?: string }>) => {
 toast.error(e.response?.data?.message ?? 'Failed to delete item. Deactivate instead if it has stock or movements.');
 } });

 const restore = useMutation({
 mutationFn: (id: string) => itemsApi.restore(id),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['inventory', 'items'] });
 toast.success('Item restored.');
 setConfirmRestore(null);
 },
 onError: (e: AxiosError<{ message?: string }>) => {
 toast.error(e.response?.data?.message ?? 'Failed to restore item.');
 } });

 // ─── Bulk archive / restore ─────────────────────────────────────
 //
 // Archiving is the one bulk write this page offers, and only because the way
 // back is already here: `itemsApi.restore`, the per-row restore button, and the
 // "Archived" scope that lists what was archived. The undo toast reverses the
 // exact ids that succeeded — including after a partial batch, which is when a
 // user most needs it and least expects to be handed one.
 const bulkRestore = useMutation({
 mutationFn: (ids: string[]) => runBatch(ids, (id) => itemsApi.restore(id)),
 onSettled: () => qc.invalidateQueries({ queryKey: ['inventory', 'items'] }),
 onSuccess: (outcome) => {
 if (outcome.failed.length > 0) {
 toast.error(partialMessage('Restored', outcome), { duration: 7000 });
 return;
 }
 toast.success(`${outcome.ok.length} item${outcome.ok.length === 1 ? '' : 's'} restored.`);
 },
 onError: (e) => reportMutationError(e, 'Bulk restore failed.'),
 });

 const bulkArchive = useMutation({
 mutationFn: (ids: string[]) => runBatch(ids, (id) => itemsApi.delete(id)),
 onSettled: () => qc.invalidateQueries({ queryKey: ['inventory', 'items'] }),
 onSuccess: (outcome) => {
 const undo = () => bulkRestore.mutate(outcome.ok);
 if (outcome.failed.length > 0) {
 // Failure first, in the toast that carries the count. The undo follows
 // separately so the half that did archive is still reversible.
 toast.error(partialMessage('Archived', outcome), { duration: 7000 });
 if (outcome.ok.length > 0) {
 showUndoToast({ message: `${outcome.ok.length} archived.`, onUndo: undo, duration: 8000 });
 }
 return;
 }
 showUndoToast({
 message: `${outcome.ok.length} item${outcome.ok.length === 1 ? '' : 's'} archived.`,
 onUndo: undo,
 });
 },
 onError: (e) => reportMutationError(e, 'Bulk archive failed.'),
 });

 const bulkPending = bulkArchive.isPending || bulkRestore.isPending;

 /**
  * Which bulk action the current view can honestly offer.
  *
  * `Item` carries no `deleted_at`, so under the "All" scope the page cannot tell
  * an archived row from a live one — and a batch built on that guess would send
  * half its calls to the wrong endpoint. Offer nothing there rather than
  * something that looks right and isn't.
  */
 const bulkActions: BulkAction<Item>[] | undefined =
 !canManage || scope === 'with'
 ? undefined
 : scope === 'only'
 ? [{
 label: 'Restore selected',
 variant: 'secondary',
 icon: <LuArchiveRestore size={13} />,
 onClick: (rows) => {
 if (rows.length > BULK_LIMIT) {
 toast.error(`Restore up to ${BULK_LIMIT} items at a time.`);
 return;
 }
 setBulkConfirm({ mode: 'restore', rows });
 },
 }]
 : [{
 label: 'Archive selected',
 variant: 'danger',
 icon: <LuTrash2 size={13} />,
 onClick: (rows) => {
 if (rows.length > BULK_LIMIT) {
 toast.error(`Archive up to ${BULK_LIMIT} items at a time.`);
 return;
 }
 setBulkConfirm({ mode: 'archive', rows });
 },
 }];

 const columns: Column<Item>[] = [
 { key: 'code', header: 'Code', cell: (r) => (
 <span className="font-mono">{r.code}</span>
 ) },
 { key: 'name', header: 'Name', cell: (r) => (
 <div>
 <div className="font-medium">{r.name}</div>
 <div className="text-xs text-muted">{r.category?.name ?? '—'} · {r.item_type_label}</div>
 </div>
 ) },
 { key: 'uom', header: 'UOM', cell: (r) => r.unit_of_measure },
 { key: 'cost', header: 'Std Cost', align: 'right', cell: (r) => <NumCell>{Number(r.standard_cost).toFixed(4)}</NumCell> },
 { key: 'on_hand', header: 'On hand', align: 'right', cell: (r) => <NumCell>{Number(r.on_hand_quantity).toFixed(3)}</NumCell> },
 { key: 'available', header: 'Available', align: 'right', cell: (r) => (
 <NumCell className={r.stock_status === 'critical' ? 'text-danger-fg' : r.stock_status === 'low' ? 'text-warning-fg' : ''}>
 {Number(r.available_quantity).toFixed(3)}
 </NumCell>
 ) },
 { key: 'reorder', header: 'Reorder pt', align: 'right', cell: (r) => <NumCell>{Number(r.reorder_point).toFixed(3)}</NumCell> },
 { key: 'status', header: 'Stock', cell: (r) => <Chip variant={stockChip(r.stock_status)}>{stockStatusLabel(r.stock_status)}</Chip> },
 { key: 'quality', header: 'QC plan', cell: (r) => (
 <Chip variant={r.quality_plan_ready ? 'success' : 'warning'}>{r.quality_plan_ready ? 'ready' : 'missing'}</Chip>
 ) },
 { key: 'active', header: '', cell: (r) => r.is_active ? null : <Chip variant="neutral">inactive</Chip> },
 ...(canManage ? [{
 key: 'actions',
 header: '',
 align: 'right' as const,
 cell: (r: Item) => (
 <div className="flex justify-end gap-1">
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuPencil size={14} />}
 aria-label={`Edit ${r.code}`}
 onClick={() => navigate(`/inventory/items/${r.id}/edit`)}
 className="text-muted hover:text-primary"
 />
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 disabled={bulkPending}
 aria-label={`${scope === 'only' ? 'Restore' : 'Delete'} ${r.code}`}
 onClick={() => (scope === 'only' ? setConfirmRestore(r) : setConfirmDelete(r))}
 className={scope === 'only' ? 'text-muted hover:text-primary' : 'text-muted hover:text-danger-fg'}
 icon={scope === 'only' ? <LuArchiveRestore size={14} /> : <LuTrash2 size={14} />}
 />
 </div>
 ) }] : []),
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'item_type', label: 'Type', type: 'select', options: [
 { value: '', label: 'All' },
 ...(itemOptions?.item_types ?? []),
 ]},
 { key: 'stock_status', label: 'Stock status', type: 'select', options: [
 { value: '', label: 'All' },
 ...(itemOptions?.stock_statuses ?? []),
 ]},
 { key: 'is_active', label: 'Active', type: 'select', options: [
 { value: '', label: 'All' }, { value: 'true', label: 'Active' }, { value: 'false', label: 'Inactive' },
 ]},
 ];

 return (
 <div>
 <PageHeader
 title="Items"
 subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'item' : 'items'}` : undefined}
 actions={
 <>
 <Button variant="secondary" size="xs" icon={<LuListChecks size={14} />} onClick={() => setShowCategories(true)}>
 Categories
 </Button>
 {/* Warehouse Structure = master data (zones/locations). Warehouse Map (sidebar) is the live floor view. */}
 <Button variant="secondary" size="xs" onClick={() => navigate('/inventory/warehouse')}>Warehouse Structure</Button>
 {canManage && (
 <Button variant="primary" size="xs" icon={<LuPlus size={14} />} onClick={() => navigate('/inventory/items/create')}>
 New item
 </Button>
 )}
 </>
 }
 />
 <FilterBar
 filters={filterConfig}
 values={filters}
 onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 searchPlaceholder="Search code or name…"
 actions={<ArchiveFilter value={scope} onChange={setScope} />}
 />
 {isLoading && !data && <SkeletonTable columns={canManage ? 10 : 9} rows={8} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load items" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
 {data && (
   <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 px-5 py-4 border-b border-default bg-canvas">
   <StatCard
     label="Active Items"
     value={data.data.filter(i => i.is_active).length}
     helper="in current view"
     linkTo="?is_active=true"
   />
   <StatCard
     label="Inactive Items"
     value={data.data.filter(i => !i.is_active).length}
     helper="in current view"
     linkTo="?is_active=false"
   />
   <StatCard
     label={stockStatusLabel('low')}
     value={data.data.filter(i => i.stock_status === 'low').length}
     helper="in current view"
     linkTo="?stock_status=low"
   />
   <StatCard
     label={stockStatusLabel('critical')}
     value={data.data.filter(i => i.stock_status === 'critical').length}
     helper="in current view"
     linkTo="?stock_status=critical"
     className="border-danger/30 bg-danger-bg/20"
   />
   </div>
  )}

{data && data.data.length === 0 && (
 <ListEmptyState />
 )}


  {data && data.data.length > 0 && (
 <div className="px-5 py-4">
  <DataTable
  tableKey="inventory-items"
  onRowClick={(r) => navigate(`/inventory/items/${r.id}`)}
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
 selectable={canManage && scope !== 'with'}
 bulkActions={bulkActions}
 />
 </div>
 )}

 <ConfirmDialog
 isOpen={!!confirmDelete}
 onClose={() => setConfirmDelete(null)}
 onConfirm={() => { if (confirmDelete) del.mutate(confirmDelete.id); }}
 title="Delete item?"
 description={
 confirmDelete ? (
 <>
 <span className="font-mono font-medium text-primary">{confirmDelete.code}</span>{' '}
 <span className="text-muted">— {confirmDelete.name}</span>
 <br />
 The item will be archived and can be restored later. Deletion fails if there is existing stock or movement history; deactivate instead in that case.
 </>
 ) : null
 }
 confirmLabel="Delete"
 variant="danger"
 pending={del.isPending}
 />

 <ConfirmDialog
 isOpen={!!confirmRestore}
 onClose={() => setConfirmRestore(null)}
 onConfirm={() => { if (confirmRestore) restore.mutate(confirmRestore.id); }}
 title="Restore item?"
 description={
 confirmRestore ? (
 <>
 <span className="font-mono font-medium text-primary">{confirmRestore.code}</span>{' '}
 <span className="text-muted">— {confirmRestore.name}</span>
 <br />
 The item will be restored and available for use again.
 </>
 ) : null
 }
 confirmLabel="Restore"
 variant="primary"
 pending={restore.isPending}
 />

 <ConfirmDialog
 isOpen={bulkConfirm !== null}
 onClose={() => setBulkConfirm(null)}
 onConfirm={() => {
 if (!bulkConfirm) return;
 const ids = bulkConfirm.rows.map((r) => r.id);
 if (bulkConfirm.mode === 'archive') bulkArchive.mutate(ids);
 else bulkRestore.mutate(ids);
 setBulkConfirm(null);
 }}
 title={
 bulkConfirm?.mode === 'restore'
 ? `Restore ${bulkConfirm.rows.length} item${bulkConfirm.rows.length === 1 ? '' : 's'}?`
 : `Archive ${bulkConfirm?.rows.length ?? 0} item${bulkConfirm?.rows.length === 1 ? '' : 's'}?`
 }
 description={
 bulkConfirm?.mode === 'restore'
 ? 'They will reappear in active lists and be available for use again.'
 : 'They can be restored afterwards, and an Undo appears once the batch finishes. Items with existing stock or movement history will be reported as failed and left alone — deactivate those instead.'
 }
 confirmLabel={bulkConfirm?.mode === 'restore' ? 'Restore' : 'Archive'}
 variant={bulkConfirm?.mode === 'restore' ? 'primary' : 'danger'}
 pending={bulkPending}
 />

 {/* Item categories — 2026-08-08: standalone page folded into this modal (same
 pattern as Leave Types). CRUD here also refreshes the category dropdown on
 the item create/edit form (same ['inventory', 'categories'] query key). */}
 <Modal isOpen={showCategories} onClose={() => setShowCategories(false)} size="xl" title="Item Categories">
 <ItemCategoriesManager />
 </Modal>
 </div>
 );
}
