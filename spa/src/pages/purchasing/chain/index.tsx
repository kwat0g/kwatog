import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ArrowRight, AlertTriangle, CheckCircle, Clock, FileText, ShoppingCart, Package, Receipt, Scale, type LucideIcon } from 'lucide-react';
import { procurementChainApi } from '@/api/purchasing/purchase-orders';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';


export default function ProcurementChainPage() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'chain'],
    queryFn: () => procurementChainApi.overview(),
    refetchInterval: 60_000,
  });

  if (isLoading && !data) return (
    <div>
      <PageHeader title="Procurement Chain" subtitle="Overview of the procure-to-pay pipeline" />
      <div className="px-5 py-4"><SkeletonDetail /></div>
    </div>
  );

  if (isError) return (
    <div>
      <PageHeader title="Procurement Chain" />
      <EmptyState icon="alert-circle" title="Failed to load chain data"
        action={<button className="text-accent hover:underline text-sm" onClick={() => refetch()}>Retry</button>} />
    </div>
  );

  if (!data) return null;

  const mr = data.material_requirements;
  const rc = data.receiving;
  const bl = data.billing;
  const tw = data.three_way_match;
  const totalBillsUnpaid = bl.bills_unpaid;
  const overdueUrgency = bl.bills_overdue > 0 ? 'warning' : bl.bills_unpaid > 0 ? 'info' : 'success';

  return (
    <div>
      <PageHeader
        title="Procurement Chain"
        subtitle="End-to-end procure-to-pay pipeline overview"
      />

      <div className="px-5 py-4 space-y-6">
        {/* ── Pipeline Stages ── */}
        <div className="grid grid-cols-4 gap-3">
          <StatCard
            label="Material Requirements"
            value={mr.pr_pending + mr.po_draft}
            helper={`${mr.pr_pending} pending PRs · ${mr.po_draft} draft POs`}
            linkTo="/purchasing/purchase-orders"
          />
          <StatCard
            label="Sent / Approved"
            value={mr.po_sent}
            helper={`${mr.pr_approved} approved PRs`}
            linkTo="/purchasing/purchase-orders?status=approved"
          />
          <StatCard
            label="Receiving"
            value={rc.grn_received}
            helper={`${rc.grn_pending_qc} pending QC`}
            linkTo="/inventory/grn"
          />
          <StatCard
            label="Bills"
            value={totalBillsUnpaid}
            helper={`${bl.bills_overdue} overdue · ${formatPeso(bl.bills_this_month)} due this month`}
            linkTo="/accounting/bills"
          />
        </div>

        {/* ── Detail Sections ── */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {/* Material Requirements */}
          <Panel title="Material Requirements">
            <div className="space-y-3">
              <StageRow
                label="Pending PRs"
                count={mr.pr_pending}
                max={mr.pr_pending + mr.pr_approved}
                color="warning"
              />
              <StageRow
                label="Approved PRs"
                count={mr.pr_approved}
                max={mr.pr_pending + mr.pr_approved}
                color="success"
              />
              <div className="border-t border-subtle pt-2">
                <StageRow
                  label="Draft POs"
                  count={mr.po_draft}
                  max={mr.po_draft + mr.po_sent + mr.po_partially_received + mr.po_received}
                  color="neutral"
                />
              </div>
              <StageRow
                label="Sent POs"
                count={mr.po_sent}
                max={mr.po_draft + mr.po_sent + mr.po_partially_received + mr.po_received}
                color="info"
              />
              <StageRow
                label="Partially received"
                count={mr.po_partially_received}
                max={mr.po_draft + mr.po_sent + mr.po_partially_received + mr.po_received}
                color="warning"
              />
              <StageRow
                label="Received"
                count={mr.po_received}
                max={mr.po_draft + mr.po_sent + mr.po_partially_received + mr.po_received}
                color="success"
              />
              <div className="pt-2 text-xs text-right">
                <Link to="/purchasing/purchase-orders" className="text-accent hover:underline inline-flex items-center gap-1">
                  View all POs <ArrowRight size={12} />
                </Link>
              </div>
            </div>
          </Panel>

          {/* Receiving */}
          <Panel title="Receiving (GRN)">
            <div className="space-y-3">
              <StageRow
                label="Pending QC"
                count={rc.grn_pending_qc}
                max={rc.grn_pending_qc + rc.grn_received}
                color="warning"
              />
              <StageRow
                label="Accepted / Received"
                count={rc.grn_received}
                max={rc.grn_pending_qc + rc.grn_received}
                color="success"
              />
              <div className="pt-2 text-xs text-right">
                <Link to="/inventory/grn" className="text-accent hover:underline inline-flex items-center gap-1">
                  View GRNs <ArrowRight size={12} />
                </Link>
              </div>
            </div>
          </Panel>

          {/* Billing */}
          <Panel title="Billing (AP)">
            <div className="space-y-3">
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted">Unpaid bills</span>
                <span className="font-mono tabular-nums font-medium">{bl.bills_unpaid}</span>
              </div>
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted">Overdue</span>
                <span className="font-mono tabular-nums font-medium">{bl.bills_overdue}</span>
              </div>
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted">Due this month</span>
                <span className="font-mono tabular-nums font-medium">{formatPeso(bl.bills_this_month)}</span>
              </div>
              <Chip variant={overdueUrgency}>
                {bl.bills_overdue > 0
                  ? `${bl.bills_overdue} overdue`
                  : bl.bills_unpaid > 0
                    ? 'All current'
                    : 'All paid'}
              </Chip>
              <div className="pt-2 text-xs text-right">
                <Link to="/accounting/bills" className="text-accent hover:underline inline-flex items-center gap-1">
                  View bills <ArrowRight size={12} />
                </Link>
              </div>
            </div>
          </Panel>

          {/* 3-Way Match */}
          <Panel title="3-Way Match">
            <div className="space-y-3">
              <div className="flex items-center justify-between text-sm">
                <span className="flex items-center gap-1.5">
                  <CheckCircle size={14} className="text-success" />
                  Matched
                </span>
                <span className="font-mono tabular-nums font-medium">{tw.matched}</span>
              </div>
              <div className="flex items-center justify-between text-sm">
                <span className="flex items-center gap-1.5">
                  <AlertTriangle size={14} className={tw.has_variances > 0 ? 'text-warning' : 'text-muted'} />
                  With variances
                </span>
                <span className="font-mono tabular-nums font-medium">{tw.has_variances}</span>
              </div>
              <div className="flex items-center justify-between text-sm">
                <span className="flex items-center gap-1.5">
                  <Clock size={14} className={tw.overridden > 0 ? 'text-info' : 'text-muted'} />
                  Overridden
                </span>
                <span className="font-mono tabular-nums font-medium">{tw.overridden}</span>
              </div>
              <div className="pt-2 flex items-center gap-2">
                <Chip variant={tw.has_variances > 0 ? 'warning' : 'success'}>
                  {tw.has_variances > 0 ? 'Variances detected' : 'All matched'}
                </Chip>
              </div>
            </div>
          </Panel>
        </div>

        {/* ── Pipeline Flow ── */}
        <Panel title="Procure-to-Pay Flow">
          <div className="flex items-center gap-0.5 text-xs">
            <FlowStep icon={FileText} label="PR" count={mr.pr_pending + mr.pr_approved} active={mr.pr_pending > 0} />
            <FlowArrow />
            <FlowStep icon={ShoppingCart} label="PO" count={mr.po_draft + mr.po_sent + mr.po_partially_received + mr.po_received} active={mr.po_draft > 0 || mr.po_sent > 0} />
            <FlowArrow />
            <FlowStep icon={Package} label="GRN" count={rc.grn_received + rc.grn_pending_qc} active={rc.grn_pending_qc > 0 || rc.grn_received > 0} />
            <FlowArrow />
            <FlowStep icon={Receipt} label="Bill" count={bl.bills_unpaid} active={bl.bills_unpaid > 0} />
            <FlowArrow />
            <FlowStep icon={Scale} label="Match" count={tw.matched + tw.has_variances + tw.overridden} active={tw.has_variances > 0} />
          </div>
        </Panel>
      </div>
    </div>
  );
}

/* ── Sub-components ── */

interface StageRowProps {
  label: string;
  count: number;
  max: number;
  color: 'success' | 'info' | 'warning' | 'neutral';
}

function StageRow({ label, count, max, color }: StageRowProps) {
  const pct = max > 0 ? Math.round((count / max) * 100) : 0;
  const barColor = {
    success: 'bg-success',
    info: 'bg-info',
    warning: 'bg-warning',
    neutral: 'bg-text-subtle',
  }[color];
  return (
    <div className="flex items-center gap-3 text-sm">
      <span className="w-36 shrink-0 text-muted">{label}</span>
      <div className="flex-1 h-2 bg-subtle rounded-full overflow-hidden">
        <div className={`h-full ${barColor} rounded-full transition-all duration-500`} style={{ width: `${pct}%` }} />
      </div>
      <span className="w-12 text-right font-mono tabular-nums font-medium">{count}</span>
    </div>
  );
}

interface FlowStepProps {
  icon: LucideIcon;
  label: string;
  count: number;
  active: boolean;
}

function FlowStep({ icon: Icon, label, count, active }: FlowStepProps) {
  return (
    <div className={`flex flex-col items-center gap-1 flex-1 px-2 py-2 rounded ${active ? 'bg-elevated' : 'opacity-50'}`}>
      <Icon size={16} className={active ? 'text-accent' : 'text-muted'} />
      <span className="font-medium text-2xs uppercase tracking-wider">{label}</span>
      <span className="font-mono tabular-nums text-xs">{count}</span>
    </div>
  );
}

function FlowArrow() {
  return <ArrowRight size={14} className="text-muted shrink-0" />;
}
