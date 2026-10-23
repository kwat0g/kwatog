# Readable DSS Decisions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make all nine existing recommendation families understandable on the list and detail routes, with readable evidence and no raw JSON, internal detector identifiers, or misleading rule confidence percentages.

**Architecture:** Add one pure presentation adapter at the SPA boundary and one shared evidence component. Both pages use them while the existing API, detector rules, permissions, lifecycle, and monetary values remain authoritative. This independently releasable slice precedes the table/panel and lifecycle releases in the program roadmap.

**Tech Stack:** React, TypeScript, existing Atelier components, date-fns through the existing date helpers, Vitest, Testing Library, and React Query.

---

## Scope and references

Read ../specs/2026-10-23-dss-system-design.md and 2026-10-23-dss-system-roadmap.md.

The current screenshot shows a chain recommendation with nested sample_documents rendered as JSON and a confidence percentage derived from a rule match. The two pages also show raw type, domain, and detector names. This slice fixes that presentation completely for the nine registered types.

Source links remain the existing authorized workflow links. The current sample payload has document numbers without public source IDs, so do not construct document detail URLs. Clock semantics, ranking, verified resolution, filters beyond current severity, notifications, and the table/panel layout have separate release boundaries in the roadmap.

The workspace already contains substantial DSS changes. Inspect its current diff before editing. Stage only the named task changes; preserve all previous work.

## File structure

| File | Responsibility |
|---|---|
| Create spa/src/pages/decision-center/presentation.ts | Whitelisted titles, enum labels, facts, and bounded evidence tables |
| Create spa/src/pages/decision-center/DecisionEvidence.tsx | Shared accessible business evidence rendering |
| Create spa/src/pages/decision-center/test-fixtures.ts | Complete non-sensitive recommendation fixture for meaningful presentation tests |
| Create spa/src/pages/decision-center/presentation.test.ts | Business examples, unknown types, malformed nested values, caps, and exact document codes |
| Create spa/src/pages/decision-center/DecisionEvidence.test.tsx | Evidence table, plain text rendering, and hidden internal fields |
| Create spa/src/pages/decision-center/detail.test.tsx | Direct detail route uses the same business presentation |
| Modify spa/src/pages/decision-center/index.tsx | Human heading, summary, evidence, and user-facing check names |
| Modify spa/src/pages/decision-center/detail.tsx | Human title, status, evidence, and workflow action label |
| Modify spa/src/pages/decision-center/helpers.ts and helpers.test.ts | Remove obsolete JSON flattening and unvalidated confidence display |
| Modify spa/src/pages/decision-center/index.test.tsx | Update assertions to business language and preserve existing error/filter coverage |

## Task 1: Define and prove the business presentation boundary

- [ ] **Step 1: Add the complete shared test fixture.**

Create spa/src/pages/decision-center/test-fixtures.ts:

~~~ts
import type { Recommendation } from '@/types/dss';

export function recommendationFixture(
  overrides: Partial<Recommendation> = {},
): Recommendation {
  return {
    id: 'rec-1',
    detector_id: 'dss.chain_bottlenecks',
    detector_version: 'v1',
    domain: 'cross_module',
    type: 'chain_bottleneck',
    severity: 'critical',
    status: 'open',
    priority_score: '0.9',
    impact_estimate: null,
    impact_unit: null,
    impact_is_estimate: true,
    confidence: '1.0000',
    data_sufficiency: 'sufficient',
    rationale: {
      rule: 'Confirmed delivery awaiting customer invoice',
      stuck_count: 1,
      threshold_hours: 4,
      oldest_hours_stuck: 336.5,
      source: 'existing ChainBottleneckService rule',
      sample_documents: [
        { document: 'DR-202610-0002', status: 'confirmed', hours_stuck: 336.5 },
      ],
    },
    action: { route: '/accounting/invoices', label: 'Open workflow queue' },
    subject_type: null,
    expires_at: null,
    snoozed_until: null,
    dismiss_reason: null,
    decided_at: null,
    created_at: '2026-10-23T00:00:00Z',
    updated_at: '2026-10-23T00:00:00Z',
    ...overrides,
  };
}
~~~

- [ ] **Step 2: Write the presentation tests before implementation.**

Create spa/src/pages/decision-center/presentation.test.ts:

~~~ts
import { describe, expect, it } from 'vitest';
import { decisionView, detectorLabel } from './presentation';
import { recommendationFixture } from './test-fixtures';

describe('decision business presentation', () => {
  it('explains the screenshot example without serializing implementation details', () => {
    const view = decisionView(recommendationFixture());

    expect(view.title).toBe(
      'Confirmed delivery awaiting customer invoice · DR-202610-0002',
    );
    expect(view.area).toBe('Connected workflows');
    expect(view.evidenceLabel).toBe('Evidence supplied');
    expect(view.tables[0].columns).toEqual([
      'Document',
      'Status',
      'Reported age (hours)',
    ]);
    expect(view.tables[0].rows).toEqual([
      ['DR-202610-0002', 'Confirmed', '336.5'],
    ]);
    expect(view.facts).toContainEqual({ label: 'Target (hours)', value: '4' });
    expect(JSON.stringify(view)).not.toMatch(
      /ChainBottleneckService|sample_documents|hours_stuck|cross_module|100%/,
    );
  });

  it.each([
    ['chain_bottleneck', 'Connected workflows'],
    ['approval_bottleneck', 'Approvals'],
    ['work_order_overdue', 'Production'],
    ['receivables_priority', 'Finance'],
    ['ncr_sla_breach', 'Quality'],
    ['preventive_maintenance_overdue', 'Maintenance'],
    ['payroll_anomaly_summary', 'Payroll'],
    ['training_expiry', 'People and training'],
    ['master_hygiene', 'Master data'],
  ])('has a business presentation for %s', (type, area) => {
    const view = decisionView(recommendationFixture({ type, rationale: {} }));
    expect(view.area).toBe(area);
    expect(view.title).not.toContain('_');
    expect(view.title).not.toBe('Review business recommendation');
    expect(view.evidenceLabel).toBe('Evidence unavailable');
  });

  it('handles unknown types and malformed nested values without dumping them', () => {
    const unknown = decisionView(
      recommendationFixture({
        type: 'future_internal_type',
        rationale: { debug: { secret: 'internal payload' } },
      }),
    );
    expect(unknown.title).toBe('Review business recommendation');
    expect(unknown.facts).toEqual([]);
    expect(unknown.tables).toEqual([]);
    expect(unknown.evidenceLabel).toBe('Evidence unavailable');
    expect(decisionView(recommendationFixture({ type: 'constructor' })).title)
      .toBe('Review business recommendation');

    const malformed = decisionView(
      recommendationFixture({
        rationale: {
          rule: { code: 'internal_rule' },
          threshold_hours: { raw: 4 },
          sample_documents: [{ document: { raw: 'not a reference' } }],
        },
      }),
    );
    expect(malformed.title).toBe('Review delayed workflow handoffs');
    expect(malformed.tables).toEqual([]);
    expect(malformed.evidenceLabel).toBe('Evidence unavailable');
    expect(JSON.stringify(malformed)).not.toContain('internal_rule');
  });

  it('preserves document identifiers and bounds sample rows', () => {
    const view = decisionView(
      recommendationFixture({
        rationale: {
          sample_documents: Array.from({ length: 8 }, (_, index) => ({
            document: 'CUSTOMER_PO_' + index,
            status: 'in_progress',
            hours_stuck: 0,
          })),
        },
      }),
    );
    expect(view.tables[0].rows).toHaveLength(5);
    expect(view.tables[0].rows[0]).toEqual([
      'CUSTOMER_PO_0',
      'In progress',
      '0',
    ]);
  });

  it('does not invent a duration or expose an unknown enum', () => {
    const view = decisionView(
      recommendationFixture({
        rationale: {
          sample_documents: [
            { document: 'DR-1', status: 'internal_future_state', hours_stuck: -2 },
          ],
        },
      }),
    );
    expect(view.tables[0].rows[0]).toEqual([
      'DR-1',
      'Status unavailable',
      'Not available',
    ]);
    expect(detectorLabel('unregistered.internal_check')).toBe('Decision check');
  });

  it('does not treat an incomplete evidence declaration as complete', () => {
    const view = decisionView(
      recommendationFixture({ data_sufficiency: 'insufficient' }),
    );
    expect(view.evidenceLabel).toBe('Limited evidence');
  });
});
~~~

- [ ] **Step 3: Confirm the tests fail because the presentation module is absent.**

From spa run:

~~~bash
npm run test -- --run src/pages/decision-center/presentation.test.ts
~~~

Expected: the new suite fails to resolve ./presentation. An unrelated configuration or environment failure is not the required red result.

- [ ] **Step 4: Implement the complete presentation adapter.**

Create spa/src/pages/decision-center/presentation.ts:

~~~ts
import type {
  Recommendation,
  RecommendationSeverity,
  RecommendationStatus,
} from '@/types/dss';
import { formatDateTime } from '@/lib/formatDate';

const unavailable = 'Not available';
type Format = 'text' | 'number' | 'date' | 'status' | 'role' | 'equipment';
type Field = [key: string, label: string, format?: Format];

export interface DecisionTable {
  title: string;
  columns: string[];
  rows: string[][];
}

export interface DecisionView {
  title: string;
  area: string;
  summary: string;
  facts: Array<{ label: string; value: string }>;
  tables: DecisionTable[];
  evidenceLabel: 'Evidence supplied' | 'Limited evidence' | 'Evidence unavailable';
  actionLabel: string;
}

interface Definition {
  title: string;
  area: string;
  summary: string;
  actionLabel: string;
  fields: Field[];
  subject?: string;
}

const definitions: Record<string, Definition> = {
  chain_bottleneck: {
    title: 'Review delayed workflow handoffs',
    area: 'Connected workflows',
    summary: 'A source check reported a workflow handoff beyond its configured target. Review the affected documents and verify the next step.',
    actionLabel: 'Review workflow queue',
    fields: [
      ['stuck_count', 'Affected documents', 'number'],
      ['threshold_hours', 'Target (hours)', 'number'],
      ['oldest_hours_stuck', 'Oldest reported age (hours)', 'number'],
    ],
  },
  approval_bottleneck: {
    title: 'Review overdue approvals',
    area: 'Approvals',
    summary: 'Current approval steps have exceeded the configured reminder target. Review the pending queue for the responsible role.',
    actionLabel: 'Review approval queue',
    fields: [
      ['role', 'Responsible role', 'role'],
      ['waiting_count', 'Waiting approvals', 'number'],
      ['oldest_wait_hours', 'Oldest wait (hours)', 'number'],
      ['reminder_threshold_hours', 'Reminder target (hours)', 'number'],
    ],
  },
  work_order_overdue: {
    title: 'Review delayed production',
    area: 'Production',
    summary: 'An active work order has passed its planned completion date. Review its current progress and the remaining work.',
    actionLabel: 'Review work order',
    subject: 'work_order',
    fields: [
      ['work_order', 'Work order'],
      ['status', 'Current status', 'status'],
      ['planned_end', 'Planned completion', 'date'],
      ['days_overdue', 'Days overdue', 'number'],
      ['quantity_target', 'Target quantity'],
      ['quantity_good', 'Accepted output quantity'],
    ],
  },
  receivables_priority: {
    title: 'Review overdue receivables',
    area: 'Finance',
    summary: 'Open invoices have passed the configured collection reminder target. Review their balances and current collection status.',
    actionLabel: 'Review overdue invoices',
    subject: 'customer',
    fields: [
      ['customer', 'Customer'],
      ['overdue_invoice_count', 'Overdue invoices', 'number'],
      ['open_balance', 'Reported open balance (PHP)'],
      ['oldest_due_date', 'Oldest due date', 'date'],
      ['days_overdue', 'Days overdue', 'number'],
      ['first_dunning_tier_days', 'First reminder target (days)', 'number'],
    ],
  },
  ncr_sla_breach: {
    title: 'Review overdue quality response',
    area: 'Quality',
    summary: 'An open non-conformance report has exceeded its response target. Review the report and its corrective-action requirements.',
    actionLabel: 'Review quality report',
    subject: 'ncr_number',
    fields: [
      ['ncr_number', 'Report'],
      ['ncr_severity', 'Source severity', 'status'],
      ['status', 'Current status', 'status'],
      ['hours_since_escalation', 'Elapsed response time (hours)', 'number'],
      ['sla_hours', 'Response target (hours)', 'number'],
      ['escalation_level', 'Escalation level', 'number'],
    ],
  },
  preventive_maintenance_overdue: {
    title: 'Review overdue preventive maintenance',
    area: 'Maintenance',
    summary: 'Preventive work remains open beyond its scheduled due date. Review the maintenance queue and equipment availability.',
    actionLabel: 'Review overdue maintenance',
    fields: [
      ['maintainable_type', 'Equipment category', 'equipment'],
      ['overdue_count', 'Overdue work orders', 'number'],
      ['oldest_due_at', 'Oldest due date', 'date'],
    ],
  },
  payroll_anomaly_summary: {
    title: 'Review unresolved payroll checks',
    area: 'Payroll',
    summary: 'Unresolved checks remain for a computed or approved payroll period. Review those checks in the payroll workflow.',
    actionLabel: 'Review payroll checks',
    fields: [
      ['period_start', 'Period starts', 'date'],
      ['period_end', 'Period ends', 'date'],
      ['unresolved_flags', 'Unresolved checks', 'number'],
    ],
  },
  training_expiry: {
    title: 'Review training certification expiry',
    area: 'People and training',
    summary: 'Current certifications have expired or enter the configured reminder period. Review renewal requirements in the training matrix.',
    actionLabel: 'Review training matrix',
    subject: 'training',
    fields: [
      ['training', 'Training course'],
      ['affected_records', 'Affected certifications', 'number'],
      ['already_expired', 'Already expired', 'number'],
      ['next_expiry', 'Next expiry', 'date'],
      ['horizon_days', 'Reminder period (days)', 'number'],
    ],
  },
  master_hygiene: {
    title: 'Complete missing master data',
    area: 'Master data',
    summary: 'Missing master data can prevent existing automation from completing. Review the affected records and required fields.',
    actionLabel: 'Review master data',
    fields: [['affected_count', 'Affected records', 'number']],
  },
};

const gapTitles: Record<string, string> = {
  items_missing_reorder_point: 'Set missing item reorder points',
  items_missing_safety_stock: 'Set missing item safety stock',
  items_missing_lead_time: 'Set missing item lead times',
  items_missing_standard_cost: 'Set missing item standard costs',
  items_without_approved_supplier: 'Review items without approved suppliers',
  customers_missing_credit_limit: 'Review missing customer credit limits',
  products_without_active_bom: 'Review products without active bills of materials',
};

const statusLabels: Record<string, string> = {
  open: 'New',
  accepted: 'Acknowledged',
  dismissed: 'Dismissed',
  snoozed: 'Snoozed',
  expired: 'Expired',
  executed: 'Action recorded',
  confirmed: 'Confirmed',
  in_progress: 'In progress',
  paused: 'Paused',
  completed: 'Completed',
  cancelled: 'Cancelled',
  draft: 'Draft',
  pending: 'Pending',
  pending_review: 'Pending review',
  approved: 'Approved',
  computed: 'Computed',
  scheduled: 'Scheduled',
  released: 'Released',
  posted: 'Posted',
  paid: 'Paid',
  partially_paid: 'Partially paid',
  critical: 'Critical',
  high: 'High',
  medium: 'Medium',
  low: 'Low',
  warning: 'Warning',
  info: 'Information',
};

const roleLabels: Record<string, string> = {
  vice_president: 'Vice president',
  ppc_head: 'Production planning',
  sales_officer: 'Sales',
  purchasing_officer: 'Purchasing',
  warehouse_staff: 'Warehouse',
  production_manager: 'Production management',
  qc_inspector: 'Quality',
  maintenance_supervisor: 'Maintenance supervision',
  finance_officer: 'Finance',
  hr_officer: 'Human resources',
  department_head: 'Department head',
  customer_service_officer: 'Customer service',
  impex_officer: 'Import and export',
  system_admin: 'System administration',
};

const checkLabels: Record<string, string> = {
  'dss.chain_bottlenecks': 'Workflow handoffs',
  'dss.approval_bottlenecks': 'Approval response',
  'dss.work_order_intervention': 'Production completion',
  'dss.receivables_priority': 'Overdue receivables',
  'dss.quality_ncr_sla': 'Quality response',
  'dss.preventive_maintenance_overdue': 'Preventive maintenance',
  'dss.payroll_anomaly_summary': 'Payroll readiness',
  'dss.training_expiry_summary': 'Training expiry',
  'dss.master_hygiene': 'Master data completeness',
};

function label(labels: Record<string, string>, value: string, fallback: string): string {
  return Object.prototype.hasOwnProperty.call(labels, value) ? labels[value] : fallback;
}

export const detectorLabel = (id: string): string =>
  label(checkLabels, id, 'Decision check');

export const severityLabel = (severity: RecommendationSeverity): string =>
  label(statusLabels, severity, 'Priority unavailable');

export const recommendationStatusLabel = (status: RecommendationStatus): string =>
  label(statusLabels, status, 'Status unavailable');

function scalar(value: unknown): string | null {
  if (typeof value === 'string') return value.trim().slice(0, 500) || null;
  if (typeof value === 'number' && Number.isFinite(value)) return String(value);
  return null;
}

function formatted(value: unknown, kind: Format = 'text'): string {
  const text = scalar(value);
  if (text === null) return unavailable;
  if (kind === 'date') return formatDateTime(text, unavailable);
  if (kind === 'number') {
    const number = Number(text);
    return Number.isFinite(number) && number >= 0
      ? number.toLocaleString('en-PH', { maximumFractionDigits: 1 })
      : unavailable;
  }
  if (kind === 'status') return label(statusLabels, text, 'Status unavailable');
  if (kind === 'role') return label(roleLabels, text, 'Assigned approval role');
  if (kind === 'equipment') {
    if (/(^|\\)Machine$/.test(text) || text === 'machine') return 'Machines';
    if (/(^|\\)Mold$/.test(text) || text === 'mold') return 'Molds';
    if (/(^|\\)Asset$/.test(text) || text === 'asset') return 'Assets';
    return 'Equipment';
  }
  return text;
}

function record(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export function decisionView(rec: Recommendation): DecisionView {
  const definition = Object.prototype.hasOwnProperty.call(definitions, rec.type)
    ? definitions[rec.type]
    : undefined;
  if (!definition) {
    return {
      title: 'Review business recommendation',
      area: 'Business operations',
      summary: 'Detailed evidence is unavailable for this recommendation. Review the related workflow to verify the issue.',
      facts: [],
      tables: [],
      evidenceLabel: 'Evidence unavailable',
      actionLabel: 'Open related workflow',
    };
  }

  const rationale = record(rec.rationale) ? rec.rationale : {};
  const facts = definition.fields.map(([key, label, kind]) => ({
    label,
    value: formatted(rationale[key], kind),
  }));
  const tables: DecisionTable[] = [];
  let title = definition.title;

  if (definition.subject) {
    const subject = scalar(rationale[definition.subject]);
    if (subject) title += ' · ' + subject;
  }

  if (rec.type === 'chain_bottleneck') {
    const rule = scalar(rationale.rule);
    if (rule && !/[_\\{}]|::|ChainBottleneckService/.test(rule)) title = rule;
    const samples = Array.isArray(rationale.sample_documents)
      ? rationale.sample_documents.slice(0, 5).filter(record)
      : [];
    const rows = samples.flatMap((sample) => {
      const document = scalar(sample.document);
      return document
        ? [[document, formatted(sample.status, 'status'), formatted(sample.hours_stuck, 'number')]]
        : [];
    });
    if (rows.length > 0) {
      title += ' · ' + rows[0][0];
      tables.push({
        title: 'Sample affected documents',
        columns: ['Document', 'Status', 'Reported age (hours)'],
        rows,
      });
    }
  }

  if (rec.type === 'master_hygiene') {
    const gap = scalar(rationale.gap);
    if (gap) title = label(gapTitles, gap, title);
    const references = Array.isArray(rationale.evidence)
      ? rationale.evidence.slice(0, 10).flatMap((value) => {
          const reference = scalar(value);
          return reference ? [[reference]] : [];
        })
      : [];
    if (references.length > 0) {
      tables.push({
        title: 'Sample affected records',
        columns: ['Reference'],
        rows: references,
      });
    }
  }

  const supplied = facts.some(({ value }) => value !== unavailable)
    || tables.some((table) => table.rows.length > 0);
  const limited = rec.data_sufficiency !== 'sufficient'
    || facts.some(({ value }) => value === unavailable);

  return {
    title,
    area: definition.area,
    summary: definition.summary,
    facts,
    tables,
    evidenceLabel: !supplied
      ? 'Evidence unavailable'
      : limited
        ? 'Limited evidence'
        : 'Evidence supplied',
    actionLabel: definition.actionLabel,
  };
}
~~~

The adapter formats presentation only. Reported age is deliberately labelled as reported because the source-clock fix is a separate prerequisite. No raw object, arbitrary rationale key, unknown enum, confidence probability, or model namespace becomes visible.

- [ ] **Step 5: Run the focused test and formatting/type checks.**

From spa run:

~~~bash
npm run test -- --run src/pages/decision-center/presentation.test.ts
npm run typecheck
./node_modules/.bin/prettier --check src/pages/decision-center/presentation.ts src/pages/decision-center/presentation.test.ts src/pages/decision-center/test-fixtures.ts
~~~

Expected: presentation suite passes, typecheck exits 0, and formatting passes after formatting the new files with the installed Prettier binary if necessary.

- [ ] **Step 6: Commit only the three task files after reviewing their diff.**

~~~bash
git add spa/src/pages/decision-center/presentation.ts spa/src/pages/decision-center/presentation.test.ts spa/src/pages/decision-center/test-fixtures.ts
git commit -m "feat(dss): add readable decision presentation"
~~~

Run git commands from the repository root. Existing unrelated changes must remain unstaged.

## Task 2: Render structured business evidence

- [ ] **Step 1: Write a rendering regression test.**

Create spa/src/pages/decision-center/DecisionEvidence.test.tsx:

~~~tsx
import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { DecisionEvidence } from './DecisionEvidence';
import { recommendationFixture } from './test-fixtures';

describe('DecisionEvidence', () => {
  it('shows the sample as a readable evidence table', () => {
    render(<DecisionEvidence recommendation={recommendationFixture()} />);
    const table = screen.getByRole('table', { name: 'Sample affected documents' });
    expect(within(table).getByRole('columnheader', { name: 'Document' })).toBeVisible();
    expect(within(table).getByText('DR-202610-0002')).toBeVisible();
    expect(within(table).getByText('Confirmed')).toBeVisible();
    expect(within(table).getByText('336.5')).toBeVisible();
    expect(screen.queryByText(/sample_documents|hours_stuck|ChainBottleneckService|100%/))
      .not.toBeInTheDocument();
  });

  it('renders source text as text rather than HTML', () => {
    const { container } = render(
      <DecisionEvidence
        recommendation={recommendationFixture({
          rationale: {
            sample_documents: [
              { document: '<img src=x onerror=alert(1)>', status: 'confirmed', hours_stuck: 2 },
            ],
          },
        })}
      />,
    );
    expect(screen.getByText('<img src=x onerror=alert(1)>')).toBeVisible();
    expect(container.querySelector('img')).toBeNull();
  });
});
~~~

- [ ] **Step 2: Run the new test and observe the missing-component failure.**

From spa run:

~~~bash
npm run test -- --run src/pages/decision-center/DecisionEvidence.test.tsx
~~~

Expected: failure to resolve ./DecisionEvidence.

- [ ] **Step 3: Add the shared component.**

Create spa/src/pages/decision-center/DecisionEvidence.tsx:

~~~tsx
import type { Recommendation } from '@/types/dss';
import { decisionView } from './presentation';

export function DecisionEvidence({
  recommendation,
}: {
  recommendation: Recommendation;
}) {
  const view = decisionView(recommendation);

  return (
    <section aria-label="Recorded evidence" className="space-y-3">
      <h3 className="text-xs font-medium text-primary">Recorded evidence</h3>
      <p className="text-sm text-secondary">{view.summary}</p>
      <p className="text-xs text-muted">{view.evidenceLabel}</p>
      {view.facts.length > 0 && (
        <dl className="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
          {view.facts.map((fact) => (
            <div key={fact.label} className="min-w-0">
              <dt className="text-xs text-muted">{fact.label}</dt>
              <dd className="break-words text-sm text-primary">{fact.value}</dd>
            </div>
          ))}
        </dl>
      )}
      {view.tables.map((table) => (
        <div key={table.title} className="overflow-x-auto rounded-md border border-default">
          <table className="w-full text-left text-xs">
            <caption className="px-3 py-2 text-left font-medium text-primary">
              {table.title}
            </caption>
            <thead className="bg-surface text-secondary">
              <tr>
                {table.columns.map((column) => (
                  <th key={column} scope="col" className="px-3 py-2 font-medium">
                    {column}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-subtle">
              {table.rows.map((row, index) => (
                <tr key={index}>
                  {row.map((cell, cellIndex) => (
                    <td key={cellIndex} className="max-w-xs break-words px-3 py-2 text-primary">
                      {cell}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}
    </section>
  );
}
~~~

These tables are bounded static evidence, so use native semantic table markup inside existing tokens. The recommendation queue itself uses the shared DataTable in R2. No new table framework is required.

- [ ] **Step 4: Run the presentation and evidence suites together.**

From spa run:

~~~bash
npm run test -- --run src/pages/decision-center/presentation.test.ts src/pages/decision-center/DecisionEvidence.test.tsx
~~~

Expected: both suites pass, including exact source references, readable status, and escaped source text.

- [ ] **Step 5: Commit the component and its test.**

~~~bash
git add spa/src/pages/decision-center/DecisionEvidence.tsx spa/src/pages/decision-center/DecisionEvidence.test.tsx
git commit -m "feat(dss): render structured decision evidence"
~~~

## Task 3: Integrate both routes and remove the misleading presentation

- [ ] **Step 1: Add a direct-detail regression test.**

Create spa/src/pages/decision-center/detail.test.tsx:

~~~tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { dssApi } from '@/api/dss';
import RecommendationDetailPage from './detail';
import { recommendationFixture } from './test-fixtures';

vi.mock('@/api/dss', () => ({
  dssApi: { show: vi.fn(), act: vi.fn(), outcome: vi.fn() },
}));

describe('recommendation detail business language', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(dssApi.show).mockResolvedValue(recommendationFixture());
  });

  it('uses the business heading and structured evidence on a direct visit', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={['/decision-center/rec-1']}>
          <Routes>
            <Route path="/decision-center/:id" element={<RecommendationDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await screen.findByRole('heading', {
      name: 'Confirmed delivery awaiting customer invoice · DR-202610-0002',
    });
    expect(screen.getByRole('table', { name: 'Sample affected documents' })).toBeVisible();
    expect(screen.queryByText(/dss\.chain_bottlenecks|cross_module|100%/))
      .not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Review workflow queue' })).toBeVisible();
  });
});
~~~

- [ ] **Step 2: Change the existing list tests to assert business wording.**

In spa/src/pages/decision-center/index.test.tsx use the following exact assertion changes:

~~~tsx
// The expand/collapse test:
fireEvent.click(await screen.findByRole('button', { name: 'Why this?' }));
expect(screen.getByRole('region', { name: 'Recorded evidence' })).toBeVisible();
fireEvent.click(screen.getByRole('button', { name: 'Hide evidence' }));
expect(screen.queryByRole('region', { name: 'Recorded evidence' }))
  .not.toBeInTheDocument();

// The health panel tests:
expect(screen.getByText('2 decision checks need attention')).toBeVisible();

// The expanded failed/partial health test:
fireEvent.click(await screen.findByText('2 decision checks need attention'));
expect(screen.getByText('Payroll readiness')).toBeVisible();
expect(screen.getByText('Quality response')).toBeVisible();
expect(screen.getByText('Partial')).toBeVisible();

// The failed-refresh test, both the initial link and the absence assertion:
await screen.findByRole('link', { name: 'Complete missing master data' });
expect(screen.queryByRole('link', { name: 'Complete missing master data' }))
  .not.toBeInTheDocument();
~~~

Preserve the intervening refresh click, awaited failure heading, filter test, and mocked responses. Replace every existing health heading occurrence and both old master-hygiene link name occurrences.

- [ ] **Step 3: Confirm the new expectations fail against the existing routes.**

From spa run:

~~~bash
npm run test -- --run src/pages/decision-center/index.test.tsx src/pages/decision-center/detail.test.tsx
~~~

Expected: failures on the new business heading, evidence region/table, human health names, and Hide evidence control.

- [ ] **Step 4: Integrate the list route using these complete replacements.**

In spa/src/pages/decision-center/index.tsx replace the helpers import and add the two local imports:

~~~tsx
import { formatImpact } from './helpers';
import { DecisionEvidence } from './DecisionEvidence';
import { decisionView, detectorLabel, severityLabel } from './presentation';
~~~

Replace the entire WhyPanel function:

~~~tsx
function WhyPanel({ rec }: { rec: Recommendation }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
        className="text-xs text-link flex items-center gap-1"
      >
        {open ? 'Hide evidence' : 'Why this?'}
        <LuChevronDown size={12} className={open ? 'rotate-180' : undefined} />
      </button>
      {open && (
        <div className="mt-2 rounded-md border border-subtle bg-elevated p-3">
          <DecisionEvidence recommendation={rec} />
        </div>
      )}
    </>
  );
}
~~~

In DetectorHealth change the summary text and replace the item name span:

~~~tsx
{checks.length} decision check{checks.length === 1 ? '' : 's'} need attention
~~~

~~~tsx
<span className="min-w-0 break-words text-xs text-primary">
  {detectorLabel(check.id)}
</span>
~~~

Replace the severity heading value:

~~~tsx
<span>{severityLabel(sev)}</span>
~~~

Inside items.map, after computing impact, add:

~~~tsx
const view = decisionView(rec);
~~~

Replace the link contents and adjacent detector chip:

~~~tsx
<Link
  to={'/decision-center/' + rec.id}
  className="break-words text-sm font-medium text-link"
>
  {view.title}
</Link>
<Chip variant="neutral">{view.area}</Chip>
~~~

Replace the confidence span and the entire thin-data conditional:

~~~tsx
<span className="text-2xs text-text-subtle">{view.evidenceLabel}</span>
~~~

Above the WhyPanel wrapper, add the business explanation:

~~~tsx
<p className="mt-2 text-xs text-secondary">{view.summary}</p>
~~~

Leave the existing lifecycle actions intact until R3. Their current behavior must not be renamed to verified resolution.

- [ ] **Step 5: Integrate the direct detail route.**

In spa/src/pages/decision-center/detail.tsx replace the helpers import and add local imports:

~~~tsx
import { formatImpact, severityVariant } from './helpers';
import { DecisionEvidence } from './DecisionEvidence';
import {
  decisionView,
  recommendationStatusLabel,
  severityLabel,
} from './presentation';
~~~

After the existing impact declaration add:

~~~tsx
const view = decisionView(rec);
~~~

Replace PageHeader with:

~~~tsx
<PageHeader
  title={view.title}
  subtitle={view.area}
  backTo="/decision-center"
  backLabel="Decision Center"
/>
~~~

Replace the content of the current severity/status/impact/confidence row:

~~~tsx
<Chip variant={severityVariant(rec.severity)}>{severityLabel(rec.severity)}</Chip>
<Chip variant={statusVariant(rec.status)}>
  {recommendationStatusLabel(rec.status)}
</Chip>
{impact && (
  <span className="font-mono tabular-nums text-sm text-primary">{impact}</span>
)}
<span className="text-xs text-text-subtle">{view.evidenceLabel}</span>
~~~

Replace the complete Why this section with:

~~~tsx
<section className="rounded-md border border-subtle bg-canvas px-3 py-2.5">
  <DecisionEvidence recommendation={rec} />
</section>
~~~

Within the existing suggested-action LinkButton replace its content with:

~~~tsx
{view.actionLabel}
~~~

Keep the existing route, mutation error handling, action controls, and outcome form. That preserves lifecycle behavior until its dedicated migration.

- [ ] **Step 6: Remove unused JSON and confidence helpers and their obsolete tests.**

Replace spa/src/pages/decision-center/helpers.ts with:

~~~ts
import type { RecommendationSeverity } from '@/types/dss';

export type SeverityVariant = 'danger' | 'warning' | 'info';

export const severityVariant = (s: RecommendationSeverity): SeverityVariant =>
  s === 'critical' ? 'danger' : s === 'warning' ? 'warning' : 'info';

export function formatImpact(
  estimate: string | null,
  unit: string | null,
  isEstimate: boolean,
): string | null {
  if (estimate === null) return null;
  const amount = unit ? estimate + ' ' + unit : estimate;
  return isEstimate ? '~' + amount : amount;
}
~~~

Replace spa/src/pages/decision-center/helpers.test.ts with:

~~~ts
import { describe, expect, it } from 'vitest';
import { formatImpact, severityVariant } from './helpers';

describe('decision display helpers', () => {
  it('maps severity to its system chip variant', () => {
    expect(severityVariant('critical')).toBe('danger');
    expect(severityVariant('warning')).toBe('warning');
    expect(severityVariant('info')).toBe('info');
  });

  it('preserves exact monetary text and identifies estimates', () => {
    expect(formatImpact('90071992547409.91', 'PHP', false))
      .toBe('90071992547409.91 PHP');
    expect(formatImpact('1500.00', 'PHP', true)).toBe('~1500.00 PHP');
    expect(formatImpact(null, 'PHP', true)).toBeNull();
  });
});
~~~

- [ ] **Step 7: Run the complete focused SPA checks.**

From spa run:

~~~bash
npm run test -- --run src/pages/decision-center
npm run typecheck
./node_modules/.bin/prettier --check src/pages/decision-center
~~~

From the repository root run:

~~~bash
git diff --check
node scripts/check-dss-route-parity.mjs
rg -n 'rationaleEntries|formatConfidence|JSON\.stringify|rec\.detector_id|rec\.type\.replace|rec\.domain' spa/src/pages/decision-center/index.tsx spa/src/pages/decision-center/detail.tsx spa/src/pages/decision-center/DecisionEvidence.tsx
~~~

Expected: tests/typecheck/formatting/parity pass. The final rg has no matches and exits 1. Do not treat the expected empty search as a failed implementation check. Tests may use JSON.stringify to inspect a view object; production business rendering may not serialize raw evidence.

- [ ] **Step 8: Review the visual result against existing system pages.**

Use the isolated demo environment with at least a chain sample, production issue, finance exposure, maintenance issue, and malformed/unknown example. Inspect desktop and mobile together. Open the direct detail route as well as the list. Confirm readable evidence, exact references, theme tokens, wrapping, keyboard operation, preserved error handling, and no invented source links or probabilities. Correct observed defects in one batch and confirm once.

Do not enable every detector in a shared environment to obtain screenshots. Use the isolated scenario setup from the roadmap and existing role-scoping rules.

- [ ] **Step 9: Review the exact diff and commit only this integration task.**

~~~bash
git add spa/src/pages/decision-center/index.tsx spa/src/pages/decision-center/index.test.tsx spa/src/pages/decision-center/detail.tsx spa/src/pages/decision-center/detail.test.tsx spa/src/pages/decision-center/helpers.ts spa/src/pages/decision-center/helpers.test.ts
git commit -m "feat(dss): use business language across decision routes"
~~~

## Completion and handoff

- [ ] Record the focused checks and browser evidence actually obtained.
- [ ] Update docs/dss/PROGRESS.md with R1 presentation coverage, preserving existing capability limitations.
- [ ] Confirm all nine types have human titles, labels, bounded evidence, and honest unavailable states.
- [ ] Confirm the source-clock issue, deep source links, workbench layout, lifecycle semantics, notifications, and domain expansion remain explicitly tracked in the system roadmap.
- [ ] Continue with the independent R2 workbench implementation plan only when implementation is authorized.

This plan contains proposed code for implementation. Creating the plan does not apply that code, execute its tests, change detector modes, or seed any database.
