/**
 * Breadcrumbs — the app's one trail.
 *
 * The bugs these lock down:
 *   • two trails on screen at once (PageHeader used to render a competing one)
 *   • a hash id rendered verbatim as the final crumb
 *   • an all-letter hash id titleized into a plausible-looking non-word
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { Breadcrumbs } from '../Breadcrumbs';
import { PageHeader } from '../PageHeader';
import { useBreadcrumbStore } from '@/stores/breadcrumbStore';

function renderAt(path: string, ui?: React.ReactNode) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Breadcrumbs />
      {ui}
    </MemoryRouter>,
  );
}

describe('Breadcrumbs', () => {
  beforeEach(() => {
    useBreadcrumbStore.setState({ path: null, label: null });
  });

  it('maps the first segment to its module display name', () => {
    renderAt('/mrp/molds');
    expect(screen.getByText('Production Planning')).toBeInTheDocument();
    expect(screen.getByText('Molds')).toBeInTheDocument();
  });

  it('does not render a hash id as the final crumb', () => {
    renderAt('/purchasing/purchase-orders/nB4kQ2');
    expect(screen.queryByText('nB4kQ2')).not.toBeInTheDocument();
    expect(screen.getByText('…')).toBeInTheDocument();
  });

  it('does not titleize a digitless hash id into a fake word', () => {
    // The old check required a digit, so this fell through to titleize()
    // and rendered as "Yrklmq".
    renderAt('/purchasing/purchase-orders/yRkLmQ');
    expect(screen.queryByText('Yrklmq')).not.toBeInTheDocument();
  });

  it('keeps known abbreviations readable rather than treating them as ids', () => {
    renderAt('/quality/ncrs');
    expect(screen.getByText('NCRs')).toBeInTheDocument();
  });

  it('uses the label PageHeader publishes for the current record', () => {
    renderAt(
      '/purchasing/purchase-orders/nB4kQ2',
      <PageHeader title={<span>PO-202604-0015</span>} />,
    );
    // Both the trail and the heading show it; the trail crumb is what matters.
    expect(screen.getAllByText('PO-202604-0015').length).toBeGreaterThan(0);
    expect(screen.queryByText('…')).not.toBeInTheDocument();
  });

  it('renders exactly one navigation landmark even with a PageHeader mounted', () => {
    renderAt('/hr/employees', <PageHeader title="Employees" />);
    expect(screen.getAllByRole('navigation', { name: 'Breadcrumb' })).toHaveLength(1);
  });

  it('ignores the published label when the segment already names itself', () => {
    // /payroll/periods titleizes to "Periods" while the heading reads "Payroll
    // Periods"; taking the override there duplicated the heading into the trail.
    renderAt('/payroll/periods', <PageHeader title="Payroll Periods" />);
    const trail = screen.getByRole('navigation', { name: 'Breadcrumb' });
    expect(trail).toHaveTextContent('Periods');
    expect(trail).not.toHaveTextContent('Payroll Periods');
  });

  it('ignores a label published for a different route', () => {
    useBreadcrumbStore.setState({ path: '/somewhere/else', label: 'Stale' });
    renderAt('/hr/employees');
    expect(screen.queryByText('Stale')).not.toBeInTheDocument();
  });
});
