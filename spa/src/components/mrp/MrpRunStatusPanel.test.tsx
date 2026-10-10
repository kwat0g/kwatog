import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { MrpRunStatusPanel } from './MrpRunStatusPanel';
import type { MrpRun } from '@/types/mrp-runs';

const automaticRun: MrpRun = {
  id: 'run-1',
  run_at: '2026-08-16T08:00:00+08:00',
  triggered_by: 'automatic',
  triggered_by_label: 'Automatic',
  triggered_by_user: null,
  sales_orders_evaluated: 3,
  shortages_found: 2,
  prs_created: 1,
  prs_updated: 0,
  plans_generated: 3,
  duration_ms: 4200,
  status: 'completed',
  status_label: 'Completed',
  error_message: null,
  summary: {
    trigger_reason: 'inventory_changed',
    scheduling: {
      scheduled: [],
      conflicts: [{ work_order_id: 'wo-1', wo_number: 'WO-001', reasons: ['no_capacity'] }],
    },
  },
  created_at: '2026-08-16T08:00:00+08:00',
};

describe('MrpRunStatusPanel', () => {
  it('shows automatic trigger context and actionable scheduling results', () => {
    render(<MrpRunStatusPanel latest={automaticRun} recent={[automaticRun]} />);

    expect(screen.getByText('Automatic')).toBeInTheDocument();
    expect(screen.getByText('inventory changed')).toBeInTheDocument();
    expect(screen.getByText('1 scheduling conflict')).toBeInTheDocument();
    expect(
      screen.getByText((_, element) => element?.textContent?.trim() === '2 shortages'),
    ).toBeInTheDocument();
  });

  it('shows a failure message when the latest run failed', () => {
    const failedRun: MrpRun = {
      ...automaticRun,
      id: 'run-2',
      status: 'failed',
      status_label: 'Failed',
      error_message: 'Queue worker unavailable.',
    };

    render(<MrpRunStatusPanel latest={failedRun} recent={[failedRun]} />);

    expect(screen.getByText('Queue worker unavailable.')).toBeInTheDocument();
  });
});

it('shows affected order and recovery instructions when a partial run creates no plan', () => {
  render(
    <MrpRunStatusPanel
      latest={{
        ...automaticRun,
        status: 'partial',
        failed_sales_orders: 1,
        plans_generated: 0,
        summary: {
          per_sales_order: [
            {
              so_id: 'order-1',
              so_number: 'SO-202610-0027',
              error: 'RM-001 has no PCS to KG conversion.',
              error_code: 'mrp_business_rule',
              recovery_action: 'Correct the purchase request unit, then rerun MRP.',
            },
          ],
        },
      }}
    />,
  );
  expect(screen.getByText('SO-202610-0027')).toBeVisible();
  expect(screen.getByText('RM-001 has no PCS to KG conversion.')).toBeVisible();
  expect(screen.getByText(/Correct the purchase request unit, then rerun MRP/)).toBeVisible();
  expect(screen.queryByText('No conflicts')).not.toBeInTheDocument();
});

it('keeps an older failed order available after a newer successful run', () => {
  const oldRun: MrpRun = {
    ...automaticRun,
    id: 'older-run',
    status: 'partial',
    summary: {
      per_sales_order: [
        {
          so_id: 'old-order',
          so_number: 'SO-OLDER',
          error: 'Invalid component unit.',
          recovery_action: 'Correct unit, then rerun.',
        },
      ],
    },
  };
  render(<MrpRunStatusPanel latest={{ ...automaticRun, summary: {} }} recent={[oldRun]} />);
  const disclosure = screen.getByText('View failed orders and recovery');
  fireEvent.click(disclosure);
  expect(disclosure.closest('details')).toHaveAttribute('open');
  expect(screen.getByText('SO-OLDER')).toBeInTheDocument();
  expect(screen.getByText(/Correct unit, then rerun/)).toBeInTheDocument();
});
