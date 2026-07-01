import { useNavigate } from 'react-router-dom';
import { AlertTriangle, CheckCircle, ExternalLink, Info, Package, ShoppingCart } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Modal } from '@/components/ui/Modal';
import { formatInt } from '@/lib/formatNumber';
import type { SoChainResult, SoChainResultWo } from '@/types/crm';

// ─── Sub-components ────────────────────────────────────────────────────────

function WoRow({ wo }: { wo: SoChainResultWo }) {
  return (
    <div className="flex items-center justify-between text-sm border border-default rounded-md px-3 py-2">
      <div className="flex items-center gap-2 min-w-0">
        <Chip variant={wo.needs_manual_scheduling ? 'warning' : 'success'} className="shrink-0">
          {wo.needs_manual_scheduling ? 'Manual' : 'Scheduled'}
        </Chip>
        <span className="font-mono tabular-nums">{wo.wo_number}</span>
        <span className="text-muted truncate">{wo.product?.name}</span>
      </div>
      <div className="flex items-center gap-3 shrink-0">
        <span className="text-xs text-muted font-mono tabular-nums">
          {formatInt(wo.quantity_target)} pcs
        </span>
        <span className="text-xs text-muted font-mono tabular-nums">
          {wo.machine ?? 'Needs scheduling'}
        </span>
      </div>
    </div>
  );
}

function SchedulingConflictsSection({ conflicts }: { conflicts: SoChainResult['scheduling_conflicts'] }) {
  if (conflicts.length === 0) return null;

  return (
    <div>
      <h4 className="flex items-center gap-1.5 text-xs uppercase tracking-wider text-warning font-medium mb-2">
        <AlertTriangle size={14} />
        Scheduling Conflicts ({conflicts.length})
      </h4>
      <div className="space-y-1.5">
        {conflicts.map((c) => (
          <div key={c.work_order_id} className="border border-warning/20 bg-warning/5 rounded-md px-3 py-2 text-sm">
            <div className="font-mono text-warning">{c.wo_number}</div>
            <ul className="mt-1 space-y-0.5">
              {c.reasons.map((r, i) => (
                <li key={i} className="text-xs text-muted flex items-start gap-1.5">
                  <span className="text-warning mt-0.5 shrink-0">-</span>
                  {r}
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </div>
  );
}

function MaterialPlanningSection({ shortages, prs_created }: { shortages: number; prs_created: number }) {
  if (shortages === 0 && prs_created === 0) return null;

  return (
    <div>
      <h4 className="flex items-center gap-1.5 text-xs uppercase tracking-wider text-muted font-medium mb-2">
        <ShoppingCart size={14} />
        Material Planning
      </h4>
      <div className="text-sm space-y-1">
        {shortages > 0 && (
          <p className="flex items-center gap-1.5 text-warning">
            <AlertTriangle size={14} className="shrink-0" />
            {shortages} material shortage{shortages > 1 ? 's' : ''} detected
          </p>
        )}
        {prs_created > 0 && (
          <p className="flex items-center gap-1.5 text-muted">
            <CheckCircle size={14} className="shrink-0" />
            {prs_created} Purchase Request{prs_created > 1 ? 's' : ''} auto-created
          </p>
        )}
      </div>
    </div>
  );
}

// ─── Main component ────────────────────────────────────────────────────────

interface ChainResultModalProps {
  chainResult: SoChainResult | null;
  onClose: () => void;
}

/**
 * Modal displayed after SO confirmation showing the chain result:
 * - Work Orders created (with scheduling status)
 * - Material planning (shortages + auto-PRs)
 * - Scheduling conflicts (if any)
 * - Navigation to Work Orders / MRP Plan
 */
export function ChainResultModal({ chainResult, onClose }: ChainResultModalProps) {
  const navigate = useNavigate();

  if (!chainResult) return null;

  const hasConflicts = chainResult.scheduling_conflicts.length > 0;
  const hasManualWos = chainResult.needs_manual > 0;

  return (
    <Modal
      isOpen={!!chainResult}
      onClose={onClose}
      title="Sales Order Confirmed"
      size="lg"
    >
      <div className="py-4 space-y-4">
        {/* Success header */}
        <div className="flex items-center gap-2 text-sm">
          <CheckCircle size={16} className="text-success" />
          <span className="text-success font-medium">{chainResult.so_number} confirmed</span>
        </div>

        {/* Warning banner for issues */}
        {(hasConflicts || hasManualWos) && (
          <div className="flex items-start gap-2 border border-warning bg-warning-bg rounded-md px-3 py-2.5 text-sm">
            <Info size={16} className="text-warning mt-0.5 shrink-0" />
            <div className="text-muted">
              {hasManualWos && (
                <span>{chainResult.needs_manual} work order{chainResult.needs_manual > 1 ? 's' : ''} need manual machine assignment. </span>
              )}
              {hasConflicts && (
                <span>{chainResult.scheduling_conflicts.length} scheduling conflict{chainResult.scheduling_conflicts.length > 1 ? 's' : ''} require attention.</span>
              )}
            </div>
          </div>
        )}

        {/* Work Orders summary */}
        <div>
          <h4 className="flex items-center gap-1.5 text-xs uppercase tracking-wider text-muted font-medium mb-2">
            <Package size={14} />
            Work Orders ({chainResult.work_orders_created})
          </h4>
          {chainResult.work_orders.length > 0 ? (
            <div className="space-y-1.5 max-h-64 overflow-y-auto">
              {chainResult.work_orders.map((wo) => (
                <WoRow key={wo.id} wo={wo} />
              ))}
            </div>
          ) : (
            <p className="text-sm text-muted">No work orders created.</p>
          )}
        </div>

        {/* Material Planning */}
        <MaterialPlanningSection shortages={chainResult.shortages} prs_created={chainResult.prs_created} />

        {/* Scheduling Conflicts */}
        <SchedulingConflictsSection conflicts={chainResult.scheduling_conflicts} />

        {/* Summary line */}
        <div className="text-xs text-muted border-t border-default pt-3 font-mono tabular-nums">
          {chainResult.auto_scheduled} auto-scheduled · {chainResult.needs_manual} need manual scheduling
        </div>

        {/* Actions */}
        <div className="flex justify-end gap-2 pt-2 border-t border-default">
          <Button variant="secondary" size="sm" icon={<ExternalLink size={14} />} onClick={() => { onClose(); navigate('/production/work-orders'); }}>
            View Work Orders
          </Button>
          <Button variant="secondary" size="sm" icon={<ExternalLink size={14} />} onClick={() => { onClose(); navigate('/mrp/plans'); }}>
            View MRP Plan
          </Button>
          <Button variant="primary" size="sm" onClick={onClose}>
            Done
          </Button>
        </div>
      </div>
    </Modal>
  );
}

// ─── MRP Failure Error Panel ───────────────────────────────────────────────

interface MrpErrorInfo {
  message: string;
  errors?: Record<string, string[]>;
}

interface ChainErrorPanelProps {
  error: MrpErrorInfo | null;
  onDismiss: () => void;
}

/**
 * Inline error panel displayed when SO confirmation fails due to MRP issues
 * (missing BOM, no supplier mapping, credit limit exceeded, etc.).
 * Renders structured error details with actionable links where possible.
 */
export function ChainErrorPanel({ error, onDismiss }: ChainErrorPanelProps) {
  const navigate = useNavigate();

  if (!error) return null;

  const isMissingBom = error.message.toLowerCase().includes('bom')
    || error.message.toLowerCase().includes('bill of material');
  const isNoSupplier = error.message.toLowerCase().includes('supplier');
  const isCreditLimit = error.message.toLowerCase().includes('credit limit');

  return (
    <div className="border border-danger/30 bg-danger/5 rounded-md px-4 py-3 space-y-2">
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-start gap-2">
          <AlertTriangle size={16} className="text-danger mt-0.5 shrink-0" />
          <div>
            <p className="text-sm font-medium text-danger">Confirmation Failed</p>
            <p className="text-sm text-muted mt-1">{error.message}</p>
          </div>
        </div>
        <button
          type="button"
          onClick={onDismiss}
          className="text-muted hover:text-primary text-xs"
        >
          Dismiss
        </button>
      </div>

      {error.errors && Object.keys(error.errors).length > 0 && (
        <ul className="text-xs text-muted space-y-0.5 ml-6">
          {Object.entries(error.errors).flatMap(([, msgs]) =>
            msgs.map((m, i) => (
              <li key={i} className="flex items-start gap-1.5">
                <span className="text-danger mt-0.5 shrink-0">-</span>
                {m}
              </li>
            ))
          )}
        </ul>
      )}

      <div className="flex gap-2 ml-6">
        {isMissingBom && (
          <Button size="sm" variant="secondary" onClick={() => navigate('/mrp/bom')}>
            Manage BOMs
          </Button>
        )}
        {isNoSupplier && (
          <Button size="sm" variant="secondary" onClick={() => navigate('/purchasing/vendors')}>
            Manage Suppliers
          </Button>
        )}
        {isCreditLimit && (
          <Button size="sm" variant="secondary" onClick={() => navigate('/crm/customers')}>
            Review Customer
          </Button>
        )}
      </div>
    </div>
  );
}
