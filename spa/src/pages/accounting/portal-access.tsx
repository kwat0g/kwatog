import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { LuBan, LuKeyRound, LuPlus, LuRefreshCw, LuShieldCheck } from '@/lib/icons';
import {
 portalAccessApi,
 type CustomerPortalAccessListParams,
 type PortalAccessListParams,
} from '@/api/b2b/portal-access';
import { vendorsApi } from '@/api/accounting/vendors';
import { customersApi } from '@/api/accounting/customers';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { PageHeader } from '@/components/layout/PageHeader';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { ListEmptyState } from '@/components/ui/ListEmptyState';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Tabs } from '@/components/ui/Tabs';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { CustomerPortalUser, SupplierPortalUser } from '@/types/b2b';

type PortalStatus = SupplierPortalUser['status'];

const statusLabels: Record<PortalStatus, string> = {
 active: 'Active',
 inactive: 'Inactive',
 locked: 'Locked',
 pending: 'Pending first sign-in',
};

const statusFilter: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: [
  { value: '', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'pending', label: 'Pending' },
  { value: 'locked', label: 'Locked' },
  { value: 'inactive', label: 'Inactive' },
 ] },
];

/**
 * Portal Access — one admin screen for BOTH self-service portals.
 *
 * Supplier accounts authenticate against `supplier_portal_users` (bearer
 * tokens) and customer accounts against `customer_portal_users` (sessions).
 * They deliberately do not live in `users` — that is the tenant-isolation
 * boundary the portal guards are built on (see auth.php providers). This page
 * is where an operator sees and manages the two account sets side by side.
 */
export default function PortalAccessPage() {
 const [searchParams, setSearchParams] = useSearchParams();
 const [tab, setTab] = useState<'suppliers' | 'customers'>(() =>
  searchParams.get('tab') === 'customers' ? 'customers' : 'suppliers',
 );

 // Tabs share the URL query string with their list filters; clear it when
 // switching so the incoming tab mounts on clean defaults instead of the
 // previous tab's page/status.
 const switchTab = (next: 'suppliers' | 'customers') => {
  if (next === tab) return;
  setTab(next);
  setSearchParams({}, { replace: true });
 };

 return (
  <div>
   <PageHeader
    title="Portal access"
    subtitle="Supplier and customer self-service sign-in accounts"
   />
   <div className="px-5 pt-2">
    <Tabs
     label="Portal account type"
     value={tab}
     onChange={switchTab}
     items={[
      { key: 'suppliers', label: 'Suppliers' },
      { key: 'customers', label: 'Customers' },
     ]}
    />
   </div>
   {tab === 'suppliers' ? <SuppliersSection /> : <CustomersSection />}
  </div>
 );
}

/* ─── Suppliers tab ───────────────────────────────────────── */

type SupplierConfirmAction = { kind: 'deactivate' | 'revoke'; user: SupplierPortalUser } | null;

function SuppliersSection() {
 const queryClient = useQueryClient();
 const { can } = usePermission();
 const canManage = can('b2b.portal_access.manage');
 const [filters, setFilters] = useUrlFilters<PortalAccessListParams>({ page: 1, per_page: 25 });
 const [inviteOpen, setInviteOpen] = useState(false);
 const [confirmAction, setConfirmAction] = useState<SupplierConfirmAction>(null);
 const [invite, setInvite] = useState({ vendor_id: '', name: '', email: '' });

 const list = useQuery({
  queryKey: ['b2b', 'portal-access', 'suppliers', filters],
  queryFn: () => portalAccessApi.listSuppliers(filters),
  placeholderData: (previous) => previous,
 });
 const vendors = useQuery({
  queryKey: ['accounting', 'vendors', 'portal-access-options'],
  queryFn: () => vendorsApi.list({ per_page: 100, is_active: true }),
  enabled: inviteOpen,
 });

 const refresh = () => queryClient.invalidateQueries({ queryKey: ['b2b', 'portal-access'] });
 const inviteMutation = useMutation({
  mutationFn: () => portalAccessApi.inviteSupplier(invite.vendor_id, { name: invite.name.trim(), email: invite.email.trim() }),
  onSuccess: () => {
   setInviteOpen(false);
   setInvite({ vendor_id: '', name: '', email: '' });
   refresh();
   toast.success('Invitation queued. The temporary password was sent by email.');
  },
  onError: () => toast.error('Could not queue the supplier invitation.'),
 });
 const resendMutation = useMutation({
  mutationFn: (id: string) => portalAccessApi.resendSupplier(id),
  onSuccess: () => { refresh(); toast.success('Invitation re-sent.'); },
  onError: () => toast.error('Could not re-send the invitation.'),
 });
 const deactivateMutation = useMutation({
  mutationFn: (id: string) => portalAccessApi.deactivateSupplier(id),
  onSuccess: () => { setConfirmAction(null); refresh(); toast.success('Portal access deactivated.'); },
  onError: () => toast.error('Could not deactivate portal access.'),
 });
 const reactivateMutation = useMutation({
  mutationFn: (id: string) => portalAccessApi.reactivateSupplier(id),
  onSuccess: () => { refresh(); toast.success('Portal access reactivated.'); },
  onError: () => toast.error('Could not reactivate portal access.'),
 });
 const revokeMutation = useMutation({
  mutationFn: (id: string) => portalAccessApi.revokeTokens(id),
  onSuccess: () => { setConfirmAction(null); refresh(); toast.success('All supplier sessions revoked.'); },
  onError: () => toast.error('Could not revoke supplier sessions.'),
 });

 const submitInvite = (event: FormEvent<HTMLFormElement>) => {
  event.preventDefault();
  if (!invite.vendor_id || !invite.name.trim() || !invite.email.trim()) {
   toast.error('Choose a vendor and enter the contact name and email.');
   return;
  }
  inviteMutation.mutate();
 };

 const columns: Column<SupplierPortalUser>[] = [
  { key: 'name', header: 'Contact', cell: (row) => <div><div className="font-medium text-primary">{row.name}</div><div className="text-xs text-muted">{row.email}</div></div>, pinned: 'left' },
  { key: 'vendor', header: 'Vendor', cell: (row) => row.vendor?.name ?? '—' },
  { key: 'status', header: 'Status', cell: (row) => <Chip variant={chipVariantForStatus(row.status)}>{statusLabels[row.status]}</Chip> },
  { key: 'last_login', header: 'Last sign-in', cell: (row) => row.last_login_at ? new Date(row.last_login_at).toLocaleString() : 'Never' },
  {
   key: 'actions',
   header: 'Actions',
   togglable: false,
   cell: (row) => canManage ? (
    <div className="flex flex-wrap items-center gap-1" onClick={(event) => event.stopPropagation()}>
     {row.status !== 'inactive' && <Button size="xs" variant="ghost" icon={<LuRefreshCw size={12} />} onClick={() => resendMutation.mutate(row.id)} loading={resendMutation.isPending && resendMutation.variables === row.id}>Resend</Button>}
     {row.status === 'inactive' ? (
      <Button size="xs" variant="ghost" icon={<LuShieldCheck size={12} />} onClick={() => reactivateMutation.mutate(row.id)} loading={reactivateMutation.isPending && reactivateMutation.variables === row.id}>Reactivate</Button>
     ) : (
      <Button size="xs" variant="ghost" icon={<LuBan size={12} />} onClick={() => setConfirmAction({ kind: 'deactivate', user: row })}>Deactivate</Button>
     )}
     <Button size="xs" variant="ghost" icon={<LuKeyRound size={12} />} onClick={() => setConfirmAction({ kind: 'revoke', user: row })}>Revoke sessions</Button>
    </div>
   ) : <span className="text-xs text-muted">View only</span>,
  },
 ];

 return (
  <>
   <FilterBar
    filters={statusFilter}
    values={filters}
    onSearch={(search) => setFilters((current) => ({ ...current, search: search || undefined, page: 1 }))}
    onFilter={(key, value) => setFilters((current) => ({ ...current, [key]: value || undefined, page: 1 }))}
    searchPlaceholder="Search contact or email…"
    actions={canManage ? <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => setInviteOpen(true)}>Invite supplier</Button> : null}
   />
   {list.isLoading && !list.data && <SkeletonTable columns={5} rows={6} />}
   {list.isError && <EmptyState icon="alert-circle" title="Failed to load portal access" action={<Button variant="secondary" onClick={() => list.refetch()}>Retry</Button>} />}
   {list.data && list.data.data.length === 0 && <ListEmptyState />}
   {list.data && list.data.data.length > 0 && <div className="px-5 py-4"><DataTable
    columns={columns}
    data={list.data.data}
    meta={list.data.meta}
    onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
    onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
    tableKey="b2b-supplier-portal-access"
   /></div>}

   <Modal isOpen={inviteOpen} onClose={() => setInviteOpen(false)} title="Invite supplier contact">
    <form onSubmit={submitInvite} className="space-y-4">
     <p className="text-sm text-muted">The contact receives a one-time temporary password by email and must set a new password on first sign-in.</p>
     <Select label="Vendor" required value={invite.vendor_id} onChange={(event) => setInvite((current) => ({ ...current, vendor_id: event.target.value }))}>
      <option value="">Choose a vendor…</option>
      {(vendors.data?.data ?? []).map((vendor) => <option key={vendor.id} value={vendor.id}>{vendor.name}</option>)}
     </Select>
     <Input label="Contact name" required value={invite.name} onChange={(event) => setInvite((current) => ({ ...current, name: event.target.value }))} maxLength={200} />
     <Input label="Email" required type="email" value={invite.email} onChange={(event) => setInvite((current) => ({ ...current, email: event.target.value }))} maxLength={255} />
     <ModalFooter>
      <Button type="button" variant="secondary" onClick={() => setInviteOpen(false)}>Cancel</Button>
      <Button type="submit" variant="primary" loading={inviteMutation.isPending}>Send invitation</Button>
     </ModalFooter>
    </form>
   </Modal>

   <ConfirmDialog
    isOpen={confirmAction !== null}
    onClose={() => setConfirmAction(null)}
    title={confirmAction?.kind === 'deactivate' ? 'Deactivate supplier access?' : 'Revoke supplier sessions?'}
    description={confirmAction?.kind === 'deactivate'
     ? `${confirmAction.user.name} will no longer be able to sign in. Existing sessions will be revoked.`
     : `All active sessions for ${confirmAction?.user.name ?? 'this contact'} will be revoked.`}
    variant={confirmAction?.kind === 'deactivate' ? 'danger' : 'warning'}
    confirmLabel={confirmAction?.kind === 'deactivate' ? 'Deactivate' : 'Revoke sessions'}
    pending={deactivateMutation.isPending || revokeMutation.isPending}
    onConfirm={() => {
     if (!confirmAction) return;
     if (confirmAction.kind === 'deactivate') deactivateMutation.mutate(confirmAction.user.id);
     else revokeMutation.mutate(confirmAction.user.id);
    }}
   />
  </>
 );
}

/* ─── Customers tab ──────────────────────────────────────── */

type CustomerConfirmAction = { kind: 'deactivate'; user: CustomerPortalUser } | null;

function CustomersSection() {
 const queryClient = useQueryClient();
 const { can } = usePermission();
 const canManage = can('b2b.portal_access.manage');
 const [filters, setFilters] = useUrlFilters<CustomerPortalAccessListParams>({ page: 1, per_page: 25 });
 const [inviteOpen, setInviteOpen] = useState(false);
 const [confirmAction, setConfirmAction] = useState<CustomerConfirmAction>(null);
 const [invite, setInvite] = useState({ customer_id: '', name: '', email: '' });

 const list = useQuery({
  queryKey: ['b2b', 'portal-access', 'customers', filters],
  queryFn: () => portalAccessApi.listCustomers(filters),
  placeholderData: (previous) => previous,
 });
 const customers = useQuery({
  queryKey: ['accounting', 'customers', 'portal-access-options'],
  queryFn: () => customersApi.list({ per_page: 200, is_active: true }),
  enabled: inviteOpen,
 });

 const refresh = () => queryClient.invalidateQueries({ queryKey: ['b2b', 'portal-access'] });
 const inviteMutation = useMutation({
  mutationFn: () => portalAccessApi.inviteCustomer(invite.customer_id, { name: invite.name.trim(), email: invite.email.trim() }),
  onSuccess: () => {
   setInviteOpen(false);
   setInvite({ customer_id: '', name: '', email: '' });
   refresh();
   toast.success('Invitation queued. The temporary password was sent by email.');
  },
  onError: () => toast.error('Could not queue the customer invitation.'),
 });
 const resendMutation = useMutation({
  mutationFn: (id: string) => portalAccessApi.resendCustomer(id),
  onSuccess: () => { refresh(); toast.success('Invitation re-sent.'); },
  onError: () => toast.error('Could not re-send the invitation.'),
 });
 const deactivateMutation = useMutation({
  mutationFn: (id: string) => portalAccessApi.deactivateCustomer(id),
  onSuccess: () => { setConfirmAction(null); refresh(); toast.success('Portal access deactivated.'); },
  onError: () => toast.error('Could not deactivate portal access.'),
 });
 const reactivateMutation = useMutation({
  mutationFn: (id: string) => portalAccessApi.reactivateCustomer(id),
  onSuccess: () => { refresh(); toast.success('Portal access reactivated.'); },
  onError: () => toast.error('Could not reactivate portal access.'),
 });

 const submitInvite = (event: FormEvent<HTMLFormElement>) => {
  event.preventDefault();
  if (!invite.customer_id || !invite.name.trim() || !invite.email.trim()) {
   toast.error('Choose a customer and enter the contact name and email.');
   return;
  }
  inviteMutation.mutate();
 };

 const columns: Column<CustomerPortalUser>[] = [
  { key: 'name', header: 'Contact', cell: (row) => <div><div className="font-medium text-primary">{row.name}</div><div className="text-xs text-muted">{row.email}</div></div>, pinned: 'left' },
  { key: 'customer', header: 'Customer', cell: (row) => row.customer?.name ?? '—' },
  { key: 'status', header: 'Status', cell: (row) => <Chip variant={chipVariantForStatus(row.status)}>{statusLabels[row.status]}</Chip> },
  { key: 'last_login', header: 'Last sign-in', cell: (row) => row.last_login_at ? new Date(row.last_login_at).toLocaleString() : 'Never' },
  {
   key: 'actions',
   header: 'Actions',
   togglable: false,
   cell: (row) => canManage ? (
    <div className="flex flex-wrap items-center gap-1" onClick={(event) => event.stopPropagation()}>
     {row.status !== 'inactive' && <Button size="xs" variant="ghost" icon={<LuRefreshCw size={12} />} onClick={() => resendMutation.mutate(row.id)} loading={resendMutation.isPending && resendMutation.variables === row.id}>Resend</Button>}
     {row.status === 'inactive' ? (
      <Button size="xs" variant="ghost" icon={<LuShieldCheck size={12} />} onClick={() => reactivateMutation.mutate(row.id)} loading={reactivateMutation.isPending && reactivateMutation.variables === row.id}>Reactivate</Button>
     ) : (
      <Button size="xs" variant="ghost" icon={<LuBan size={12} />} onClick={() => setConfirmAction({ kind: 'deactivate', user: row })}>Deactivate</Button>
     )}
    </div>
   ) : <span className="text-xs text-muted">View only</span>,
  },
 ];

 return (
  <>
   <FilterBar
    filters={statusFilter}
    values={filters}
    onSearch={(search) => setFilters((current) => ({ ...current, search: search || undefined, page: 1 }))}
    onFilter={(key, value) => setFilters((current) => ({ ...current, [key]: value || undefined, page: 1 }))}
    searchPlaceholder="Search contact or email…"
    actions={canManage ? <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => setInviteOpen(true)}>Invite customer</Button> : null}
   />
   {list.isLoading && !list.data && <SkeletonTable columns={5} rows={6} />}
   {list.isError && <EmptyState icon="alert-circle" title="Failed to load portal access" action={<Button variant="secondary" onClick={() => list.refetch()}>Retry</Button>} />}
   {list.data && list.data.data.length === 0 && <ListEmptyState />}
   {list.data && list.data.data.length > 0 && <div className="px-5 py-4"><DataTable
    columns={columns}
    data={list.data.data}
    meta={list.data.meta}
    onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
    onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
    tableKey="b2b-customer-portal-access"
   /></div>}

   <Modal isOpen={inviteOpen} onClose={() => setInviteOpen(false)} title="Invite customer contact">
    <form onSubmit={submitInvite} className="space-y-4">
     <p className="text-sm text-muted">The contact receives a one-time temporary password by email and must set a new password on first sign-in.</p>
     <Select label="Customer" required value={invite.customer_id} onChange={(event) => setInvite((current) => ({ ...current, customer_id: event.target.value }))}>
      <option value="">Choose a customer…</option>
      {(customers.data?.data ?? []).map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}
     </Select>
     <Input label="Contact name" required value={invite.name} onChange={(event) => setInvite((current) => ({ ...current, name: event.target.value }))} maxLength={200} />
     <Input label="Email" required type="email" value={invite.email} onChange={(event) => setInvite((current) => ({ ...current, email: event.target.value }))} maxLength={255} />
     <ModalFooter>
      <Button type="button" variant="secondary" onClick={() => setInviteOpen(false)}>Cancel</Button>
      <Button type="submit" variant="primary" loading={inviteMutation.isPending}>Send invitation</Button>
     </ModalFooter>
    </form>
   </Modal>

   <ConfirmDialog
    isOpen={confirmAction !== null}
    onClose={() => setConfirmAction(null)}
    title="Deactivate customer access?"
    description={`${confirmAction?.user.name ?? 'This contact'} will no longer be able to sign in to the customer portal.`}
    variant="danger"
    confirmLabel="Deactivate"
    pending={deactivateMutation.isPending}
    onConfirm={() => {
     if (!confirmAction) return;
     deactivateMutation.mutate(confirmAction.user.id);
    }}
   />
  </>
 );
}
