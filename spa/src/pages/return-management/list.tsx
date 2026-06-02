import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Plus } from 'lucide-react';
import { returnManagementApi } from '@/api/returnManagement';
import { usePermission } from '@/hooks/usePermission';
import type { ReturnRequest } from '@/types/returnManagement';

const STATUS_COLORS: Record<string, string> = {
  draft: 'bg-gray-500/20 text-gray-500 border-gray-500/30',
  pending_approval: 'bg-yellow-500/20 text-yellow-500 border-yellow-500/30',
  approved: 'bg-blue-500/20 text-blue-500 border-blue-500/30',
  received: 'bg-indigo-500/20 text-indigo-500 border-indigo-500/30',
  inspected: 'bg-purple-500/20 text-purple-500 border-purple-500/30',
  completed: 'bg-green-500/20 text-green-500 border-green-500/30',
  rejected: 'bg-red-500/20 text-red-500 border-red-500/30',
  cancelled: 'bg-gray-500/10 text-gray-400 border-gray-500/20',
};

const TYPE_COLORS: Record<string, string> = {
  customer_return: 'bg-cyan-500/20 text-cyan-500 border-cyan-500/30',
  supplier_return: 'bg-orange-500/20 text-orange-500 border-orange-500/30',
};

function StatusBadge({ status, statusLabel }: { status: string; statusLabel?: string }) {
  return (
    <Badge variant="accent" className={STATUS_COLORS[status] || ''}>
      {statusLabel || status}
    </Badge>
  );
}

function TypeBadge({ type, typeLabel }: { type: string; typeLabel?: string }) {
  return (
    <Badge variant="accent" className={TYPE_COLORS[type] || ''}>
      {typeLabel || type}
    </Badge>
  );
}

export default function ReturnManagementListPage() {
  const [typeFilter, setTypeFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);
  const { can } = usePermission();

  const { data, isLoading } = useQuery({
    queryKey: ['return-requests', typeFilter, statusFilter, page],
    queryFn: () =>
      returnManagementApi.list({
        type: typeFilter || undefined,
        status: statusFilter || undefined,
        per_page: 25,
        page,
      }),
  });

  const items: ReturnRequest[] = data?.data ?? [];
  const meta = data?.meta;

  return (
    <div>
      <PageHeader
        title="Return Management (RMA)"
        subtitle="Customer returns & supplier returns"
        backTo="/dashboard"
        actions={
          can('return_management.manage') ? (
            <Link to="/return-management/new">
              <Button variant="primary" icon={<Plus size={14} />}>
                New RMA
              </Button>
            </Link>
          ) : null
        }
      />

      {/* Filters */}
      <div className="flex gap-3 mb-4 px-4">
        <select
          className="input text-sm"
          value={typeFilter}
          onChange={(e) => { setTypeFilter(e.target.value); setPage(1); }}
        >
          <option value="">All types</option>
          <option value="customer_return">Customer returns</option>
          <option value="supplier_return">Supplier returns</option>
        </select>
        <select
          className="input text-sm"
          value={statusFilter}
          onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
        >
          <option value="">All statuses</option>
          <option value="draft">Draft</option>
          <option value="pending_approval">Pending Approval</option>
          <option value="approved">Approved</option>
          <option value="received">Received</option>
          <option value="inspected">Inspected</option>
          <option value="completed">Completed</option>
          <option value="rejected">Rejected</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>

      {/* Table */}
      <div className="px-4">
        {isLoading ? (
          <div className="text-center text-muted py-12">Loading…</div>
        ) : items.length === 0 ? (
          <div className="text-center text-muted py-12">No return requests found.</div>
        ) : (
          <>
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-default text-left text-xs uppercase tracking-wider text-muted">
                  <th className="py-2 pr-3 font-medium">RMA #</th>
                  <th className="py-2 pr-3 font-medium">Type</th>
                  <th className="py-2 pr-3 font-medium">Source</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 pr-3 font-medium">Reason</th>
                  <th className="py-2 pr-3 font-medium">Items</th>
                  <th className="py-2 pr-3 font-medium">Date</th>
                </tr>
              </thead>
              <tbody>
                {items.map((rma) => (
                  <tr key={rma.id} className="border-b border-default hover:bg-elevated/50 transition-colors">
                    <td className="py-2.5 pr-3">
                      <Link to={`/return-management/${rma.id}`} className="text-accent hover:underline font-medium">
                        {rma.rma_number}
                      </Link>
                    </td>
                    <td className="py-2.5 pr-3">
                      <TypeBadge type={rma.type} typeLabel={rma.type_label} />
                    </td>
                    <td className="py-2.5 pr-3 text-secondary">
                      {rma.customer?.name || rma.vendor?.name || rma.source_label || '—'}
                    </td>
                    <td className="py-2.5 pr-3">
                      <StatusBadge status={rma.status} statusLabel={rma.status_label} />
                    </td>
                    <td className="py-2.5 pr-3 text-secondary max-w-[160px] truncate">
                      {rma.reason_description || rma.reason_code || '—'}
                    </td>
                    <td className="py-2.5 pr-3 text-secondary">{rma.item_count}</td>
                    <td className="py-2.5 pr-3 text-secondary">
                      {rma.return_date ? new Date(rma.return_date).toLocaleDateString() : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {/* Pagination */}
            {meta && meta.last_page > 1 && (
              <div className="flex justify-center gap-2 mt-4">
                {Array.from({ length: meta.last_page }, (_, i) => i + 1).map((p) => (
                  <button
                    key={p}
                    onClick={() => setPage(p)}
                    className={`px-3 py-1 text-sm rounded ${p === meta.current_page ? 'bg-accent text-white' : 'bg-elevated text-secondary hover:text-primary'}`}
                  >
                    {p}
                  </button>
                ))}
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
