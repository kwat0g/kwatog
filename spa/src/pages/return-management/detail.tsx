import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { returnManagementApi } from '@/api/returnManagement';
import { usePermission } from '@/hooks/usePermission';

const STATUS_VARIANT: Record<string, 'success' | 'danger' | 'warning' | 'info' | 'neutral' | 'purple'> = {
  draft: 'neutral',
  pending_approval: 'warning',
  approved: 'info',
  received: 'info',
  inspected: 'purple',
  completed: 'success',
  rejected: 'danger',
  cancelled: 'neutral',
};

const CONDITION_LABELS: Record<string, string> = {
  new: 'New',
  used: 'Used',
  damaged: 'Damaged',
  defective: 'Defective',
  obsolete: 'Obsolete',
};

const REASON_LABELS: Record<string, string> = {
  defective: 'Defective product',
  damaged: 'Damaged in transit',
  wrong_item: 'Wrong item shipped',
  excess: 'Excess quantity',
  customer_change: 'Customer changed mind',
  quality_issue: 'Quality issue',
  other: 'Other',
};

const RESOLUTION_LABELS: Record<string, string> = {
  replace: 'Replace',
  refund: 'Refund',
  credit_note: 'Credit Note',
  scrap: 'Scrap',
  return_to_vendor: 'Return to Vendor',
};

export default function ReturnRequestDetailPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [confirmAction, setConfirmAction] = useState<string | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [locationId, setLocationId] = useState('');

  const { data: rma, isLoading } = useQuery({
    queryKey: ['return-request', id],
    queryFn: () => returnManagementApi.get(id!),
    enabled: !!id,
  });

  const actionMutation = useMutation({
    mutationFn: async ({ action, payload }: { action: string; payload?: Record<string, unknown> }) => {
      switch (action) {
        case 'submit': return returnManagementApi.submit(id!);
        case 'approve': return returnManagementApi.approve(id!);
        case 'receive': return returnManagementApi.receive(id!);
        case 'inspect': return returnManagementApi.inspect(id!);
        case 'complete': return returnManagementApi.complete(id!, payload?.locationId as string);
        case 'reject': return returnManagementApi.reject(id!, payload?.reason as string);
        case 'cancel': return returnManagementApi.cancel(id!);
        default: throw new Error(`Unknown action: ${action}`);
      }
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['return-request', id] });
      setConfirmAction(null);
      setRejectReason('');
    },
  });

  const availableActions = (status?: string): Array<{ key: string; label: string; variant?: 'primary' | 'danger' | 'default' }> => {
    if (!status) return [];
    switch (status) {
      case 'draft': return [
        { key: 'submit', label: 'Submit for Approval', variant: 'primary' },
        { key: 'cancel', label: 'Cancel', variant: 'danger' },
      ];
      case 'pending_approval': return [
        { key: 'approve', label: 'Approve', variant: 'primary' },
        { key: 'reject', label: 'Reject', variant: 'danger' },
      ];
      case 'approved': return [
        { key: 'receive', label: 'Record Receipt', variant: 'primary' },
        { key: 'reject', label: 'Reject', variant: 'danger' },
      ];
      case 'received': return [
        { key: 'inspect', label: 'Complete Inspection', variant: 'primary' },
        { key: 'reject', label: 'Reject', variant: 'danger' },
      ];
      case 'inspected': return [
        { key: 'complete', label: 'Complete RMA', variant: 'primary' },
        { key: 'reject', label: 'Reject', variant: 'danger' },
      ];
      default: return [];
    }
  };

  if (isLoading) {
    return (
      <div className="px-4 py-6">
        <SkeletonBlock className="h-8 w-64 mb-4" />
        <SkeletonBlock className="h-4 w-96 mb-8" />
        <SkeletonBlock className="h-32 w-full mb-4" />
      </div>
    );
  }

  if (!rma) {
    return (
      <EmptyState icon="alert-circle" title="Return request not found" />
    );
  }

  const actions = can('return_management.manage') ? availableActions(rma.status) : [];

  return (
    <div>
      <PageHeader
        title={rma.rma_number}
        subtitle={
          <div className="flex items-center gap-2">
            <Chip variant={STATUS_VARIANT[rma.status] ?? 'neutral'}>
              {rma.status_label}
            </Chip>
            <span className="text-muted">|</span>
            <span>{rma.type_label}</span>
          </div>
        }
        backTo="/return-management"
        breadcrumbs={[{ label: 'Returns', href: '/return-management' }, { label: rma.rma_number }]}
      />

      <div className="px-4 space-y-4">
        {/* Workflow Actions */}
        {actions.length > 0 && (
          <div className="flex gap-2 flex-wrap">
            {actions.map((action) => (
              <Button
                key={action.key}
                variant={action.variant === 'danger' ? 'danger' : 'primary'}
                onClick={() => {
                  if (action.key === 'reject') {
                    setConfirmAction('reject');
                  } else {
                    actionMutation.mutate({ action: action.key });
                  }
                }}
                loading={actionMutation.isPending && confirmAction === null}
              >
                {action.label}
              </Button>
            ))}
          </div>
        )}

        {/* Details Panel */}
        <Panel title="RMA Details">
          <div className="grid grid-cols-3 gap-4 text-sm mt-2">
            <div>
              <div className="text-xs text-muted mb-0.5">Type</div>
              <div>{rma.type_label}</div>
            </div>
            <div>
              <div className="text-xs text-muted mb-0.5">Status</div>
              <Chip variant={STATUS_VARIANT[rma.status] ?? 'neutral'}>{rma.status_label}</Chip>
            </div>
            <div>
              <div className="text-xs text-muted mb-0.5">Return Date</div>
              <div>{rma.return_date ? new Date(rma.return_date).toLocaleDateString() : '—'}</div>
            </div>
            <div>
              <div className="text-xs text-muted mb-0.5">Source</div>
              <div className="flex flex-col gap-0.5">
                {rma.customer && <span>Customer: {rma.customer.name}</span>}
                {rma.vendor && <span>Vendor: {rma.vendor.name}</span>}
                {rma.sales_order && (
                  <Link to={`/crm/sales-orders/${rma.sales_order.id}`} className="text-accent hover:underline">
                    SO: {rma.sales_order.so_number}
                  </Link>
                )}
                {rma.invoice && (
                  <Link to={`/accounting/invoices/${rma.invoice.id}`} className="text-accent hover:underline">
                    Invoice: {rma.invoice.invoice_number}
                  </Link>
                )}
                {rma.purchase_order && (
                  <Link to={`/purchasing/purchase-orders/${rma.purchase_order.id}`} className="text-accent hover:underline">
                    PO: {rma.purchase_order.po_number}
                  </Link>
                )}
                {rma.bill && (
                  <Link to={`/accounting/bills/${rma.bill.id}`} className="text-accent hover:underline">
                    Bill: {rma.bill.bill_number}
                  </Link>
                )}
                {!rma.customer && !rma.vendor && !rma.sales_order && !rma.invoice && !rma.purchase_order && !rma.bill && (
                  <span className="text-muted">—</span>
                )}
              </div>
            </div>
            <div>
              <div className="text-xs text-muted mb-0.5">Reason</div>
              <div>{REASON_LABELS[rma.reason_code ?? ''] || rma.reason_code || '—'}</div>
              {rma.reason_description && (
                <div className="text-muted text-xs mt-0.5">{rma.reason_description}</div>
              )}
            </div>
            <div>
              <div className="text-xs text-muted mb-0.5">Resolution</div>
              <div>{RESOLUTION_LABELS[rma.resolution ?? ''] || rma.resolution || '—'}</div>
            </div>
          </div>

          {rma.customer_notes && (
            <div className="mt-3">
              <div className="text-xs text-muted mb-0.5">Customer Notes</div>
              <div className="text-sm bg-elevated p-2 rounded">{rma.customer_notes}</div>
            </div>
          )}

          {rma.internal_notes && (
            <div className="mt-3">
              <div className="text-xs text-muted mb-0.5">Internal Notes</div>
              <div className="text-sm bg-elevated p-2 rounded">{rma.internal_notes}</div>
            </div>
          )}

          {rma.refund_amount && (
            <div className="mt-3">
              <div className="text-xs text-muted mb-0.5">Refund Amount</div>
              <div className="text-sm font-medium">₱{parseFloat(rma.refund_amount).toLocaleString()}</div>
            </div>
          )}
        </Panel>

        {/* Timeline */}
        <Panel title="Timeline">
          <div className="space-y-2 text-sm mt-2">
            {rma.created_at && (
              <div className="flex items-center gap-2">
                <div className="h-2 w-2 rounded-full bg-gray-400" />
                <span className="text-muted text-xs">Created</span>
                <span>{new Date(rma.created_at).toLocaleString()}</span>
                {rma.creator && <span className="text-muted">by {rma.creator.name}</span>}
              </div>
            )}
            {rma.approved_at && (
              <div className="flex items-center gap-2">
                <div className="h-2 w-2 rounded-full bg-blue-500" />
                <span className="text-muted text-xs">Approved</span>
                <span>{new Date(rma.approved_at).toLocaleString()}</span>
                {rma.approved_by && <span className="text-muted">by {rma.approved_by.name}</span>}
              </div>
            )}
            {rma.received_at && (
              <div className="flex items-center gap-2">
                <div className="h-2 w-2 rounded-full bg-indigo-500" />
                <span className="text-muted text-xs">Received</span>
                <span>{new Date(rma.received_at).toLocaleString()}</span>
              </div>
            )}
            {rma.inspected_at && (
              <div className="flex items-center gap-2">
                <div className="h-2 w-2 rounded-full bg-purple-500" />
                <span className="text-muted text-xs">Inspected</span>
                <span>{new Date(rma.inspected_at).toLocaleString()}</span>
              </div>
            )}
            {rma.completed_at && (
              <div className="flex items-center gap-2">
                <div className="h-2 w-2 rounded-full bg-green-500" />
                <span className="text-muted text-xs">Completed</span>
                <span>{new Date(rma.completed_at).toLocaleString()}</span>
                {rma.approved_by && <span className="text-muted">by {rma.approved_by.name}</span>}
              </div>
            )}
            {rma.rejected_at && (
              <div className="flex items-center gap-2">
                <div className="h-2 w-2 rounded-full bg-red-500" />
                <span className="text-muted text-xs">Rejected</span>
                <span>{new Date(rma.rejected_at).toLocaleString()}</span>
              </div>
            )}
            {rma.cancelled_at && (
              <div className="flex items-center gap-2">
                <div className="h-2 w-2 rounded-full bg-gray-500" />
                <span className="text-muted text-xs">Cancelled</span>
                <span>{new Date(rma.cancelled_at).toLocaleString()}</span>
              </div>
            )}
          </div>
        </Panel>

        {/* Items */}
        <Panel title={`Items (${rma.items?.length ?? 0})`}>
          {!rma.items || rma.items.length === 0 ? (
            <div className="text-muted text-sm py-2">No items.</div>
          ) : (
            <table className="w-full text-sm mt-2">
              <thead>
                <tr className="border-b border-default text-left text-xs uppercase tracking-wider text-muted">
                  <th className="py-2 pr-3 font-medium">Product</th>
                  <th className="py-2 pr-3 font-medium">Qty</th>
                  <th className="py-2 pr-3 font-medium">Returned</th>
                  <th className="py-2 pr-3 font-medium">Unit Price</th>
                  <th className="py-2 pr-3 font-medium">Condition</th>
                  <th className="py-2 pr-3 font-medium">Reason</th>
                </tr>
              </thead>
              <tbody>
                {rma.items.map((item) => (
                  <tr key={item.id} className="border-b border-default">
                    <td className="py-2 pr-3">
                      {item.product
                        ? `${item.product.part_number} — ${item.product.name}`
                        : item.item
                          ? `${item.item.code} — ${item.item.name}`
                          : '—'}
                    </td>
                    <td className="py-2 pr-3">{parseFloat(item.quantity).toLocaleString()}</td>
                    <td className="py-2 pr-3">{parseFloat(item.returned_quantity).toLocaleString()}</td>
                    <td className="py-2 pr-3">₱{parseFloat(item.unit_price).toLocaleString()}</td>
                    <td className="py-2 pr-3">{CONDITION_LABELS[item.condition ?? ''] || item.condition || '—'}</td>
                    <td className="py-2 pr-3">{item.reason || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Panel>
      </div>

      {/* Reject Modal */}
      <Modal
        isOpen={confirmAction === 'reject'}
        onClose={() => setConfirmAction(null)}
        title="Reject Return Request"
      >
        <div className="space-y-3">
          <textarea
            className="input w-full h-24"
            placeholder="Reason for rejection..."
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
          />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirmAction(null)}>Cancel</Button>
            <Button
              variant="danger"
              loading={actionMutation.isPending}
              onClick={() => actionMutation.mutate({ action: 'reject', payload: { reason: rejectReason } })}
            >
              Confirm Reject
            </Button>
          </div>
        </div>
      </Modal>

      {/* Complete with location picker */}
      <Modal
        isOpen={confirmAction === 'complete'}
        onClose={() => setConfirmAction(null)}
        title="Complete RMA"
      >
        <div className="space-y-3">
          <p className="text-sm text-muted">Select the warehouse location for stock movement:</p>
          <input
            className="input w-full"
            placeholder="Location ID (optional)"
            value={locationId}
            onChange={(e) => setLocationId(e.target.value)}
          />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirmAction(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={actionMutation.isPending}
              onClick={() => actionMutation.mutate({ action: 'complete', payload: { locationId: locationId || undefined } })}
            >
              Confirm Complete
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
