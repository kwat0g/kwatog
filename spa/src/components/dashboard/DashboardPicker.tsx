import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuArrowDown, LuArrowUp, LuLayoutGrid, LuPlus, LuSave, LuX } from '@/lib/icons';
import { dashboardLayoutApi, type DashboardLayoutItem, type DashboardWidgetMeta, type SavedLayoutWidget } from '@/api/dashboard-layout';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Chip } from '@/components/ui/Chip';

interface DashboardPickerProps {
  layout: DashboardLayoutItem[];
  layoutVersion: string;
}

interface DraftWidget extends SavedLayoutWidget {
  name: string;
  module: string;
  render_kind: DashboardWidgetMeta['render_kind'];
}

const KIND_LABELS: Record<DashboardWidgetMeta['render_kind'], string> = {
  scalar: 'Metric',
  breakdown: 'Breakdown',
  trend: 'Trend',
  table: 'Worklist',
  gauge: 'Gauge',
};

const MODULE_LABELS: Record<string, string> = {
  accounting: 'Finance',
  attendance: 'Attendance',
  assets: 'Assets',
  budgeting: 'Budgeting',
  crm: 'CRM',
  hr: 'People',
  inventory: 'Inventory',
  leave: 'Leave',
  loans: 'Loans',
  maintenance: 'Maintenance',
  mrp: 'Planning',
  payroll: 'Payroll',
  platform: 'Platform',
  production: 'Production',
  quality: 'Quality',
  return_management: 'Returns',
  purchasing: 'Purchasing',
  supply_chain: 'Supply chain',
};

function moduleLabel(module: string): string {
  return MODULE_LABELS[module] ?? module.replaceAll('_', ' ');
}

function pack(widgets: DraftWidget[]): SavedLayoutWidget[] {
  let x = 0;
  let y = 0;

  return widgets.map((widget) => {
    const w = Math.max(4, Math.min(12, widget.w ?? 4));
    const h = Math.max(4, Math.min(12, widget.h ?? 4));

    if (x > 0 && x + w > 12) {
      x = 0;
      y += 1;
    }

    const positioned = { key: widget.key, x, y, w, h };
    x += w;

    if (x >= 12) {
      x = 0;
      y += 1;
    }

    return positioned;
  });
}

function draftFromLayout(layout: DashboardLayoutItem[]): DraftWidget[] {
  return layout.map((item) => ({
    key: item.key,
    name: item.name,
    module: item.module,
    render_kind: item.render_kind,
    x: item.x,
    y: item.y,
    w: item.w,
    h: item.h,
  }));
}

function draftFromMeta(widget: DashboardWidgetMeta): DraftWidget {
  return {
    key: widget.key,
    name: widget.name,
    module: widget.module,
    render_kind: widget.render_kind,
    w: widget.default_w,
    h: widget.default_h,
  };
}

export function DashboardPicker({ layout, layoutVersion }: DashboardPickerProps) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [draft, setDraft] = useState<DraftWidget[]>(() => draftFromLayout(layout));

  const catalog = useQuery({
    queryKey: ['dashboard', 'widgets'],
    queryFn: () => dashboardLayoutApi.widgets(),
    enabled: open,
    staleTime: 5 * 60_000,
  });

  const save = useMutation({
    mutationFn: (widgets: SavedLayoutWidget[]) => dashboardLayoutApi.save(widgets, layoutVersion),
    onSuccess: () => {
      toast.success('Dashboard layout saved.');
      setOpen(false);
      queryClient.invalidateQueries({ queryKey: ['dashboard', 'layout'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard', 'widget-data'] });
    },
    onError: (error: { response?: { status?: number } }) => {
      toast.error(error.response?.status === 409
        ? 'This layout changed elsewhere. Reload the dashboard before saving again.'
        : 'Could not save the dashboard layout.');
    },
  });

  useEffect(() => {
    if (open) {
      setDraft(draftFromLayout(layout));
      setSearch('');
    }
  }, [open, layout]);

  const activeKeys = useMemo(() => new Set(draft.map((widget) => widget.key)), [draft]);
  const available = useMemo(() => {
    const term = search.trim().toLowerCase();
    return (catalog.data ?? []).filter((widget) => {
      if (activeKeys.has(widget.key)) return false;
      if (!term) return true;
      return `${widget.name} ${widget.module} ${widget.description ?? ''}`.toLowerCase().includes(term);
    });
  }, [activeKeys, catalog.data, search]);

  const move = (index: number, direction: -1 | 1) => {
    const target = index + direction;
    if (target < 0 || target >= draft.length) return;

    setDraft((current) => {
      const next = [...current];
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  };

  return (
    <>
      <Button
        variant="secondary"
        size="sm"
        icon={<LuLayoutGrid size={14} />}
        onClick={() => setOpen(true)}
        aria-label="Customize dashboard"
      >
        Customize
      </Button>

      <Modal isOpen={open} onClose={() => setOpen(false)} title="Customize dashboard" size="xl">
        <div className="space-y-5">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <div>
              <p className="text-sm text-primary">Keep the work you monitor most often within reach.</p>
              <p className="mt-1 text-xs text-muted">
                Widgets are filtered to the permissions on this account. Reordering controls the reading order on smaller screens.
              </p>
            </div>
            <div className="w-full sm:w-64">
              <Input
                label="Find a widget"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search by name or module"
                fieldSize="sm"
              />
            </div>
          </div>

          <section aria-labelledby="active-dashboard-widgets">
            <div className="mb-2 flex items-baseline justify-between gap-2">
              <h3 id="active-dashboard-widgets" className="text-sm font-medium text-primary">On your dashboard</h3>
              <span className="text-xs text-muted">{draft.length} selected</span>
            </div>

            {draft.length === 0 ? (
              <div className="rounded-md border border-dashed border-default p-4">
                <EmptyState size="compact" icon="grid" title="No widgets selected" description="Add a worklist or metric below to build this dashboard." />
              </div>
            ) : (
              <ol className="divide-y divide-subtle rounded-md border border-default">
                {draft.map((widget, index) => (
                  <li key={widget.key} className="flex items-center gap-3 px-3 py-2.5">
                    <span className="w-6 shrink-0 text-center font-mono text-xs tabular-nums text-subtle">{index + 1}</span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-primary">{widget.name}</p>
                      <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
                        <span className="text-xs text-muted">{moduleLabel(widget.module)}</span>
                        <Chip variant="neutral">{KIND_LABELS[widget.render_kind]}</Chip>
                      </div>
                    </div>
                    <div className="flex shrink-0 items-center gap-1">
                      <Button
                        variant="ghost"
                        size="xs"
                        iconOnly
                        icon={<LuArrowUp size={13} />}
                        onClick={() => move(index, -1)}
                        disabled={index === 0}
                        aria-label={`Move ${widget.name} up`}
                      />
                      <Button
                        variant="ghost"
                        size="xs"
                        iconOnly
                        icon={<LuArrowDown size={13} />}
                        onClick={() => move(index, 1)}
                        disabled={index === draft.length - 1}
                        aria-label={`Move ${widget.name} down`}
                      />
                      <Button
                        variant="ghost"
                        size="xs"
                        iconOnly
                        icon={<LuX size={13} />}
                        onClick={() => setDraft((current) => current.filter((item) => item.key !== widget.key))}
                        aria-label={`Remove ${widget.name}`}
                      />
                    </div>
                  </li>
                ))}
              </ol>
            )}
          </section>

          <section aria-labelledby="available-dashboard-widgets">
            <div className="mb-2 flex items-baseline justify-between gap-2">
              <h3 id="available-dashboard-widgets" className="text-sm font-medium text-primary">Available to add</h3>
              {catalog.data && <span className="text-xs text-muted">{available.length} matching</span>}
            </div>

            {catalog.isLoading ? (
              <p className="rounded-md border border-default p-4 text-sm text-muted">Loading widgets available to this account…</p>
            ) : catalog.isError ? (
              <p className="rounded-md border border-danger p-4 text-sm text-danger-fg">The widget catalog could not be loaded. Close this window and try again.</p>
            ) : available.length === 0 ? (
              <p className="rounded-md border border-default p-4 text-sm text-muted">No additional permitted widgets match this search.</p>
            ) : (
              <div className="grid gap-2 sm:grid-cols-2">
                {available.map((widget) => (
                  <button
                    key={widget.key}
                    type="button"
                    className="flex min-w-0 items-center gap-3 rounded-md border border-default bg-canvas px-3 py-2.5 text-left transition-colors hover:border-strong hover:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    onClick={() => setDraft((current) => [...current, draftFromMeta(widget)])}
                  >
                    <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded bg-subtle text-muted" aria-hidden="true">
                      <LuPlus size={14} />
                    </span>
                    <span className="min-w-0">
                      <span className="block truncate text-sm font-medium text-primary">{widget.name}</span>
                      <span className="mt-0.5 block truncate text-xs text-muted">{moduleLabel(widget.module)} · {KIND_LABELS[widget.render_kind]}</span>
                    </span>
                  </button>
                ))}
              </div>
            )}
          </section>
        </div>

        <ModalFooter>
          <Button variant="secondary" onClick={() => setOpen(false)} disabled={save.isPending}>Cancel</Button>
          <Button variant="primary" icon={<LuSave size={14} />} onClick={() => save.mutate(pack(draft))} loading={save.isPending}>
            Save layout
          </Button>
        </ModalFooter>
      </Modal>
    </>
  );
}
