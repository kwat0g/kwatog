import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Activity, AlertTriangle, ArrowRight, CheckCircle2, Wrench, X } from 'lucide-react';
import { Chip } from '@/components/ui/Chip';
import { Button } from '@/components/ui/Button';

export interface ShopFloorMachine {
  machine_id: string;
  machine_code: string;
  name: string;
  tonnage?: number | null;
  status: 'running' | 'idle' | 'breakdown' | 'maintenance' | string;
  status_label?: string;
  oee?: number | null;
  active_wo?: string | null;
  active_mold?: string | null;
  current_output?: number | null;
  target_output?: number | null;
  cycle_time_sec?: number | null;
}

interface ShopFloorMapProps {
  machines: ShopFloorMachine[];
  onSelectMachine?: (machine: ShopFloorMachine) => void;
}

export function ShopFloorMap({ machines, onSelectMachine }: ShopFloorMapProps) {
  const [selectedMachine, setSelectedMachine] = useState<ShopFloorMachine | null>(null);

  const getStatusBg = (status: string) => {
    switch (status) {
      case 'running':
        return 'bg-success-bg border-success/30 text-success-fg';
      case 'breakdown':
        return 'bg-danger-bg border-danger/30 text-danger-fg';
      case 'maintenance':
        return 'bg-warning-bg border-warning/30 text-warning-fg';
      default:
        return 'bg-subtle border-default text-muted';
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'running':
        return <CheckCircle2 className="w-3.5 h-3.5 text-success-fg" />;
      case 'breakdown':
        return <AlertTriangle className="w-3.5 h-3.5 text-danger-fg" />;
      case 'maintenance':
        return <Wrench className="w-3.5 h-3.5 text-warning-fg" />;
      default:
        return <Activity className="w-3.5 h-3.5 text-muted" />;
    }
  };

  const renderMachineNode = (m: ShopFloorMachine) => {
    const isSelected = selectedMachine?.machine_id === m.machine_id;
    const oeePct =
      m.oee != null ? (m.oee <= 1 ? Math.round(m.oee * 100) : Math.round(m.oee)) : null;

    return (
      <button
        key={m.machine_id}
        type="button"
        onClick={() => {
          setSelectedMachine(m);
          onSelectMachine?.(m);
        }}
        className={`group relative flex flex-col justify-between p-3 rounded-md border text-left transition-colors duration-200 cursor-pointer ${
          isSelected
            ? 'border-accent ring-2 ring-accent/20 bg-elevated '
            : `${getStatusBg(m.status)} hover:border-accent/50 hover:bg-elevated`
        }`}
      >
        <div className="flex items-center justify-between gap-1 w-full mb-1.5">
          <div className="flex items-center gap-1.5">
            {getStatusIcon(m.status)}
            <span className="font-mono font-medium text-xs text-primary">{m.machine_code}</span>
          </div>
          <Chip
            variant={
              m.status === 'running'
                ? 'success'
                : m.status === 'breakdown'
                  ? 'danger'
                  : m.status === 'maintenance'
                    ? 'warning'
                    : 'neutral'
            }
            className="text-[10px] px-1.5 py-0"
          >
            {m.status_label ?? m.status}
          </Chip>
        </div>

        <div className="text-[11px] text-muted truncate mb-2">{m.name}</div>

        {/* Dynamic visual representation of injection hydraulic press */}
        <div className="w-full bg-surface/80 rounded border border-default/60 p-1.5 space-y-1 mb-2">
          <div className="flex justify-between items-center text-[10px]">
            <span className="text-muted">Mold</span>
            <span className="font-mono text-primary font-medium">{m.active_mold ?? '—'}</span>
          </div>
          <div className="flex justify-between items-center text-[10px]">
            <span className="text-muted">OEE</span>
            <span
              className={`font-mono font-medium ${
                oeePct == null
                  ? 'text-muted'
                  : oeePct >= 80
                    ? 'text-success-fg'
                    : oeePct >= 65
                      ? 'text-warning-fg'
                      : 'text-danger-fg'
              }`}
            >
              {oeePct == null ? '—' : `${oeePct}%`}
            </span>
          </div>
          <div className="h-1 bg-elevated rounded-full overflow-hidden">
            <div
              className={`h-full rounded-full transition-[width] ${
                oeePct == null
                  ? 'bg-elevated'
                  : oeePct >= 80
                    ? 'bg-success-bg'
                    : oeePct >= 65
                      ? 'bg-warning-bg'
                      : 'bg-danger-bg'
              }`}
              style={{ width: `${oeePct == null ? 0 : Math.min(100, Math.max(0, oeePct))}%` }}
            />
          </div>
        </div>

        <div className="flex items-center justify-between text-[10px] text-subtle pt-1 border-t border-default/40">
          <span>WO: {m.active_wo ?? '—'}</span>
          <span className="group-hover:text-accent flex items-center gap-0.5">
            Details <ArrowRight className="w-2.5 h-2.5" />
          </span>
        </div>
      </button>
    );
  };

  return (
    <div className="space-y-4">
      {/* Visual Shop Floor Plant Layout Grid */}
      <div className="bg-canvas border border-default rounded-md p-4 space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-default pb-3">
          <div>
            <h3 className="text-xs font-medium text-primary uppercase tracking-wider flex items-center gap-1.5">
              <span className="w-2 h-2 rounded-full bg-success-bg" />
              Shop floor
            </h3>
            <p className="text-2xs text-muted">
              Click any machine cell to inspect the latest OEE, mold, and work-order data.
            </p>
          </div>
          <div className="flex items-center gap-3 text-xs">
            <span className="flex items-center gap-1">
              <span className="w-2.5 h-2.5 rounded-full bg-success-bg" /> Running
            </span>
            <span className="flex items-center gap-1">
              <span className="w-2.5 h-2.5 rounded-full bg-warning-bg" /> Maintenance
            </span>
            <span className="flex items-center gap-1">
              <span className="w-2.5 h-2.5 rounded-full bg-danger-bg" /> Breakdown
            </span>
            <span className="flex items-center gap-1">
              <span className="w-2.5 h-2.5 rounded-full bg-muted" /> Idle
            </span>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-2.5 bg-surface/50 p-3 rounded-md border border-default/40 sm:grid-cols-3 lg:grid-cols-4">
          {machines.map(renderMachineNode)}
        </div>
      </div>

      {/* Selected Machine Detail Popover Drawer */}
      {selectedMachine && (
        <div className="bg-surface border border-accent/40 rounded-md p-4 space-y-3 relative transition-colors duration-fast">
          <button
            type="button"
            onClick={() => setSelectedMachine(null)}
            className="absolute top-3 right-3 text-muted hover:text-primary p-1 rounded-md hover:bg-elevated"
          >
            <X className="w-4 h-4" />
          </button>

          <div className="flex items-center gap-3">
            {getStatusIcon(selectedMachine.status)}
            <div>
              <div className="flex items-center gap-2">
                <h4 className="text-sm font-medium font-mono text-primary">
                  {selectedMachine.machine_code}
                </h4>
                <Chip
                  variant={
                    selectedMachine.status === 'running'
                      ? 'success'
                      : selectedMachine.status === 'breakdown'
                        ? 'danger'
                        : selectedMachine.status === 'maintenance'
                          ? 'warning'
                          : 'neutral'
                  }
                >
                  {selectedMachine.status_label ?? selectedMachine.status}
                </Chip>
              </div>
              <p className="text-xs text-muted">{selectedMachine.name}</p>
            </div>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs bg-canvas p-3 rounded-md border border-default/60">
            <div>
              <span className="text-muted block text-2xs uppercase">Active Mold</span>
              <span className="font-mono font-medium text-primary">
                {selectedMachine.active_mold ?? '—'}
              </span>
            </div>
            <div>
              <span className="text-muted block text-2xs uppercase">Active Work Order</span>
              <span className="font-mono font-medium text-accent">
                {selectedMachine.active_wo ?? '—'}
              </span>
            </div>
            <div>
              <span className="text-muted block text-2xs uppercase">Cycle Time</span>
              <span className="font-mono font-medium text-primary">
                {selectedMachine.cycle_time_sec == null
                  ? '—'
                  : `${selectedMachine.cycle_time_sec}s`}
              </span>
            </div>
            <div>
              <span className="text-muted block text-2xs uppercase">Target Output</span>
              <span className="font-mono font-medium text-primary">
                {selectedMachine.current_output == null || selectedMachine.target_output == null
                  ? '—'
                  : `${selectedMachine.current_output} / ${selectedMachine.target_output} pcs`}
              </span>
            </div>
          </div>

          <div className="flex justify-end gap-2 pt-1">
            <Link to={`/mrp/machines/${selectedMachine.machine_id}`}>
              <Button variant="secondary" size="sm">
                Full Machine Telemetry
              </Button>
            </Link>
            {selectedMachine.active_wo && (
              <Link to={`/production/work-orders/${selectedMachine.active_wo}`}>
                <Button variant="primary" size="sm">
                  View Work Order
                </Button>
              </Link>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
