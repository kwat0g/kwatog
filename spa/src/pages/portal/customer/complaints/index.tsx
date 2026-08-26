import { PortalTable } from '@/components/portal/PortalTable';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { LuPlus, LuX, LuFileText } from '@/lib/icons';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Chip } from '@/components/ui/Chip';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime } from '@/lib/formatDate';
import { useDebounce } from '@/hooks/useDebounce';
import type { EightDReportData } from '@/types/b2b';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { LinkButton } from '@/components/ui/LinkButton';
import { CompanyName } from '@/components/brand/CompanyName';

export default function CustomerComplaintsPage() {
 const queryClient = useQueryClient();
 const [showForm, setShowForm] = useState(false);
 const [orderId, setOrderId] = useState('');
 const [severity, setSeverity] = useState('');
 const [description, setDescription] = useState('');
 const [affectedQty, setAffectedQty] = useState('');
 const [page, setPage] = useState(1);
 const [perPage, setPerPage] = useState(25);
 const [statusFilter, setStatusFilter] = useState('');
 const [search, setSearch] = useState('');
 const [dateFrom, setDateFrom] = useState('');
 const [dateTo, setDateTo] = useState('');
 const [viewing8d, setViewing8d] = useState<EightDReportData | null>(null);
 const debouncedSearch = useDebounce(search, 300);

 const { data: complaintOptions } = useQuery({
 queryKey: ['portal', 'customer', 'complaint-options'],
 queryFn: () => customerPortalApi.complaintOptions(),
 });

 // Order provenance for the complaint form. The backend already validates that
 // the id decodes, belongs to THIS customer and is not cancelled
 // (CreateComplaintRequest), so this select only has to offer a sane shortlist —
 // it is never the authority on ownership. Cancelled orders are filtered out
 // because the server refuses them, and only fetched while the form is open.
 const { data: linkableOrdersPage } = useQuery({
 queryKey: ['portal', 'customer', 'complaint-linkable-orders'],
 queryFn: () => customerPortalApi.listOrders({ per_page: 100 }),
 enabled: showForm,
 });
 const linkableOrders = (linkableOrdersPage?.data ?? []).filter((o) => o.status !== 'cancelled');

 const { data: complaintsPage, isLoading, isError, refetch } = useQuery({
 queryKey: ['portal', 'customer', 'complaints', { page, perPage, status: statusFilter, search: debouncedSearch, dateFrom, dateTo }],
 queryFn: () => customerPortalApi.listComplaints({
 page,
 per_page: perPage,
 status: statusFilter || undefined,
 search: debouncedSearch || undefined,
 date_from: dateFrom || undefined,
 date_to: dateTo || undefined,
 }),
 placeholderData: (prev) => prev,
 });
 const complaints = complaintsPage?.data ?? [];

 const createMut = useMutation({
 mutationFn: () => customerPortalApi.createComplaint({
 order_id: orderId || undefined,
 severity,
 description,
 affected_quantity: parseInt(affectedQty, 10),
 }),
 onSuccess: (res) => {
 toast.success(res.message ?? 'Complaint submitted.');
 setShowForm(false);
 setDescription('');
 setSeverity('');
 setAffectedQty('');
 setOrderId('');
 queryClient.invalidateQueries({ queryKey: ['portal', 'customer', 'complaints'] });
 },
 // Surface the server's own rule text — "The order ID is invalid or does not
 // belong to your account." is far more actionable than a generic failure.
 onError: (e: Error & { response?: { data?: { message?: string } } }) =>
 toast.error(e.response?.data?.message ?? 'Failed to submit complaint.'),
 });

 const open8d = async (complaintId: string) => {
 try {
 const data = await customerPortalApi.get8dReport(complaintId);
 setViewing8d(data);
 } catch {
 toast.error('No 8D report available for this complaint.');
 }
 };

 return (
 <div>
 <PageHeader
 title="Complaints"
 subtitle={<>Quality issues you have reported to <CompanyName /></>}
 actions={
 <Button variant="primary" size="sm" icon={showForm ? <LuX size={14} /> : <LuPlus size={14} />} onClick={() => setShowForm(!showForm)}>
 {showForm ? 'Cancel' : 'New complaint'}
 </Button>
 }
 />

 {/* One padded body holds every state, so loading and loaded agree on width. */}
 <div className="px-5 py-4 space-y-4 max-w-5xl">
 {isLoading && <SkeletonBlock className="h-64 rounded-md" />}

 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load complaints"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}

 {/* New complaint form */}
 {!isLoading && !isError && showForm && (
 <Panel title="Submit a complaint">
 <form onSubmit={(e) => { e.preventDefault(); createMut.mutate(); }} className="flex flex-col gap-3">
 <Select
 label="Related order"
 helper="Optional. Linking the order carries your complaint through to the quality investigation."
 value={orderId}
 onChange={(e) => setOrderId(e.target.value)}
 >
 <option value="">— Not order specific —</option>
 {linkableOrders.map((order) => (
 <option key={order.id} value={order.id}>
 {order.so_number}{order.date ? ` · ${order.date}` : ''}
 </option>
 ))}
 </Select>
 <Select label="Severity" value={severity} onChange={(e) => setSeverity(e.target.value)}>
 <option value="">— Select —</option>
 {(complaintOptions?.severities ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
 </Select>
 <Textarea
 label="Description"
 value={description}
 onChange={(e) => setDescription(e.target.value)}
 rows={3}
 required
 placeholder="Describe the issue…"
 />
 <Input
 label="Affected quantity"
 type="number"
 min={1}
 required
 value={affectedQty}
 onChange={(e) => setAffectedQty(e.target.value)}
 className="font-mono tabular-nums"
 />
 <Button type="submit" variant="primary" size="sm" loading={createMut.isPending} className="self-start">
 Submit complaint
 </Button>
 </form>
 </Panel>
 )}

 {/* Complaints list */}
 {!isLoading && !isError && (
 <Panel noPadding>
 <div className="flex flex-wrap items-end gap-3 border-b border-default px-4 py-3">
 <Input
 label="Search"
 value={search}
 onChange={(e) => {
 setSearch(e.target.value);
 setPage(1);
 }}
 placeholder="Complaint number or description…"
 containerClassName="min-w-64 flex-1"
 />
 <Select
 label="Status"
 value={statusFilter}
 onChange={(e) => {
 setStatusFilter(e.target.value);
 setPage(1);
 }}
 containerClassName="w-52"
 >
 <option value="">All statuses</option>
 {(complaintOptions?.statuses ?? []).map((option) => (
 <option key={option.value} value={option.value}>{option.label}</option>
 ))}
 </Select>
 <Input
 label="From"
 type="date"
 value={dateFrom}
 onChange={(e) => {
 setDateFrom(e.target.value);
 setPage(1);
 }}
 containerClassName="w-40"
 />
 <Input
 label="To"
 type="date"
 value={dateTo}
 onChange={(e) => {
 setDateTo(e.target.value);
 setPage(1);
 }}
 containerClassName="w-40"
 />
 </div>
 {complaints && complaints.length > 0 ? (
 <>
 <PortalTable>
<table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>#</Th>
 <Th>Severity</Th>
 <Th>Description</Th>
 <Th align="right">Qty</Th>
 <Th>Date</Th>
 <Th align="right">Status</Th>
 <Th align="right">8D</Th>
 </tr>
 </thead>
 <tbody>
 {complaints.map((c) => (
 <tr key={c.id} className={trCls}>
 <Td mono className="text-muted">{c.complaint_number}</Td>
 <Td>
 <Chip variant={c.severity === 'critical' ? 'danger' : ['high', 'medium'].includes(c.severity) ? 'warning' : 'neutral'}>
 {c.severity_label ?? c.severity}
 </Chip>
 </Td>
 <Td className="max-w-xs truncate">{c.description}</Td>
 <Td align="right" mono>{c.affected_quantity}</Td>
 <Td className="text-muted">{c.received_date ?? '—'}</Td>
 <Td align="right" mono>
 <Chip variant={c.status === 'closed' ? 'success' : c.status === 'resolved' ? 'info' : 'warning'}>
 {c.status_label ?? c.status}
 </Chip>
 </Td>
 <Td align="right" mono>
 {(c.status === 'resolved' || c.status === 'closed') && (
 <LinkButton
 onClick={() => open8d(c.id)}
 icon={<LuFileText size={12} />}
 className="text-2xs"
 title="View 8D report"
 >
 8D
 </LinkButton>
 )}
 </Td>
 </tr>
 ))}
 </tbody>
 </table>
</PortalTable>
 {complaintsPage?.meta && (
 <div className="px-4 pb-4">
 <DataTablePagination
 meta={complaintsPage.meta}
 onPageChange={setPage}
 onPageSizeChange={(nextPerPage) => {
 setPerPage(nextPerPage);
 setPage(1);
 }}
 perPage={perPage}
 />
 </div>
 )}
 </>
 ) : (
 <EmptyState
 icon="message-square"
 title="No complaints"
 description={search || statusFilter || dateFrom || dateTo ? 'No complaints match the selected filters.' : 'Any reported issues will appear here.'}
 />
 )}
 </Panel>
 )}
 </div>

 {/* 8D Report Modal */}
 <Modal isOpen={!!viewing8d} onClose={() => setViewing8d(null)} size="lg">
 {viewing8d && (
 <div className="pb-5">
 <div className="flex items-center justify-between -mx-4 px-5 py-3 border-b border-default mb-4">
 <div>
 <h3 className="text-sm font-medium">8D Report &mdash; {viewing8d.complaint_number}</h3>
 <p className="text-2xs text-muted mt-0.5">
 {viewing8d.severity_label ?? viewing8d.severity} &middot; {viewing8d.complaint_status_label ?? viewing8d.complaint_status}
 </p>
 </div>
 <Button
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuX size={16} />}
 aria-label="Close 8D report"
 onClick={() => setViewing8d(null)}
 className="text-muted hover:text-primary"
 />
 </div>
 <div className="space-y-4 max-h-[70vh] overflow-y-auto">
 <p className="text-xs text-muted">{viewing8d.description}</p>

 {viewing8d.report ? (
 <div className="space-y-3">
 {[
 { key: 'd1_team', label: 'D1: Team Members', val: viewing8d.report.d1_team },
 { key: 'd2_problem', label: 'D2: Problem Description', val: viewing8d.report.d2_problem },
 { key: 'd3_containment', label: 'D3: Containment Actions', val: viewing8d.report.d3_containment },
 { key: 'd4_root_cause', label: 'D4: Root Cause Analysis', val: viewing8d.report.d4_root_cause },
 { key: 'd5_corrective_action', label: 'D5: Corrective Actions', val: viewing8d.report.d5_corrective_action },
 { key: 'd6_verification', label: 'D6: Verification of Effectiveness', val: viewing8d.report.d6_verification },
 { key: 'd7_prevention', label: 'D7: Preventive Actions', val: viewing8d.report.d7_prevention },
 { key: 'd8_recognition', label: 'D8: Recognition & Closure', val: viewing8d.report.d8_recognition },
 ].map((d) => (
 <div key={d.key} className="border border-default rounded-md p-3">
 <h4 className="text-2xs font-medium uppercase tracking-wide text-muted mb-1.5">{d.label}</h4>
 <p className="text-xs whitespace-pre-wrap">{d.val ?? '—'}</p>
 </div>
 ))}
 {viewing8d.report.finalized_at && (
 <p className="text-2xs text-muted text-right">
 Finalized: {formatDateTime(viewing8d.report.finalized_at)}
 </p>
 )}
 </div>
 ) : (
 <p className="text-xs text-muted text-center py-4">No 8D report data available yet.</p>
 )}
 </div>
 </div>
 )}
 </Modal>
 </div>
 );
}
