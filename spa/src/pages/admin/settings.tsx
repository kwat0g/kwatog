import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import {
  LuBuilding2,
  LuCalendar,
  LuBanknote,
  LuCircleCheck,
  LuShield,
  LuPuzzle,
  LuSearch,
  LuServer,
  LuSlidersHorizontal,
  LuCheck,
  LuCpu,
  LuDatabase,
  LuLayers,
  LuClock,
  LuActivity,
  LuLock,
  LuLockOpen,
} from '@/lib/icons';
import {
  settingsApi,
  type SettingRow,
  type SettingValue,
  type SystemInfo,
} from '@/api/admin/settings';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { Switch } from '@/components/ui/Switch';
import { Chip } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { useAuthStore } from '@/stores/authStore';
import { formatDate, formatDateTime } from '@/lib/formatDate';
import { cn } from '@/lib/cn';

const MODULE_LABELS: Record<string, string> = {
  hr: 'Human Resources',
  attendance: 'Attendance',
  leave: 'Leave Management',
  payroll: 'Payroll',
  loans: 'Loans',
  accounting: 'Accounting',
  inventory: 'Inventory',
  purchasing: 'Purchasing',
  supply_chain: 'Supply Chain',
  production: 'Production',
  mrp: 'MRP / MRP II',
  crm: 'CRM',
  quality: 'Quality',
  maintenance: 'Maintenance',
  assets: 'Assets',
  search: 'Global Search',
  notifications: 'Notifications',
  recruitment: 'Recruitment',
  return_management: 'Return Management',
  b2b_portals: 'B2B Portals',
  forecasting: 'Forecasting',
  budgeting: 'Budgeting',
};

const MODULE_DEPENDENCIES: Record<string, string[]> = {
  budgeting: ['accounting'],
  forecasting: ['inventory', 'crm'],
  recruitment: ['hr'],
  payroll: ['hr', 'attendance'],
  loans: ['hr', 'payroll'],
  leave: ['hr'],
  attendance: ['hr'],
  quality: ['production', 'inventory'],
  production: ['mrp', 'inventory'],
  supply_chain: ['inventory', 'purchasing'],
  maintenance: ['production'],
  b2b_portals: ['crm', 'accounting'],
  return_management: ['crm', 'inventory'],
};

function getEnabledDependents(moduleSlug: string, allModuleRows: SettingRow[]): string[] {
  return Object.entries(MODULE_DEPENDENCIES)
    .filter(([, deps]) => deps.includes(moduleSlug))
    .filter(([mod]) => {
      const row = allModuleRows.find((r) => r.key === `modules.${mod}`);
      return row && Boolean(row.value);
    })
    .map(([mod]) => MODULE_LABELS[mod] ?? mod);
}

interface GroupMeta {
  label: string;
  description: string;
  icon: React.ReactNode;
  category: 'general' | 'operations' | 'security' | 'modules';
}

const GROUP_META: Record<string, GroupMeta> = {
  company: {
    label: 'Company',
    description: 'Organization identity used on documents, PDFs, and legal notices',
    icon: <LuBuilding2 size={16} />,
    category: 'general',
  },
  fiscal: {
    label: 'Fiscal Year',
    description: 'Fiscal year cycle and reporting period configuration',
    icon: <LuCalendar size={16} />,
    category: 'general',
  },
  payroll: {
    label: 'Payroll',
    description: 'Pay schedule and payslip delivery options',
    icon: <LuBanknote size={16} />,
    category: 'operations',
  },
  approval: {
    label: 'Approvals',
    description: 'Workflow threshold limits and auto-resolution policies',
    icon: <LuCircleCheck size={16} />,
    category: 'operations',
  },
  accounting: {
    label: 'Accounting',
    description: 'Default ledgers and automated collection parameters',
    icon: <LuBanknote size={16} />,
    category: 'operations',
  },
  attendance: {
    label: 'Attendance',
    description: 'Overtime calculation thresholds and biometric rules',
    icon: <LuCalendar size={16} />,
    category: 'operations',
  },
  hr: {
    label: 'HR & Staffing',
    description: 'Employee provisioning and onboarding configurations',
    icon: <LuBuilding2 size={16} />,
    category: 'operations',
  },
  purchasing: {
    label: 'Purchasing',
    description: 'Three-way matching tolerances and PO limits',
    icon: <LuBanknote size={16} />,
    category: 'operations',
  },
  inventory: {
    label: 'Inventory',
    description: 'Stock valuation policies and safety stock parameters',
    icon: <LuPuzzle size={16} />,
    category: 'operations',
  },
  dashboard: {
    label: 'Dashboard & Badges',
    description:
      'Sidebar badge caching and severity thresholds, including per-badge overrides (dashboard.badges.overrides.<badge>.{danger,warning})',
    icon: <LuSlidersHorizontal size={16} />,
    category: 'operations',
  },
  security: {
    label: 'Security & Auth',
    description: 'Login policies, session timeouts, and password rules',
    icon: <LuShield size={16} />,
    category: 'security',
  },
  modules: {
    label: 'Module Feature Flags',
    description: 'Enable or disable system modules across the application',
    icon: <LuSlidersHorizontal size={16} />,
    category: 'modules',
  },
};

const GROUP_ORDER = [
  'company',
  'fiscal',
  'payroll',
  'approval',
  'accounting',
  'attendance',
  'hr',
  'purchasing',
  'inventory',
  'dashboard',
  'security',
  'modules',
];

type CategoryTab = 'all' | 'general' | 'operations' | 'security' | 'modules' | 'system';

export default function SettingsPage() {
  const queryClient = useQueryClient();
  const refreshAuth = useAuthStore((s) => s.refresh);
  const [search, setSearch] = useState('');
  const [activeTab, setActiveTab] = useState<CategoryTab>('all');
  const [isEditMode, setIsEditMode] = useState(false);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'settings'],
    queryFn: settingsApi.index,
  });

  const { data: sysInfo } = useQuery({
    queryKey: ['admin', 'system-info'],
    queryFn: settingsApi.systemInfo,
  });

  const update = useMutation({
    mutationFn: ({ key, value }: { key: string; value: SettingValue }) =>
      settingsApi.update(key, value),
    onSuccess: async (_data, variables) => {
      toast.success(`Saved setting ${variables.key}`);
      queryClient.invalidateQueries({ queryKey: ['admin', 'settings'] });
      if (variables.key.startsWith('modules.')) {
        await refreshAuth();
      }
    },
    onError: () => toast.error('Could not save setting.'),
  });

  const groups = useMemo(() => {
    if (!data) return [] as Array<[string, SettingRow[]]>;
    const q = search.toLowerCase().trim();

    return GROUP_ORDER.filter((g) => Array.isArray(data[g]) && data[g].length > 0)
      .map((g) => {
        const meta = GROUP_META[g];
        // Apply tab filter (unless on 'all' or 'system')
        if (activeTab !== 'all' && activeTab !== 'system' && meta?.category !== activeTab) {
          return [g, []] as [string, SettingRow[]];
        }

        const rows = q
          ? data[g].filter(
              (r: SettingRow) =>
                r.key.toLowerCase().includes(q) ||
                (r.label ?? '').toLowerCase().includes(q) ||
                (r.description ?? '').toLowerCase().includes(q),
            )
          : data[g];
        return [g, rows] as [string, SettingRow[]];
      })
      .filter(([, rows]) => rows.length > 0);
  }, [data, search, activeTab]);

  const totalSettingsCount = useMemo(() => {
    if (!data) return 0;
    return Object.values(data).reduce(
      (acc, rows) => acc + (Array.isArray(rows) ? rows.length : 0),
      0,
    );
  }, [data]);

  return (
    <div>
      <PageHeader
        title="LuSettings & Configuration"
        subtitle="System parameters, organization profile, security policies, and feature flags."
        actions={
          <Button
            variant={isEditMode ? 'primary' : 'secondary'}
            icon={isEditMode ? <LuLockOpen size={14} /> : <LuLock size={14} />}
            onClick={() => setIsEditMode(!isEditMode)}
          >
            {isEditMode ? 'Lock LuSettings' : 'Unlock to Edit'}
          </Button>
        }
      />

      <div className="px-5 py-4 max-w-5xl space-y-6">
        {/* Category Navigation Tabs */}
        <div className="flex items-center gap-1.5 overflow-x-auto border-b border-default pb-3 scrollbar-none">
          {(
            [
              { id: 'all', label: 'All LuSettings', icon: <LuSlidersHorizontal size={14} /> },
              { id: 'general', label: 'Company & Fiscal', icon: <LuBuilding2 size={14} /> },
              { id: 'operations', label: 'Operations & HR', icon: <LuBanknote size={14} /> },
              { id: 'security', label: 'Security & Auth', icon: <LuShield size={14} /> },
              { id: 'modules', label: 'Feature Flags', icon: <LuPuzzle size={14} /> },
              { id: 'system', label: 'System Info', icon: <LuServer size={14} /> },
            ] as const
          ).map((tab) => (
            <button
              key={tab.id}
              type="button"
              onClick={() => setActiveTab(tab.id)}
              className={cn(
                'flex items-center gap-2 rounded-full px-3.5 py-1.5 font-mono text-[11px] uppercase tracking-wider transition-colors cursor-pointer shrink-0',
                activeTab === tab.id
                  ? 'bg-accent text-accent-fg font-medium '
                  : 'bg-subtle text-muted hover:text-primary hover:bg-elevated',
              )}
            >
              {tab.icon}
              <span>{tab.label}</span>
            </button>
          ))}
        </div>

        {isLoading && <SkeletonForm />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load settings"
            description="Unable to connect to settings service."
            action={
              <Button variant="secondary" onClick={() => window.location.reload()}>
                Retry
              </Button>
            }
          />
        )}

        {data && (
          <>
            {/* Search & Counter Toolbar */}
            <div className="flex items-center justify-between gap-4 flex-wrap">
              <Input
                placeholder="Search settings by key, label, or description…"
                aria-label="Search settings"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                prefix={<LuSearch size={14} className="text-muted" />}
                containerClassName="max-w-md flex-1"
              />
              <div className="flex items-center gap-2 text-xs font-mono text-muted">
                <span>{totalSettingsCount} total parameters</span>
                {search && (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setSearch('')}
                    className="text-xs text-secondary hover:text-primary"
                  >
                    Clear search
                  </Button>
                )}
              </div>
            </div>

            {groups.length === 0 && activeTab !== 'system' && (
              <EmptyState
                icon="search"
                title="No settings found"
                description={
                  search
                    ? `No settings match "${search}".`
                    : `No settings available under ${activeTab} category.`
                }
              />
            )}

            {/* Setting Groups */}
            {activeTab !== 'system' &&
              groups.map(([group, rows]) => {
                const meta = GROUP_META[group];
                return (
                  <SettingsGroup
                    key={group}
                    group={group}
                    meta={meta}
                    rows={rows}
                    isEditMode={isEditMode}
                    saving={update.isPending ? update.variables?.key : undefined}
                    onSave={(key, value) => update.mutate({ key, value })}
                  />
                );
              })}
          </>
        )}

        {/* System Info Panel */}
        {(activeTab === 'all' || activeTab === 'system') && sysInfo && (
          <SystemInfoPanel info={sysInfo} />
        )}
      </div>
    </div>
  );
}

interface SettingsGroupProps {
  group: string;
  meta?: GroupMeta;
  rows: SettingRow[];
  isEditMode: boolean;
  saving?: string;
  onSave: (key: string, value: SettingValue) => void;
}

function SettingsGroup({ group, meta, rows, isEditMode, saving, onSave }: SettingsGroupProps) {
  const isModule = group === 'modules';

  return (
    <Panel
      title={
        <div className="flex items-center justify-between w-full">
          <span className="flex items-center gap-2 text-base ">
            {meta && <span className="text-accent">{meta.icon}</span>}
            <span>{meta?.label ?? group}</span>
          </span>
          <Chip variant="neutral" className="font-mono text-2xs">
            {rows.length} {rows.length === 1 ? 'setting' : 'settings'}
          </Chip>
        </div>
      }
    >
      {meta?.description && <p className="text-xs text-muted -mt-1 mb-4">{meta.description}</p>}
      <div className="flex flex-col divide-y divide-subtle">
        {rows.map((row) => (
          <SettingRowEditor
            key={row.key}
            row={row}
            isModule={isModule}
            allModuleRows={isModule ? rows : undefined}
            isEditMode={isEditMode}
            isSaving={saving === row.key}
            onSave={(value) => onSave(row.key, value)}
          />
        ))}
      </div>
    </Panel>
  );
}

interface RowEditorProps {
  row: SettingRow;
  isModule: boolean;
  allModuleRows?: SettingRow[];
  isEditMode: boolean;
  isSaving: boolean;
  onSave: (value: SettingValue) => void;
}

function SettingRowEditor({
  row,
  isModule,
  allModuleRows,
  isEditMode,
  isSaving,
  onSave,
}: RowEditorProps) {
  const [confirmToggle, setConfirmToggle] = useState<{
    key: string;
    currentValue: boolean;
  } | null>(null);

  const label = row.label ?? row.key;
  const description = row.description;

  if (isModule) {
    const slug = row.key.replace('modules.', '');
    const displayLabel = row.label ?? MODULE_LABELS[slug] ?? slug;
    const isEnabled = Boolean(row.value);
    const dependents = isEnabled && allModuleRows ? getEnabledDependents(slug, allModuleRows) : [];

    return (
      <>
        <div className="flex items-center justify-between py-3.5 first:pt-0 last:pb-0 hover:bg-subtle/30 px-2 rounded-md transition-colors">
          <div className="flex-1 min-w-0 pr-4">
            <div className="flex items-center gap-2">
              <span className="text-sm font-medium text-primary">{displayLabel}</span>
              <Chip
                variant={isEnabled ? 'success' : 'neutral'}
                className="text-[9px] font-mono px-1.5"
              >
                {isEnabled ? 'ACTIVE' : 'DISABLED'}
              </Chip>
            </div>
            {description && (
              <div className="text-xs text-muted mt-1 leading-relaxed">{description}</div>
            )}
            <ChangeAttribution row={row} />
          </div>
          <Switch
            checked={isEnabled}
            disabled={!isEditMode || isSaving}
            onChange={() => setConfirmToggle({ key: row.key, currentValue: isEnabled })}
          />
        </div>
        <ConfirmDialog
          isOpen={confirmToggle?.key === row.key}
          title={
            confirmToggle?.currentValue ? `Disable ${displayLabel}?` : `Enable ${displayLabel}?`
          }
          description={
            confirmToggle?.currentValue
              ? dependents.length > 0
                ? `Disabling ${displayLabel} may affect these enabled modules that depend on it: ${dependents.join(', ')}. All ${displayLabel} pages will become inaccessible for all users. Existing data is preserved.`
                : `All ${displayLabel} pages will become inaccessible for all users. Existing data is preserved and will be visible again when re-enabled.`
              : `${displayLabel} pages will become accessible to users with the appropriate permissions.`
          }
          confirmLabel={confirmToggle?.currentValue ? 'Disable Module' : 'Enable Module'}
          variant={confirmToggle?.currentValue ? 'danger' : 'primary'}
          onConfirm={() => {
            onSave(!confirmToggle!.currentValue);
            setConfirmToggle(null);
          }}
          onClose={() => setConfirmToggle(null)}
          pending={isSaving}
        />
      </>
    );
  }

  if (typeof row.value === 'boolean') {
    return (
      <div className="flex items-center justify-between py-3.5 first:pt-0 last:pb-0 hover:bg-subtle/30 px-2 rounded-md transition-colors">
        <div className="flex-1 min-w-0 pr-4">
          <div className="text-sm font-medium text-primary">{label}</div>
          {description && (
            <div className="text-xs text-muted mt-1 leading-relaxed">{description}</div>
          )}
          <ChangeAttribution row={row} />
        </div>
        <Switch
          checked={row.value as boolean}
          disabled={!isEditMode || isSaving}
          onChange={(e) => onSave(e.target.checked)}
        />
      </div>
    );
  }

  if (typeof row.value === 'number') {
    return (
      <ScalarRow
        row={row}
        label={label}
        description={description}
        type="number"
        isEditMode={isEditMode}
        saving={isSaving}
        onSave={(s) => onSave(s === '' ? 0 : Number(s))}
      />
    );
  }

  return (
    <ScalarRow
      row={row}
      label={label}
      description={description}
      type="text"
      isEditMode={isEditMode}
      saving={isSaving}
      onSave={(s) => onSave(s)}
    />
  );
}

function ChangeAttribution({ row }: { row: SettingRow }) {
  if (!row.updated_by_name || !row.updated_at) return null;
  return (
    <div className="text-2xs text-muted mt-1">
      Changed by <span className="font-medium text-secondary">{row.updated_by_name}</span> &middot;{' '}
      {formatDate(row.updated_at)}
    </div>
  );
}

function ScalarRow({
  row,
  label,
  description,
  type,
  isEditMode,
  saving,
  onSave,
}: {
  row: SettingRow;
  label: string;
  description: string | null;
  type: 'text' | 'number';
  isEditMode: boolean;
  saving: boolean;
  onSave: (s: string) => void;
}) {
  const [val, setVal] = useState(String(row.value ?? ''));
  const [saved, setSaved] = useState(false);
  // The "saved" tick clears itself after 2s. Held in a ref so unmounting the
  // row mid-countdown (a filter change, a tab switch) cancels the timer
  // instead of leaving it to fire into a component that no longer exists.
  const savedTimer = useRef<number | undefined>(undefined);

  useEffect(() => () => window.clearTimeout(savedTimer.current), []);

  const handleBlur = () => {
    if (String(row.value ?? '') !== val) {
      onSave(val);
      setSaved(true);
      window.clearTimeout(savedTimer.current);
      savedTimer.current = window.setTimeout(() => setSaved(false), 2000);
    }
  };

  return (
    <div className="grid grid-cols-1 sm:grid-cols-[1fr_auto] items-start gap-4 py-3.5 first:pt-0 last:pb-0 hover:bg-subtle/30 px-2 rounded-md transition-colors">
      <div className="min-w-0">
        <div className="text-sm font-medium text-primary">{label}</div>
        {description && (
          <div className="text-xs text-muted mt-1 leading-relaxed">{description}</div>
        )}
        <ChangeAttribution row={row} />
      </div>
      <div className="w-full sm:w-56 flex items-center gap-2">
        <Input
          type={type}
          value={val}
          onChange={(e) => setVal(e.target.value)}
          onBlur={handleBlur}
          disabled={!isEditMode || saving}
          containerClassName="flex-1"
        />
        {saving && <span className="text-2xs font-mono text-accent animate-pulse">Saving…</span>}
        {saved && !saving && (
          <span className="text-2xs font-mono text-success-fg flex items-center gap-0.5">
            <LuCheck size={12} /> Saved
          </span>
        )}
      </div>
    </div>
  );
}

function SystemInfoPanel({ info }: { info: SystemInfo }) {
  return (
    <Panel
      title={
        <div className="flex items-center justify-between w-full">
          <span className="flex items-center gap-2 text-base ">
            <span className="text-accent">
              <LuServer size={16} />
            </span>
            <span>System Telemetry & Environment</span>
          </span>
          <Chip
            variant={info.app_env === 'production' ? 'success' : 'warning'}
            className="font-mono text-2xs uppercase"
          >
            {info.app_env}
          </Chip>
        </div>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
        <TelemetryCard icon={<LuCpu size={16} />} label="PHP Version" value={info.php_version} />
        <TelemetryCard
          icon={<LuActivity size={16} />}
          label="Laravel Version"
          value={info.laravel_version}
        />
        <TelemetryCard
          icon={<LuDatabase size={16} />}
          label="Database"
          value={`${info.database.driver} (${info.database.version})`}
        />
        <TelemetryCard icon={<LuLayers size={16} />} label="Cache Driver" value={info.cache_driver} />
        <TelemetryCard icon={<LuLayers size={16} />} label="Queue Driver" value={info.queue_driver} />
        <TelemetryCard
          icon={<LuLayers size={16} />}
          label="Session Driver"
          value={info.session_driver}
        />
        <TelemetryCard
          icon={<LuShield size={16} />}
          label="Debug Mode"
          value={info.app_debug ? 'Enabled (ON)' : 'Disabled (OFF)'}
          tone={info.app_debug ? 'warning' : 'neutral'}
        />
        <TelemetryCard icon={<LuClock size={16} />} label="Timezone" value={info.timezone} />
        <TelemetryCard
          icon={<LuClock size={16} />}
          label="Server Time"
          value={formatDateTime(info.server_time)}
        />
      </div>
    </Panel>
  );
}

function TelemetryCard({
  icon,
  label,
  value,
  tone = 'neutral',
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  tone?: 'neutral' | 'warning';
}) {
  return (
    <div className="flex items-start gap-3 rounded-md border border-default bg-subtle/40 p-3">
      <div className="p-2 rounded bg-surface border border-subtle text-accent">{icon}</div>
      <div className="min-w-0">
        <div className="text-2xs font-mono uppercase tracking-wider text-muted">{label}</div>
        <div
          className={cn(
            'text-xs font-mono font-medium truncate mt-0.5',
            tone === 'warning' ? 'text-warning-fg' : 'text-primary',
          )}
        >
          {value}
        </div>
      </div>
    </div>
  );
}
