import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import toast from 'react-hot-toast';
import { LuPlus, LuX, LuFileText } from '@/lib/icons';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { EightDReportData, PortalComplaint } from '@/types/b2b';
import { LinkButton } from '@/components/ui/LinkButton';
import { CompanyName } from '@/components/brand/CompanyName';

type ComplaintFilters = {
  page: number;
  per_page: number;
  status?: string;
  search?: string;
  date_from?: string;
  date_to?: string;
};

const DEFAULT_FILTERS: ComplaintFilters = { page: 1, per_page: 25 };

export default function CustomerComplaintsPage() {
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [orderId, setOrderId] = useState('');
  const [severity, setSeverity] = useState('');
  const [description, setDescription] = useState('');
  const [affectedQty, setAffectedQty] = useState('');
  const [viewing8d, setViewing8d] = useState<EightDReportData | null>(null);
  const [filters, setFilters] = useUrlFilters<ComplaintFilters>(DEFAULT_FILTERS);

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

  const {
    data: complaintsPage,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['portal', 'customer', 'complaints', filters],
    queryFn: () =>
      customerPortalApi.listComplaints({
        page: filters.page,
        per_page: filters.per_page,
        status: filters.status || undefined,
        search: filters.search || undefined,
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
      }),
    placeholderData: (prev) => prev,
  });
  const complaints = complaintsPage?.data ?? [];

  const createMut = useMutation({
    mutationFn: () =>
      customerPortalApi.createComplaint({
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

  const columns: Column<PortalComplaint>[] = [
    {
      key: 'complaint_number',
      header: '#',
      cell: (r) => <span className="font-mono text-muted">{r.complaint_number}</span>,
    },
    {
      key: 'severity',
      header: 'Severity',
      cell: (r) => (
        <Chip
          variant={
            r.severity === 'critical'
              ? 'danger'
              : ['high', 'medium'].includes(r.severity)
                ? 'warning'
                : 'neutral'
          }
        >
          {r.severity_label ?? r.severity}
        </Chip>
      ),
    },
    {
      key: 'description',
      header: 'Description',
      className: 'max-w-md',
      cell: (r) => <span className="block truncate">{r.description}</span>,
    },
    {
      key: 'affected_quantity',
      header: 'Qty',
      align: 'right',
      cell: (r) => <NumCell>{r.affected_quantity}</NumCell>,
    },
    {
      key: 'received_date',
      header: 'Date',
      cell: (r) => <span className="font-mono">{r.received_date ? formatDate(r.received_date) : '—'}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <Chip
          variant={
            r.status === 'closed' ? 'success' : r.status === 'resolved' ? 'info' : 'warning'
          }
        >
          {r.status_label ?? r.status}
        </Chip>
      ),
    },
    {
      key: 'report',
      header: '8D',
      align: 'right',
      togglable: false,
      cell: (r) =>
        r.status === 'resolved' || r.status === 'closed' ? (
          <LinkButton
            onClick={() => open8d(r.id)}
            icon={<LuFileText size={12} />}
            className="text-2xs"
            title="View 8D report"
          >
            8D
          </LinkButton>
        ) : null,
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        ...(complaintOptions?.statuses ?? []).map((option) => ({
          value: option.value,
          label: option.label,
        })),
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Complaints"
        subtitle={
          complaintsPage ? (
            <>{complaintsPage.meta.total} quality issues reported to <CompanyName /></>
          ) : (
            <>Quality issues you have reported to <CompanyName /></>
          )
        }
        actions={
          <Button
            variant="primary"
            size="sm"
            icon={showForm ? <LuX size={14} /> : <LuPlus size={14} />}
            onClick={() => setShowForm(!showForm)}
          >
            {showForm ? 'Cancel' : 'New complaint'}
          </Button>
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((current) => ({ ...current, search: search || undefined, page: 1 }))}
        onFilter={(key, value) => setFilters((current) => ({ ...current, [key]: value || undefined, page: 1 }))}
        searchPlaceholder="Search complaint number or description…"
        dateRange={{ fromKey: 'date_from', toKey: 'date_to', label: 'Received date' }}
      />

      <div className="px-5 py-4 space-y-4">
        {showForm && (
          <Panel title="Submit a complaint">
            <form
              onSubmit={(e) => {
                e.preventDefault();
                createMut.mutate();
              }}
              className="flex flex-col gap-3"
            >
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Select
                  label="Related order"
                  helper="Optional. Linking the order carries your complaint through to the quality investigation."
                  value={orderId}
                  onChange={(e) => setOrderId(e.target.value)}
                >
                  <option value="">— Not order specific —</option>
                  {linkableOrders.map((order) => (
                    <option key={order.id} value={order.id}>
                      {order.so_number}
                      {order.date ? ` · ${order.date}` : ''}
                    </option>
                  ))}
                </Select>
                <Select
                  label="Severity"
                  value={severity}
                  onChange={(e) => setSeverity(e.target.value)}
                >
                  <option value="">— Select —</option>
                  {(complaintOptions?.severities ?? []).map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </Select>
              </div>
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
                containerClassName="max-w-xs"
              />
              <div className="flex justify-end gap-2 pt-2 border-t border-default">
                <Button type="button" variant="secondary" size="sm" onClick={() => setShowForm(false)}>
                  Cancel
                </Button>
                <Button
                  type="submit"
                  variant="primary"
                  size="sm"
                  disabled={!description.trim() || !severity || !affectedQty}
                  loading={createMut.isPending}
                >
                  Submit complaint
                </Button>
              </div>
            </form>
          </Panel>
        )}

        {isLoading && !complaintsPage && <SkeletonTable columns={7} rows={8} />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load complaints"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {complaintsPage && (
          <DataTable
            tableKey="portal-customer-complaints"
            columns={columns}
            data={complaints}
            meta={complaintsPage.meta}
            onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
            onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
            emptyState={
              <EmptyState
                icon="message-square"
                title="No complaints"
                description={
                  filters.search || filters.status || filters.date_from || filters.date_to
                    ? 'No complaints match the selected filters.'
                    : 'Any reported issues will appear here.'
                }
              />
            }
          />
        )}
      </div>

      {/* 8D Report Modal */}
      <Modal isOpen={!!viewing8d} onClose={() => setViewing8d(null)} size="lg">
        {viewing8d && (
          <div className="pb-5">
            <div className="flex items-center justify-between -mx-4 px-5 py-3 border-b border-default mb-4">
              <div>
                <h3 className="text-sm font-medium">
                  8D Report &mdash; {viewing8d.complaint_number}
                </h3>
                <p className="text-2xs text-muted mt-0.5">
                  {viewing8d.severity_label ?? viewing8d.severity} &middot;{' '}
                  {viewing8d.complaint_status_label ?? viewing8d.complaint_status}
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
                    {
                      key: 'd2_problem',
                      label: 'D2: Problem Description',
                      val: viewing8d.report.d2_problem,
                    },
                    {
                      key: 'd3_containment',
                      label: 'D3: Containment Actions',
                      val: viewing8d.report.d3_containment,
                    },
                    {
                      key: 'd4_root_cause',
                      label: 'D4: Root Cause Analysis',
                      val: viewing8d.report.d4_root_cause,
                    },
                    {
                      key: 'd5_corrective_action',
                      label: 'D5: Corrective Actions',
                      val: viewing8d.report.d5_corrective_action,
                    },
                    {
                      key: 'd6_verification',
                      label: 'D6: Verification of Effectiveness',
                      val: viewing8d.report.d6_verification,
                    },
                    {
                      key: 'd7_prevention',
                      label: 'D7: Preventive Actions',
                      val: viewing8d.report.d7_prevention,
                    },
                    {
                      key: 'd8_recognition',
                      label: 'D8: Recognition & Closure',
                      val: viewing8d.report.d8_recognition,
                    },
                  ].map((d) => (
                    <div key={d.key} className="border border-default rounded-md p-3">
                      <h4 className="text-2xs font-medium uppercase tracking-wide text-muted mb-1.5">
                        {d.label}
                      </h4>
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
                <p className="text-xs text-muted text-center py-4">
                  No 8D report data available yet.
                </p>
              )}
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
