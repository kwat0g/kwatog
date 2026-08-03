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
  oee?: number;
  active_wo?: string | null;
  active_mold?: string | null;
  current_output?: number;
  target_output?: number;
  cycle_time_sec?: number;
}

interface ShopFloorMapProps {
  machines: ShopFloorMachine[];
  onSelectMachine?: (machine: ShopFloorMachine) => void;
}

export function ShopFloorMap({ machines, onSelectMachine }: ShopFloorMapProps) {
  const [selectedMachine, setSelectedMachine] = useState<ShopFloorMachine | null>(null);

  // Group machines into two bays (Bay A: IM-001 to IM-006, Bay B: IM-007 to IM-012)
  const bayA = machines.filter((_, idx) => idx < 6);
  const bayB = machines.filter((_, idx) => idx >= 6);

  const getStatusBg = (status: string) => {
    switch (status) {
      case 'running':
        return 'bg-emerald-500/10 border-emerald-500/30 text-emerald-600 dark:text-emerald-400';
      case 'breakdown':
        return 'bg-rose-500/10 border-rose-500/30 text-rose-600 dark:text-rose-400';
      case 'maintenance':
        return 'bg-amber-500/10 border-amber-500/30 text-amber-600 dark:text-amber-400';
      default:
        return 'bg-zinc-500/10 border-zinc-500/30 text-zinc-600 dark:text-zinc-400';
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'running':
        return <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500 animate-pulse" />;
      case 'breakdown':
        return <AlertTriangle className="w-3.5 h-3.5 text-rose-500" />;
      case 'maintenance':
        return <Wrench className="w-3.5 h-3.5 text-amber-500" />;
      default:
        return <Activity className="w-3.5 h-3.5 text-zinc-400" />;
    }
  };

  const renderMachineNode = (m: ShopFloorMachine) => {
    const isSelected = selectedMachine?.machine_id === m.machine_id;
    const oeePct = m.oee != null ? (m.oee <= 1 ? Math.round(m.oee * 100) : Math.round(m.oee)) : 85;

    return (
      <button
        key={m.machine_id}
        type="button"
        onClick={() => {
          setSelectedMachine(m);
          onSelectMachine?.(m);
        }}
        className={`group relative flex flex-col justify-between p-3 rounded-lg border text-left transition-all duration-200 cursor-pointer ${
          isSelected
            ? 'border-indigo-500 ring-2 ring-indigo-500/20 bg-indigo-500/5 shadow-md'
            : `${getStatusBg(m.status)} hover:border-indigo-400/50 hover:shadow-sm`
        }`}
      >
        <div className="flex items-center justify-between gap-1 w-full mb-1.5">
          <div className="flex items-center gap-1.5">
            {getStatusIcon(m.status)}
            <span className="font-mono font-semibold text-xs text-primary">{m.machine_code}</span>
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
        <div className="w-full bg-surface/80 rounded border border-border/60 p-1.5 space-y-1 mb-2">
          <div className="flex justify-between items-center text-[10px]">
            <span className="text-muted">Mold</span>
            <span className="font-mono text-primary font-medium">{m.active_mold ?? 'M-101'}</span>
          </div>
          <div className="flex justify-between items-center text-[10px]">
            <span className="text-muted">OEE</span>
            <span
              className={`font-mono font-semibold ${
                oeePct >= 80 ? 'text-emerald-600 dark:text-emerald-400' : oeePct >= 65 ? 'text-amber-600' : 'text-rose-600'
              }`}
            >
              {oeePct}%
            </span>
          </div>
          <div className="h-1 bg-elevated rounded-full overflow-hidden">
            <div
              className={`h-full rounded-full transition-all ${
                oeePct >= 80 ? 'bg-emerald-500' : oeePct >= 65 ? 'bg-amber-500' : 'bg-rose-500'
              }`}
              style={{ width: `${Math.min(100, Math.max(5, oeePct))}%` }}
            />
          </div>
        </div>

        <div className="flex items-center justify-between text-[10px] text-subtle pt-1 border-t border-border/40">
          <span>WO: {m.active_wo ?? 'WO-2026-001'}</span>
          <span className="group-hover:text-indigo-500 flex items-center gap-0.5">
            Details <ArrowRight className="w-2.5 h-2.5" />
          </span>
        </div>
      </button>
    );
  };

  return (
    <div className="space-y-4">
      {/* Visual Shop Floor Plant Layout Grid */}
      <div className="bg-canvas border border-border rounded-xl p-4 shadow-xs space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
          <div>
            <h3 className="text-xs font-semibold text-primary uppercase tracking-wider flex items-center gap-1.5">
              <span className="w-2 h-2 rounded-full bg-emerald-500 animate-ping" />
              Dasmarinas Plant — Injection Molding Shop Floor (12 Bays)
            </h3>
            <p className="text-2xs text-muted">Click any machine cell to inspect real-time OEE, mold, and active work orders.</p>
          </div>
          <div className="flex items-center gap-3 text-xs">
            <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-full bg-emerald-500" /> Running</span>
            <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-full bg-amber-500" /> Maintenance</span>
            <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-full bg-rose-500" /> Breakdown</span>
            <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-full bg-zinc-400" /> Idle</span>
          </div>
        </div>

        {/* Plant Floor Bays Layout */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 bg-surface/50 p-3 rounded-lg border border-border/40">
          {/* Bay A: Machines 1 to 6 */}
          <div>
            <div className="text-[11px] font-semibold text-muted uppercase tracking-wider mb-2 flex items-center justify-between">
              <span>Production Bay A (High Tonnage: 350T - 500T)</span>
              <span className="font-mono text-2xs">IM-001 ~ IM-006</span>
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
              {bayA.map(renderMachineNode)}
            </div>
          </div>

          {/* Bay B: Machines 7 to 12 */}
          <div>
            <div className="text-[11px] font-semibold text-muted uppercase tracking-wider mb-2 flex items-center justify-between">
              <span>Production Bay B (Precision Tonnage: 120T - 250T)</span>
              <span className="font-mono text-2xs">IM-007 ~ IM-012</span>
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
              {bayB.map(renderMachineNode)}
            </div>
          </div>
        </div>
      </div>

      {/* Selected Machine Detail Popover Drawer */}
      {selectedMachine && (
        <div className="bg-surface border border-indigo-500/40 rounded-xl p-4 shadow-md space-y-3 relative animate-in fade-in slide-in-from-bottom-2 duration-200">
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
                <h4 className="text-sm font-bold font-mono text-primary">{selectedMachine.machine_code}</h4>
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

          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs bg-canvas p-3 rounded-lg border border-border/60">
            <div>
              <span className="text-muted block text-2xs uppercase">Active Mold</span>
              <span className="font-mono font-semibold text-primary">{selectedMachine.active_mold ?? 'M-104-OPT'}</span>
            </div>
            <div>
              <span className="text-muted block text-2xs uppercase">Active Work Order</span>
              <span className="font-mono font-semibold text-accent">{selectedMachine.active_wo ?? 'WO-202604-0001'}</span>
            </div>
            <div>
              <span className="text-muted block text-2xs uppercase">Cycle Time</span>
              <span className="font-mono font-semibold text-primary">{selectedMachine.cycle_time_sec ?? 24.5}s</span>
            </div>
            <div>
              <span className="text-muted block text-2xs uppercase">Target Output</span>
              <span className="font-mono font-semibold text-primary">
                {selectedMachine.current_output ?? 1250} / {selectedMachine.target_output ?? 1500} pcs
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
