import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import DecisionEvidence from './DecisionEvidence';
import { recommendationFixture } from './test-fixtures';

describe('DecisionEvidence', () => {
  it('renders readable recorded facts and sample document evidence', () => {
    render(<DecisionEvidence recommendation={recommendationFixture()} />);

    const region = screen.getByRole('region', { name: 'Recorded evidence' });
    expect(within(region).getByRole('heading', { name: 'Recorded evidence' })).toBeVisible();
    expect(within(region).getByText('Evidence supplied')).toBeVisible();
    expect(
      within(region).queryByText(/Confirmed delivery awaiting customer invoice/),
    ).not.toBeInTheDocument();
    expect(within(region).queryByText(/workflow handoff/)).not.toBeInTheDocument();
    expect(within(region).getByText('Affected documents')).toBeVisible();
    expect(within(region).getAllByText('336.5')).toHaveLength(2);

    const table = within(region).getByRole('table', { name: 'Sample affected documents' });
    expect(within(table).getByRole('columnheader', { name: 'Document' })).toBeVisible();
    expect(within(table).getByRole('cell', { name: 'DR-202610-0002' })).toBeVisible();
    expect(within(table).getByRole('cell', { name: 'Confirmed' })).toBeVisible();
    expect(within(table).getByRole('cell', { name: '336.5' })).toBeVisible();
    expect(region.textContent).not.toContain('ChainBottleneckService');
    expect(region.textContent).not.toContain('rationale');
    expect(region.textContent).not.toContain('100%');
  });

  it('renders source strings as text, never markup', () => {
    const source = '<img src=x onerror=alert(1)>';
    render(
      <DecisionEvidence
        recommendation={recommendationFixture({
          rationale: {
            rule: 'Confirmed delivery awaiting customer invoice',
            stuck_count: 1,
            threshold_hours: 4,
            oldest_hours_stuck: 336.5,
            source,
            sample_documents: [{ document: source, status: 'confirmed', hours_stuck: 336.5 }],
          },
        })}
      />,
    );

    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    expect(screen.getByText(source)).toBeVisible();
  });

  it('shows a useful empty state when evidence is unavailable', () => {
    render(
      <DecisionEvidence
        recommendation={recommendationFixture({ type: 'unknown_type', rationale: {} })}
      />,
    );

    const region = screen.getByRole('region', { name: 'Recorded evidence' });
    expect(within(region).getByText('Evidence unavailable')).toBeVisible();
    expect(within(region).queryByText('Review business recommendation')).not.toBeInTheDocument();
    expect(
      within(region).queryByText(/related business workflow for details/),
    ).not.toBeInTheDocument();
    expect(within(region).queryByRole('table')).not.toBeInTheDocument();
    expect(within(region).queryByRole('definition')).not.toBeInTheDocument();
    expect(region.textContent).not.toContain('unknown_type');
  });
});
