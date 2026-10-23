import { describe, expect, it } from 'vitest';
import {
  decisionView,
  detectorLabel,
  recommendationStatusLabel,
  severityLabel,
} from './presentation';
import { recommendationFixture } from './test-fixtures';

describe('decisionView', () => {
  it('presents chain evidence with human labels and exact document references', () => {
    const view = decisionView(recommendationFixture());
    expect(view).toMatchObject({
      title: 'Confirmed delivery awaiting customer invoice · DR-202610-0002',
      area: 'Connected workflows',
      summary: expect.stringContaining('workflow handoff'),
      evidenceLabel: 'Evidence supplied',
      actionLabel: 'Review workflow queue',
      facts: [
        { label: 'Affected documents', value: '1' },
        { label: 'Target (hours)', value: '4' },
        { label: 'Oldest reported age (hours)', value: '336.5' },
      ],
      tables: [
        {
          title: 'Sample affected documents',
          columns: ['Document', 'Status', 'Reported age (hours)'],
          rows: [['DR-202610-0002', 'Confirmed', '336.5']],
        },
      ],
    });
    expect(JSON.stringify(view)).not.toContain('ChainBottleneckService');
  });

  it.each([
    ['chain_bottleneck', 'Review delayed workflow handoffs', 'Connected workflows'],
    ['approval_bottleneck', 'Review overdue approvals', 'Approvals'],
    ['work_order_overdue', 'Review delayed production', 'Production'],
    ['receivables_priority', 'Review overdue receivables', 'Finance'],
    ['ncr_sla_breach', 'Review overdue quality response', 'Quality'],
    ['preventive_maintenance_overdue', 'Review overdue preventive maintenance', 'Maintenance'],
    ['payroll_anomaly_summary', 'Review unresolved payroll flags', 'Payroll'],
    ['training_expiry', 'Review training certification expiry', 'People and training'],
    ['master_hygiene', 'Complete missing master data', 'Master data'],
  ])('uses safe defaults for %s', (type, title, area) => {
    const view = decisionView(recommendationFixture({ type, rationale: {} }));
    expect(view).toMatchObject({ title, area, evidenceLabel: 'Evidence unavailable' });
    expect(view.facts.every((fact) => fact.value === 'Not available')).toBe(true);
  });

  it('handles unknown and prototype type names without exposing identifiers', () => {
    for (const type of ['unknown_type', 'constructor', '__proto__', 'toString']) {
      expect(decisionView(recommendationFixture({ type, rationale: {} }))).toEqual({
        title: 'Review business recommendation',
        area: 'Business operations',
        summary: expect.any(String),
        facts: [],
        tables: [],
        evidenceLabel: 'Evidence unavailable',
        actionLabel: 'Open related workflow',
      });
    }
  });

  it('limits rows and references, preserves underscores, and rejects invalid numeric values', () => {
    const rows = Array.from({ length: 7 }, (_, i) => ({
      document: `DOC_${i}`,
      status: i ? 'unknown' : 'confirmed',
      hours_stuck: i ? -1 : 'bad',
    }));
    const view = decisionView(
      recommendationFixture({
        rationale: {
          rule: 'ChainBottleneckService {internal}',
          stuck_count: -1,
          threshold_hours: 'bad',
          oldest_hours_stuck: -2,
          sample_documents: rows,
        },
      }),
    );
    expect(view.title).toBe('Review delayed workflow handoffs · DOC_0');
    expect(view.facts).toEqual([
      { label: 'Affected documents', value: 'Not available' },
      { label: 'Target (hours)', value: 'Not available' },
      { label: 'Oldest reported age (hours)', value: 'Not available' },
    ]);
    expect(view.tables[0]?.rows).toHaveLength(5);
    expect(view.tables[0]?.rows[0]).toEqual(['DOC_0', 'Confirmed', 'Not available']);
    expect(view.tables[0]?.rows[1]).toEqual(['DOC_1', 'Status unavailable', 'Not available']);
    expect(view.evidenceLabel).toBe('Limited evidence');
    const master = decisionView(
      recommendationFixture({
        type: 'master_hygiene',
        rationale: {
          affected_count: 1,
          evidence: [null, ...Array.from({ length: 12 }, (_, i) => `CUSTOMER_PO_${i}`)],
        },
      }),
    );
    expect(master.tables[0]?.rows).toHaveLength(10);
    expect(master.tables[0]?.rows[0]).toEqual(['CUSTOMER_PO_0']);
  });

  it('marks partial or insufficient evidence limited', () => {
    expect(decisionView(recommendationFixture({ data_sufficiency: 'partial' })).evidenceLabel).toBe(
      'Limited evidence',
    );
    expect(
      decisionView(recommendationFixture({ rationale: { stuck_count: 1 } })).evidenceLabel,
    ).toBe('Limited evidence');
  });

  it('formats non-chain type fields without exposing private payroll data', () => {
    const approval = decisionView(
      recommendationFixture({
        type: 'approval_bottleneck',
        data_sufficiency: 'partial',
        rationale: {
          queue: 'Payroll',
          role: 'production_manager',
          waiting_count: 3,
          oldest_wait_hours: 2.5,
          reminder_threshold_hours: 1,
          excluded_queues: ['Leave', 'Purchase requests', 'Purchase orders', 'Loans'],
        },
      }),
    );
    expect(approval.facts.map(({ label }) => label)).toEqual([
      'Approval queue',
      'Approver role',
      'Waiting approvals',
      'Oldest wait (hours)',
      'Reminder target (hours)',
      'Excluded row-scoped queues',
    ]);
    expect(approval.facts[0]?.value).toBe('Payroll');
    expect(approval.facts[1]?.value).toBe('Production manager');
    expect(approval.facts[5]?.value).toBe('Leave, Purchase requests, Purchase orders, Loans');
    expect(approval.title).toBe('Review overdue Payroll approvals');
    expect(approval.actionLabel).toBe('Review Payroll approvals');
    expect(approval.evidenceLabel).toBe('Limited evidence');
    const payroll = decisionView(
      recommendationFixture({
        type: 'payroll_anomaly_summary',
        rationale: {
          period_start: '2026-10-01',
          period_end: '2026-10-15',
          unresolved_flags: 2,
          employee_name: 'Private Person',
        },
      }),
    );
    expect(JSON.stringify(payroll)).not.toContain('Private Person');
    expect(payroll.facts.map(({ label }) => label)).toEqual([
      'Period start',
      'Period end',
      'Unresolved checks',
    ]);
  });

  it('formats production, receivables, quality, maintenance, and master data details', () => {
    const workOrder = decisionView(
      recommendationFixture({
        type: 'work_order_overdue',
        rationale: {
          work_order: 'WO_104',
          status: 'in_progress',
          planned_end: '2026-10-01',
          days_overdue: 2.25,
          quantity_target: 1000,
          quantity_good: 950,
          remaining_quantity: 50,
          unit_of_measure: 'pcs',
        },
      }),
    );
    expect(workOrder.title).toBe('Review delayed production · WO_104');
    expect(workOrder.facts.map(({ value }) => value)).toEqual([
      'WO_104',
      'In progress',
      'Oct 1, 2026 · 12:00 AM',
      '2.3',
      '1,000',
      '950',
      '50',
      'pcs',
    ]);
    const receivables = decisionView(
      recommendationFixture({
        type: 'receivables_priority',
        rationale: {
          customer: 'CUST_7',
          overdue_invoice_count: 2,
          open_balance: '001250.00',
          oldest_due_date: '2026-10-01',
          days_overdue: 3,
          first_dunning_tier_days: 5,
        },
      }),
    );
    expect(receivables.facts[2]?.value).toBe('001250.00');
    const ncr = decisionView(
      recommendationFixture({
        type: 'ncr_sla_breach',
        rationale: {
          ncr_number: 'NCR_10',
          ncr_severity: 'high',
          status: 'open',
          hours_since_escalation: 3,
          sla_hours: 2,
          escalation_level: 1,
        },
      }),
    );
    expect(ncr.facts.slice(0, 3).map(({ value }) => value)).toEqual(['NCR_10', 'High', 'Open']);
    const maintenance = decisionView(
      recommendationFixture({
        type: 'preventive_maintenance_overdue',
        rationale: {
          maintainable_type: 'App\\Modules\\MRP\\Models\\Mold',
          overdue_count: 1,
          oldest_due_at: '2026-10-02T03:00:00Z',
        },
      }),
    );
    expect(maintenance.summary).toContain('Molds');
    expect(maintenance.facts[0]).toEqual({ label: 'Equipment category', value: 'Molds' });
    expect(maintenance.facts[2]?.value).toBe('Oct 2, 2026 · 3:00 AM');
    const master = decisionView(
      recommendationFixture({
        type: 'master_hygiene',
        rationale: { gap: 'items_missing_reorder_point', affected_count: 1 },
      }),
    );
    expect(master.title).toBe('Set missing item reorder points');
  });

  it('labels emitted approval roles, NCR severities, and work order statuses', () => {
    for (const [role, label] of [
      ['vice_president', 'Vice president'],
      ['ppc_head', 'PPC head'],
      ['sales_officer', 'Sales officer'],
      ['purchasing_officer', 'Purchasing officer'],
      ['warehouse_staff', 'Warehouse staff'],
      ['production_manager', 'Production manager'],
      ['qc_inspector', 'Quality inspector'],
      ['maintenance_supervisor', 'Maintenance supervisor'],
      ['maintenance_tech', 'Maintenance technician'],
      ['employee', 'Employee'],
      ['driver', 'Driver'],
      ['finance_officer', 'Finance officer'],
      ['hr_officer', 'HR officer'],
      ['department_head', 'Department head'],
      ['customer_service_officer', 'Customer service officer'],
      ['impex_officer', 'Import/export officer'],
      ['system_admin', 'System administrator'],
      ['unassigned', 'No role assigned'],
    ]) {
      const view = decisionView(
        recommendationFixture({
          type: 'approval_bottleneck',
          rationale: { role, waiting_count: 1, oldest_wait_hours: 2, reminder_threshold_hours: 1 },
        }),
      );
      expect(view.facts[0]?.value).toBe(label);
    }
    for (const [severity, label] of [
      ['critical', 'Critical'],
      ['high', 'High'],
      ['medium', 'Medium'],
      ['low', 'Low'],
    ]) {
      const view = decisionView(
        recommendationFixture({
          type: 'ncr_sla_breach',
          rationale: {
            ncr_number: 'NCR_10',
            ncr_severity: severity,
            status: 'in_progress',
            hours_since_escalation: 3,
            sla_hours: 2,
            escalation_level: 1,
          },
        }),
      );
      expect(view.facts[1]?.value).toBe(label);
      expect(view.facts[2]?.value).toBe('In progress');
    }
    const paused = decisionView(
      recommendationFixture({
        type: 'work_order_overdue',
        rationale: {
          work_order: 'WO_1',
          status: 'paused',
          planned_end: '2026-10-01T12:30:00Z',
          days_overdue: 2,
          quantity_target: 10,
          quantity_good: 4,
        },
      }),
    );
    expect(paused.facts[1]?.value).toBe('Paused');
    expect(paused.facts[2]?.value).toContain('12:30');
    for (const [raw, label] of [
      ['draft', 'Draft'],
      ['scheduled', 'Scheduled'],
      ['accepted', 'Accepted'],
    ]) {
      const view = decisionView(
        recommendationFixture({
          type: 'work_order_overdue',
          rationale: {
            work_order: 'WO_1',
            status: raw,
            planned_end: '2026-10-01T12:30:00Z',
            days_overdue: 2,
            quantity_target: 10,
            quantity_good: 4,
          },
        }),
      );
      expect(view.facts[1]?.value).toBe(label);
    }
  });

  it('ignores malformed chain samples and requires valid rendered evidence', () => {
    const invalidFacts = decisionView(
      recommendationFixture({
        rationale: {
          stuck_count: -1,
          threshold_hours: 'not a number',
          oldest_hours_stuck: -3,
          sample_documents: [
            null,
            { document: 12, status: 'confirmed', hours_stuck: 1 },
            { document: '', status: 'confirmed', hours_stuck: 1 },
          ],
        },
      }),
    );
    expect(invalidFacts.tables).toEqual([]);
    expect(invalidFacts.evidenceLabel).toBe('Evidence unavailable');
    const unknownStatus = decisionView(
      recommendationFixture({
        rationale: {
          stuck_count: 1,
          threshold_hours: 4,
          oldest_hours_stuck: 8,
          sample_documents: [{ document: 'DOC_1', status: 'unmapped_status', hours_stuck: 8 }],
        },
      }),
    );
    expect(unknownStatus.evidenceLabel).toBe('Limited evidence');
    const oldMasterAlias = decisionView(
      recommendationFixture({
        type: 'master_hygiene',
        rationale: { affected_count: 1, sample_references: ['SHOULD_NOT_APPEAR'] },
      }),
    );
    expect(oldMasterAlias.tables).toEqual([]);
  });

  it('marks unknown work order status as limited evidence', () => {
    const view = decisionView(
      recommendationFixture({
        type: 'work_order_overdue',
        rationale: {
          work_order: 'WO_1',
          status: 'future_state',
          planned_end: '2026-10-01T12:30:00Z',
          days_overdue: 2,
          quantity_target: 10,
          quantity_good: 4,
        },
      }),
    );
    expect(view.facts[1]?.value).toBe('Status unavailable');
    expect(view.evidenceLabel).toBe('Limited evidence');
  });
});

describe('presentation labels', () => {
  it.each([
    ['dss.chain_bottlenecks', 'Workflow handoffs'],
    ['dss.approval_bottlenecks', 'Approval response'],
    ['dss.work_order_intervention', 'Production completion'],
    ['dss.receivables_priority', 'Overdue receivables'],
    ['dss.quality_ncr_sla', 'Quality response'],
    ['dss.preventive_maintenance_overdue', 'Preventive maintenance'],
    ['dss.payroll_anomaly_summary', 'Payroll anomaly review'],
    ['dss.training_expiry_summary', 'Training expiry'],
    ['dss.master_hygiene', 'Master data completeness'],
    ['constructor', 'Decision check'],
  ])('labels detector %s', (id, label) => expect(detectorLabel(id)).toBe(label));
  it.each([
    ['critical', 'Critical'],
    ['warning', 'Warning'],
    ['info', 'Information'],
    ['constructor', 'Severity unavailable'],
  ])('labels severity %s', (severity, label) => expect(severityLabel(severity)).toBe(label));
  it.each([
    ['open', 'New'],
    ['in_progress', 'In progress'],
    ['accepted', 'Acknowledged'],
    ['executed', 'Action recorded'],
    ['dismissed', 'Dismissed'],
    ['snoozed', 'Snoozed'],
    ['expired', 'Expired'],
    ['resolved', 'Resolved'],
    ['constructor', 'Status unavailable'],
  ])('labels recommendation status %s', (status, label) =>
    expect(recommendationStatusLabel(status)).toBe(label),
  );
});
