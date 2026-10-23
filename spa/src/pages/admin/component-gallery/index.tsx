/**
 * Component gallery (W1.2).
 *
 * WHY THIS PAGE EXISTS
 * The SPA has ~60 shared components in `components/ui/` and, until now, the only
 * way to see one was to find a page that happened to use it. That made two
 * things impossible: reviewing a component's *states* (does this control have a
 * focused look? a disabled density? an error message?) without hunting through
 * modules, and gate their appearance — a visual regression on a shared primitive
 * only surfaced by opening whichever page it lived on.
 *
 * So this is a catalogue, not a product surface. It fetches nothing, reads no
 * clock and uses no seeded data: every value below is a literal, which is what
 * makes it safe to photograph. `spa/e2e/gallery.spec.ts` screenshots each
 * section (those carrying `data-gallery-section`) at 1280 and 390 and compares
 * against committed baselines.
 *
 * The sections an operator can *only* judge by interacting — overlays, tooltips
 * — are marked `data-gallery-interactive` and deliberately left out of the
 * baseline: a portal renders outside its section's box, so a section screenshot
 * would either miss the dialog or photograph an empty frame.
 *
 * ADDING A COMPONENT: put it in the section whose job it does, give every state
 * its own tile, and remember the page must stay deterministic (no `Date.now()`,
 * no query, no random id) or the baseline will flake rather than catch.
 */
import { useState } from 'react';
import {
  LuArrowDown,
  LuArrowUp,
  LuCheck,
  LuDownload,
  LuPlus,
  LuRefreshCw,
  LuTrash2,
  LuUpload,
} from '@/lib/icons';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus, type ChipVariant } from '@/components/ui/Chip';
import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Checkbox } from '@/components/ui/Checkbox';
import { Radio } from '@/components/ui/Radio';
import { Switch } from '@/components/ui/Switch';
import { CurrencyInput } from '@/components/ui/CurrencyInput';
import { MaskedInput } from '@/components/ui/MaskedInput';
import { SearchableSelect } from '@/components/ui/SearchableSelect';
import { FileInput } from '@/components/ui/FileInput';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { StatCard } from '@/components/ui/StatCard';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Tabs } from '@/components/ui/Tabs';
import { TabNavigation } from '@/components/ui/TabNavigation';
import { SkeletonTable, SkeletonBlock } from '@/components/ui/Skeleton';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { ListEmptyState } from '@/components/ui/ListEmptyState';
import { QueryErrorState } from '@/components/ui/QueryErrorState';
import { FormErrorSummary } from '@/components/ui/FormErrorSummary';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { BottomSheet } from '@/components/ui/BottomSheet';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Tooltip } from '@/components/ui/Tooltip';
import { CopyButton } from '@/components/ui/CopyButton';
import { ChainHeader, type ChainStep } from '@/components/ui/ChainHeader';
import { StageBreakdown, type StageData } from '@/components/ui/StageBreakdown';
import { LinkedRecords, type LinkedRecordGroup } from '@/components/ui/LinkedRecords';
import { ActivityStream, type ActivityItem } from '@/components/ui/ActivityStream';
import { SpecToleranceBar } from '@/components/ui/SpecToleranceBar';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { cn } from '@/lib/cn';
import type { PaginationMeta } from '@/types';

// ── Page furniture ────────────────────────────────────────────────────────

const SECTIONS = [
  { id: 'buttons', title: 'Buttons' },
  { id: 'status', title: 'Chips & badges' },
  { id: 'forms', title: 'Form fields' },
  { id: 'feedback', title: 'Feedback & empty states' },
  { id: 'panels', title: 'Panels & KPI cards' },
  { id: 'controls', title: 'Selection controls' },
  { id: 'tables', title: 'Tables' },
  { id: 'records', title: 'Record patterns' },
  { id: 'overlays', title: 'Overlays (interactive)' },
] as const;

interface SectionProps {
  id: (typeof SECTIONS)[number]['id'];
  title: string;
  note: string;
  /** Overlays are interactive-only — see the header. */
  interactive?: boolean;
  children: React.ReactNode;
}

function Section({ id, title, note, interactive = false, children }: SectionProps) {
  return (
    <section
      id={`gallery-${id}`}
      data-gallery-section={interactive ? undefined : id}
      data-gallery-interactive={interactive ? id : undefined}
      aria-labelledby={`gallery-${id}-heading`}
      className="scroll-mt-24 border-t border-default pt-8 first:border-t-0 first:pt-0"
    >
      <h2 id={`gallery-${id}-heading`} className="text-lg font-medium text-primary">
        {title}
      </h2>
      <p className="mt-1 max-w-3xl text-xs text-muted">{note}</p>
      <div className="mt-4 flex flex-col gap-6">{children}</div>
    </section>
  );
}

/** One tile: the component's name in mono, then the live component. */
function Demo({
  name,
  hint,
  className,
  children,
}: {
  name: string;
  hint?: string;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div className={cn('min-w-0', className)}>
      <div className="mb-2 font-mono text-2xs uppercase tracking-wider text-subtle">{name}</div>
      {children}
      {hint && <p className="mt-1.5 text-2xs text-muted">{hint}</p>}
    </div>
  );
}

// ── Deterministic fixtures ────────────────────────────────────────────────
// Literals only. A gallery that reads the clock or the currency store cannot be
// a visual baseline (the screenshot would move without anybody editing code).

const CHIP_VARIANTS: ChipVariant[] = ['success', 'warning', 'danger', 'info', 'purple', 'neutral'];
const STATUS_SAMPLES = ['approved', 'pending', 'rejected', 'draft', 'in_progress', 'completed'];

const CHAIN_STEPS: ChainStep[] = [
  { key: 'so', label: 'Sales order', date: '2026-04-03', state: 'done' },
  { key: 'mrp', label: 'MRP plan', date: '2026-04-04', state: 'done' },
  { key: 'wo', label: 'Work order', date: '2026-04-06', state: 'active' },
  { key: 'qc', label: 'Outgoing QC', state: 'pending' },
  { key: 'delivery', label: 'Delivery', state: 'pending' },
];

const STAGES: StageData[] = [
  { label: 'Completed', count: 128, percent: 52, color: 'success' },
  { label: 'In process', count: 54, percent: 22, color: 'info' },
  { label: 'Waiting', count: 44, percent: 18, color: 'warning' },
  { label: 'Blocked', count: 20, percent: 8, color: 'danger' },
];

const LINKED: LinkedRecordGroup[] = [
  {
    label: 'Sales order',
    items: [
      { id: 'SO-202604-0003', meta: 'Toyota · due 2026-04-18' },
    ],
  },
  {
    label: 'Work orders',
    items: [
      { id: 'WO-202604-0006', meta: '1,200 pcs', chip: { variant: 'success', text: 'Completed' } },
      { id: 'WO-202604-0007', meta: '800 pcs', chip: { variant: 'warning', text: 'In process' } },
    ],
  },
];

const ACTIVITY: ActivityItem[] = [
  { id: 1, dot: 'success', text: 'Outgoing inspection QC-202604-0012 passed 30/30 samples', time: '2h ago' },
  { id: 2, dot: 'info', text: 'Work order WO-202604-0006 released to Mold 14', time: '5h ago' },
  { id: 3, dot: 'warning', text: 'Mold shot count at 82% of max — preventive schedule suggested', time: 'Yesterday' },
  { id: 4, dot: 'danger', text: 'NCR-202604-0002 raised: dimension 12.5mm over tolerance', time: '2 days ago' },
];

interface GalleryRow {
  id: string;
  number: string;
  product: string;
  customer: string;
  quantity: string;
  status: string;
}

const ROWS: GalleryRow[] = [
  { id: '1', number: 'WO-202604-0006', product: 'Wiper bushing 8mm', customer: 'Toyota Motor Philippines', quantity: '1,200', status: 'completed' },
  { id: '2', number: 'WO-202604-0007', product: 'Pivot cap 12mm', customer: 'Nissan Philippines', quantity: '800', status: 'in_progress' },
  { id: '3', number: 'WO-202604-0008', product: 'Relay cover A', customer: 'Honda Cars Philippines', quantity: '2,400', status: 'pending' },
  { id: '4', number: 'WO-202604-0009', product: 'Wiper bushing 10mm', customer: 'Suzuki Philippines', quantity: '600', status: 'rejected' },
  { id: '5', number: 'WO-202604-0010', product: 'Pivot cap 14mm', customer: 'Yamaha Motor Philippines', quantity: '1,000', status: 'draft' },
];

const ROWS_META: PaginationMeta = {
  current_page: 1,
  last_page: 4,
  per_page: 5,
  total: 18,
  from: 1,
  to: 5,
};

const COLUMNS: Column<GalleryRow>[] = [
  { key: 'number', header: 'Work order', cell: (r) => <span className="font-mono text-primary">{r.number}</span>, sortable: true },
  { key: 'product', header: 'Product', cell: (r) => <StackedCell primary={r.product} secondary={r.customer} /> },
  { key: 'quantity', header: 'Qty', cell: (r) => <NumCell>{r.quantity}</NumCell>, align: 'right' },
  { key: 'status', header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{r.status.replace(/_/g, ' ')}</Chip> },
];

const STATUS_OPTIONS = [
  { value: 'all', label: 'All', count: 246 },
  { value: 'draft', label: 'Draft', count: 12 },
  { value: 'pending', label: 'Pending approval', count: 34 },
  { value: 'approved', label: 'Approved', count: 158 },
  { value: 'rejected', label: 'Rejected', count: 9 },
  { value: 'cancelled', label: 'Cancelled', count: 33 },
];

const DEPARTMENTS = [
  { value: 'production', label: 'Production' },
  { value: 'quality', label: 'Quality Assurance' },
  { value: 'maintenance', label: 'Maintenance' },
  { value: 'accounting', label: 'Accounting' },
];

// ── Page ──────────────────────────────────────────────────────────────────

export default function ComponentGalleryPage() {
  const [status, setStatus] = useState<string>('all');
  const [tab, setTab] = useState<string>('overview');
  const [department, setDepartment] = useState('production');
  const [modalOpen, setModalOpen] = useState(false);
  const [sheetOpen, setSheetOpen] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [currency, setCurrency] = useState('486500.00');

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-8">
      <PageHeader
        title="Component gallery"
        subtitle="Every shared primitive and its states, in isolation. Sections marked with a name are photographed by the visual baseline gate."
        actions={
          <Button
            variant="secondary"
            size="sm"
            icon={<LuRefreshCw size={14} />}
            onClick={() => window.location.reload()}
          >
            Reload
          </Button>
        }
        bottom={
          <nav aria-label="Gallery sections" className="flex flex-wrap gap-x-4 gap-y-1">
            {SECTIONS.map((s) => (
              <a
                key={s.id}
                href={`#gallery-${s.id}`}
                className="text-xs text-muted hover:text-primary hover:underline"
              >
                {s.title}
              </a>
            ))}
          </nav>
        }
      />

      <Section
        id="buttons"
        title="Buttons"
        note="Four variants, five sizes. `loading` keeps the width and swaps the label for a spinner; `iconOnly` is only legal with an accessible name."
      >
        <Demo name="Variants">
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="primary">Primary</Button>
            <Button variant="secondary">Secondary</Button>
            <Button variant="danger">Danger</Button>
            <Button variant="ghost">Ghost</Button>
          </div>
        </Demo>

        <Demo name="States">
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="primary" disabled>Disabled</Button>
            <Button variant="primary" loading>Saving</Button>
            <Button variant="secondary" icon={<LuPlus size={14} />}>New record</Button>
            <Button variant="secondary" icon={<LuDownload size={14} />}>Export</Button>
            <Button variant="secondary" icon={<LuUpload size={14} />}>Import</Button>
            <Button variant="danger" icon={<LuTrash2 size={14} />}>Delete</Button>
          </div>
        </Demo>

        <Demo name="Sizes" hint="xs · sm · md · lg · touch. `touch` is the 44px floor the PWAs use.">
          <div className="flex flex-wrap items-center gap-2">
            <Button size="xs">Extra small</Button>
            <Button size="sm">Small</Button>
            <Button size="md">Medium</Button>
            <Button size="lg">Large</Button>
            <Button size="touch">Touch</Button>
          </div>
        </Demo>

        <Demo name="Icon only" hint="Each carries an aria-label — an icon-only button without one is nameless to a screen reader.">
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="secondary" iconOnly aria-label="Approve" icon={<LuCheck size={14} />} />
            <Button variant="secondary" iconOnly aria-label="Move up" icon={<LuArrowUp size={14} />} />
            <Button variant="secondary" iconOnly aria-label="Move down" icon={<LuArrowDown size={14} />} />
            <Button variant="danger" iconOnly aria-label="Remove line" icon={<LuTrash2 size={14} />} />
          </div>
        </Demo>
      </Section>

      <Section
        id="status"
        title="Chips & badges"
        note="Status is never a bare string in the DOM. `chipVariantForStatus` maps an API status to a semantic variant, so a status the map has not been taught renders neutral instead of inventing a colour."
      >
        <Demo name="Chip — semantic variants">
          <div className="flex flex-wrap items-center gap-2">
            {CHIP_VARIANTS.map((v) => (
              <Chip key={v} variant={v}>{v}</Chip>
            ))}
          </div>
        </Demo>

        <Demo name="chipVariantForStatus — mapped statuses">
          <div className="flex flex-wrap items-center gap-2">
            {STATUS_SAMPLES.map((s) => (
              <Chip key={s} variant={chipVariantForStatus(s)}>{s.replace(/_/g, ' ')}</Chip>
            ))}
          </div>
        </Demo>

        <Demo name="Badge">
          <div className="flex flex-wrap items-center gap-2">
            <Badge>8</Badge>
            <Badge variant="warning">3</Badge>
            <Badge variant="danger">12</Badge>
            <Badge variant="neutral">0</Badge>
          </div>
        </Demo>

        <Demo name="CopyButton" hint="Copies a document number without a text selection, and confirms inline.">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-mono text-sm text-primary">PO-202604-0015</span>
            <CopyButton value="PO-202604-0015" label="purchase order number" size="xs" />
          </div>
        </Demo>
      </Section>

      <Section
        id="forms"
        title="Form fields"
        note="Every field is a labelled control with helper and error slots. Errors are announced (`aria-invalid` + `aria-describedby`), not merely coloured red."
      >
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          <Demo name="Input — default">
            <Input label="Employee name" placeholder="Last, First M." helper="As printed on the 201 file." />
          </Demo>
          <Demo name="Input — error">
            <Input label="Employee number" defaultValue="OGM-2026" error="Expected 4 digits after the year." />
          </Demo>
          <Demo name="Input — disabled">
            <Input label="Company code" defaultValue="OGM" disabled helper="Set at provisioning." />
          </Demo>
          <Demo name="Input — prefix / suffix">
            <Input
              label="Mold shots"
              defaultValue="48200"
              prefix={<span className="text-muted">#</span>}
              suffix={<span className="text-muted">shots</span>}
            />
          </Demo>
          <Demo name="Select">
            <Select label="Department" defaultValue="production">
              {DEPARTMENTS.map((d) => (
                <option key={d.value} value={d.value}>{d.label}</option>
              ))}
            </Select>
          </Demo>
          <Demo name="Select — error">
            <Select label="Payment terms" error="Choose a term to continue." defaultValue="">
              <option value="">Select a term…</option>
              <option value="30">30 days</option>
              <option value="60">60 days</option>
            </Select>
          </Demo>
          <Demo name="SearchableSelect">
            <SearchableSelect
              label="Department head"
              value={department}
              selectedLabel={DEPARTMENTS.find((d) => d.value === department)?.label}
              options={DEPARTMENTS}
              onChange={setDepartment}
              placeholder="Search departments…"
            />
          </Demo>
          <Demo name="Textarea — with counter">
            <Textarea
              label="NCR description"
              maxLength={280}
              defaultValue="Dimension 12.5mm measured 12.82mm on cavitation 3 during outgoing inspection."
              showCounter
            />
          </Demo>
          <Demo name="CurrencyInput" hint="Displays thousands separators; hands the form a raw decimal string.">
            <CurrencyInput label="Unit price" value={currency} onChange={setCurrency} />
          </Demo>
          <Demo name="MaskedInput — SSS">
            <MaskedInput kind="sss" label="SSS number" value="3412345678" />
          </Demo>
          <Demo name="MaskedInput — mobile">
            <MaskedInput kind="mobile" label="Mobile number" value="9171234567" />
          </Demo>
          <Demo name="FileInput">
            <FileInput label="CoC attachment" helper="PDF, up to 10 MB." />
          </Demo>
        </div>

        <Demo name="Checkbox / Radio / Switch">
          <div className="flex flex-col gap-3">
            <Checkbox label="Include cancelled records" defaultChecked />
            <Checkbox label="Email me when this breaks" />
            <div className="flex flex-wrap items-center gap-5">
              <Radio name="gallery-priority" label="Normal" defaultChecked />
              <Radio name="gallery-priority" label="High" />
              <Radio name="gallery-priority" label="Urgent" disabled />
            </div>
            <div className="flex flex-wrap items-center gap-5">
              <Switch label="Auto-approve under ₱5,000" defaultChecked />
              <Switch label="Require VP above ₱50,000" />
            </div>
          </div>
        </Demo>

        <Demo name="FormErrorSummary" hint="The jump-to-field list rendered above a form after a failed submit.">
          <FormErrorSummary
            errors={{
              quantity: { message: 'Must be greater than zero.' },
              delivery_date: { message: 'Must be on or after today.' },
            }}
          />
        </Demo>
      </Section>

      <Section
        id="feedback"
        title="Feedback & empty states"
        note="A failed fetch must never look like an empty list — `QueryErrorState` is the recovery affordance, `EmptyState` is the ‘nothing here yet’ one."
      >
        <Demo name="ProgressBar" hint="Values are clamped: 140 renders full, never past the track (F-003).">
          <div className="flex flex-col gap-3">
            <ProgressBar value={0} ariaLabel="0 of 100" />
            <ProgressBar value={42} />
            <ProgressBar value={100} variant="success" />
            <ProgressBar value={140} variant="danger" height="2" />
          </div>
        </Demo>

        <Demo name="Spinner">
          <div className="flex flex-wrap items-center gap-4">
            <Spinner size="sm" />
            <Spinner />
            <Spinner size="lg" />
          </div>
        </Demo>

        <Demo name="SkeletonBlock / SkeletonTable">
          <div className="flex flex-col gap-3">
            <SkeletonBlock className="h-4 w-40" />
            <SkeletonTable columns={4} rows={3} />
          </div>
        </Demo>

        <Demo name="EmptyState">
          <EmptyState
            icon="inbox"
            title="No work orders yet"
            description="Work orders appear here once MRP releases a plan."
            size="compact"
            action={<Button size="sm">Create work order</Button>}
          />
        </Demo>

        <Demo name="ListEmptyState — filtered">
          <ListEmptyState searchTerm="wiper" route="/maintenance/work-orders" />
        </Demo>

        <Demo name="QueryErrorState">
          <QueryErrorState subject="work orders" onRetry={() => undefined} size="compact" />
        </Demo>
      </Section>

      <Section
        id="panels"
        title="Panels & KPI cards"
        note="The KPI figure sizes against its own card, not the viewport — the contract that closed F-009. Watch the narrow column below: the money figures stay inside their box."
      >
        <Demo name="Panel — title, meta, actions">
          <Panel
            title="Work orders"
            meta="18 open"
            actions={
              <Button size="xs" variant="secondary" icon={<LuPlus size={12} />}>New</Button>
            }
          >
            <p className="text-sm text-secondary">
              Body content sits inside the panel's padding. Header actions stay on one line and
              shrink before they clip.
            </p>
          </Panel>
        </Demo>

        <Demo name="Panel — scrollBody" hint="Opt-in: an over-wide child scrolls instead of being silently clipped (F-004).">
          <Panel title="Lines" scrollBody>
            <table className="w-full min-w-[36rem] text-sm">
              <thead>
                <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-muted">
                  <th className="py-1.5 pr-4 font-medium">Item</th>
                  <th className="py-1.5 pr-4 font-medium">Warehouse</th>
                  <th className="py-1.5 pr-4 font-medium">On hand</th>
                  <th className="py-1.5 font-medium">Reorder point</th>
                </tr>
              </thead>
              <tbody className="font-mono tabular-nums text-secondary">
                <tr className="border-b border-default">
                  <td className="py-1.5 pr-4">RES-ABS-750</td>
                  <td className="py-1.5 pr-4">FG-01</td>
                  <td className="py-1.5 pr-4">12,400 kg</td>
                  <td className="py-1.5">4,000 kg</td>
                </tr>
                <tr>
                  <td className="py-1.5 pr-4">RES-PP-1200</td>
                  <td className="py-1.5 pr-4">RM-02</td>
                  <td className="py-1.5 pr-4">3,150 kg</td>
                  <td className="py-1.5">2,500 kg</td>
                </tr>
              </tbody>
            </table>
          </Panel>
        </Demo>

        <Demo name="StatCard — default, with delta">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard label="Open work orders" value="246" helper="Across 3 lines" />
            <StatCard
              label="On-time delivery"
              value="96.4%"
              delta={{ value: '1.8 pts', direction: 'up' }}
            />
            <StatCard
              label="Scrap rate"
              value="2.1%"
              delta={{ value: '0.4 pts', direction: 'down' }}
              polarity="lower-is-better"
            />
            <StatCard
              label="Revenue this month"
              value="₱ 4,812,300.00"
              delta={{ value: '6.2%', direction: 'up' }}
            />
          </div>
        </Demo>

        <Demo name="StatCard — longest money value" hint="The F-009 case: this must wrap inside the card, never widen the page.">
          <div className="grid max-w-[22rem] grid-cols-2 gap-3">
            <StatCard label="Receivables" value="₱ 12,345,678.90" helper="Aged 0–30" />
            <StatCard label="Collections" value="₱ 1,234,567.89" helper="This cutoff" />
          </div>
        </Demo>
      </Section>

      <Section
        id="controls"
        title="Selection controls"
        note="Both strips are radio/tab semantics with arrow-key movement, so only the selected option is a tab stop — not a row of independent buttons."
      >
        <Demo name="SegmentedControl — six options" hint="F-010: at 390px this wraps onto a second row rather than clipping the last options.">
          <SegmentedControl
            label="Budget status"
            options={STATUS_OPTIONS}
            value={status}
            onChange={setStatus}
          />
        </Demo>

        <Demo name="SegmentedControl — sizes">
          <div className="flex flex-col items-start gap-3">
            <SegmentedControl
              label="Range (small)"
              size="sm"
              options={[
                { value: '7d', label: '7 days' },
                { value: '30d', label: '30 days' },
                { value: '90d', label: '90 days' },
              ]}
              value="30d"
              onChange={() => undefined}
            />
            <SegmentedControl
              label="Range (touch)"
              size="touch"
              options={[
                { value: 'shift', label: 'This shift' },
                { value: 'day', label: 'Today' },
              ]}
              value="day"
              onChange={() => undefined}
            />
          </div>
        </Demo>

        <Demo name="Tabs — local state">
          <Tabs
            label="Work order sections"
            items={[
              { key: 'overview', label: 'Overview' },
              { key: 'output', label: 'Output', count: 12 },
              { key: 'qc', label: 'In-process QC', count: 3 },
              { key: 'downtime', label: 'Downtime', disabled: true },
            ]}
            value={tab}
            onChange={setTab}
          />
        </Demo>

        <Demo name="TabNavigation — routed">
          <TabNavigation
            defaultKey="all"
            tabs={[
              { key: 'all', label: 'All', to: '/admin/component-gallery?tab=all' },
              { key: 'pending', label: 'Pending', to: '/admin/component-gallery?tab=pending' },
              { key: 'closed', label: 'Closed', to: '/admin/component-gallery?tab=closed' },
            ]}
          />
        </Demo>
      </Section>

      <Section
        id="tables"
        title="Tables"
        note="One primitive under 119 call sites: sticky header, mono tabular figures, a pagination footer and an optional selection column."
      >
        <Demo name="DataTable">
          <DataTable columns={COLUMNS} data={ROWS} meta={ROWS_META} getRowId={(r) => r.id} />
        </Demo>
      </Section>

      <Section
        id="records"
        title="Record patterns"
        note="The detail-page vocabulary: where this record sits in its chain, how its population breaks down, what it links to, and what happened to it."
      >
        <Demo name="ChainHeader">
          <ChainHeader steps={CHAIN_STEPS} />
        </Demo>

        <Demo name="StageBreakdown">
          <StageBreakdown title="Work order stages" stages={STAGES} />
        </Demo>

        <Demo name="LinkedRecords">
          <LinkedRecords groups={LINKED} />
        </Demo>

        <Demo name="ActivityStream">
          <ActivityStream items={ACTIVITY} />
        </Demo>

        <Demo name="SpecToleranceBar" hint="Within tolerance, then over the upper limit — actual measurements against the spec.">
          <div className="flex flex-col gap-4">
            <SpecToleranceBar nominal={12.5} min={12.3} max={12.7} value="12.45" unit="mm" />
            <SpecToleranceBar nominal={12.5} min={12.3} max={12.7} value="12.82" unit="mm" />
            <SpecToleranceBar nominal={12.5} min={12.3} max={12.7} value="12.21" unit="mm" />
          </div>
        </Demo>
      </Section>

      <Section
        id="overlays"
        title="Overlays"
        note="Dialog, sheet and tooltip. The dialog rule lives in the primitive: Modal is a centred card at and above 640px and a bottom sheet below it, so no page opts in — the same one you see here is the one every approval and self-service flow opens. Sections are not photographed with their overlays open (a fixed dialog is not inside the section's box); the baseline photographs the two presentations directly instead."
        interactive
      >
        <Demo name="Triggers">
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="secondary" onClick={() => setModalOpen(true)}>Open modal</Button>
            <Button variant="secondary" onClick={() => setSheetOpen(true)}>Open bottom sheet</Button>
            <Button variant="danger" onClick={() => setConfirmOpen(true)}>Open confirm dialog</Button>
            <Tooltip content="Applies to every line below">
              <Button variant="ghost">Hover for tooltip</Button>
            </Tooltip>
          </div>
        </Demo>

        <Modal
          isOpen={modalOpen}
          onClose={() => setModalOpen(false)}
          title="Create work order"
          size="md"
        >
          <div className="flex flex-col gap-4">
            <Input label="Product" defaultValue="Wiper bushing 8mm" />
            <Input label="Quantity" defaultValue="1200" />
          </div>
          <ModalFooter>
            <Button variant="ghost" onClick={() => setModalOpen(false)}>Cancel</Button>
            <Button onClick={() => setModalOpen(false)}>Create</Button>
          </ModalFooter>
        </Modal>

        <BottomSheet
          isOpen={sheetOpen}
          onClose={() => setSheetOpen(false)}
          title="Apply for leave"
        >
          <div className="flex flex-col gap-4">
            <Select label="Leave type" defaultValue="vacation">
              <option value="vacation">Vacation</option>
              <option value="sick">Sick</option>
            </Select>
            <Button onClick={() => setSheetOpen(false)}>Submit request</Button>
          </div>
        </BottomSheet>

        <ConfirmDialog
          isOpen={confirmOpen}
          title="Cancel this purchase order?"
          description="The supplier will be notified. This cannot be undone."
          confirmLabel="Cancel PO"
          variant="danger"
          onConfirm={() => setConfirmOpen(false)}
          onClose={() => setConfirmOpen(false)}
        />
      </Section>
    </div>
  );
}
