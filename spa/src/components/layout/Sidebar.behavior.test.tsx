import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter, useNavigate } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { Sidebar } from './Sidebar';
import { useKeyboardShortcuts } from '@/hooks/useKeyboardShortcuts';
import { PageActionsProvider } from '@/contexts/PageActionsContext';
import { useSidebarStore } from '@/stores/sidebarStore';

const badgeMock = vi.hoisted(() => ({
  getBadge: vi.fn(
    (_key?: string): { count: number; severity: 'warning' | 'danger' | 'neutral'; label: string; description: string } | undefined => undefined,
  ),
}));
vi.mock('@/hooks/useBadges', () => ({ useBadges: () => ({ getBadge: badgeMock.getBadge }) }));
vi.mock('@/api/auth', () => ({ authApi: { updatePreferences: vi.fn().mockResolvedValue({}) } }));
import { authApi } from '@/api/auth';

function Harness() {
  const navigate = useNavigate();
  return <><button onClick={() => navigate('/production/work-orders')}>Visit production</button><Sidebar roleSlug="system_admin" /></>;
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.clearAllMocks();
  badgeMock.getBadge.mockReturnValue(undefined);
  useSidebarStore.getState().init(false);
  useSidebarStore.getState().setCollapsed(false);
});

it('persists the expand all preference and restores it after initialization', () => {
  vi.stubGlobal('innerWidth', 1440);
  render(<MemoryRouter initialEntries={['/mrp/plans']}><Sidebar roleSlug="system_admin" /></MemoryRouter>);
  fireEvent.click(screen.getByRole('button', { name: 'Expand all' }));
  expect(useSidebarStore.getState().expandAll).toBe(true);
  expect(authApi.updatePreferences).toHaveBeenCalledWith({ sidebar_expand_all: true });

  cleanup();
  useSidebarStore.getState().init(false, true, true);
  render(<MemoryRouter initialEntries={['/dashboard']}><Sidebar roleSlug="system_admin" /></MemoryRouter>);
  expect(screen.getByRole('button', { name: 'Collapse all' })).toBeInTheDocument();
  screen.getAllByRole('button').filter((button) => button.hasAttribute('aria-expanded'))
    .forEach((button) => expect(button).toHaveAttribute('aria-expanded', 'true'));
});
afterEach(() => { cleanup(); vi.useRealTimers(); });

it('opens the awaiting-approval filter when purchase requests has a positive badge', () => {
  badgeMock.getBadge.mockImplementation((key) =>
    key === 'purchase_requests'
      ? {
          count: 3,
          severity: 'warning',
          label: 'Purchase Requests',
          description: 'Purchase requests awaiting approval',
        }
      : undefined,
  );
  render(
    <MemoryRouter initialEntries={['/purchasing/purchase-requests']}>
      <Sidebar roleSlug="system_admin" />
    </MemoryRouter>,
  );
  expect(screen.getByRole('link', { name: /Purchase Requests/ })).toHaveAttribute(
    'href',
    '/purchasing/purchase-requests?signal=awaiting_my_approval',
  );
});

it('opens the pending-QC filter when GRN has a positive badge', () => {
  badgeMock.getBadge.mockImplementation((key) =>
    key === 'pending_grn'
      ? {
          count: 2,
          severity: 'warning',
          label: 'Receiving (GRN)',
          description: 'Goods receipts awaiting QC',
        }
      : undefined,
  );
  render(
    <MemoryRouter initialEntries={['/inventory/grn']}>
      <Sidebar roleSlug="warehouse_staff" />
    </MemoryRouter>,
  );
  expect(screen.getByRole('link', { name: /Goods Receipts \(GRN\)/ })).toHaveAttribute(
    'href',
    '/inventory/grn?status=pending_qc',
  );
});

it('opens overdue bills when the AP badge is positive', () => {
  badgeMock.getBadge.mockImplementation((key) => {
    if (key === 'overdue_bills') {
      return {
        count: 2,
        severity: 'warning',
        label: 'Overdue',
        description: 'Overdue bills payable',
      };
    }
    if (key === 'unpaid_bills') {
      return {
        count: 5,
        severity: 'warning',
        label: 'Unpaid',
        description: 'Open bills with an unpaid balance',
      };
    }
    return undefined;
  });
  render(
    <MemoryRouter initialEntries={['/accounting/bills']}>
      <Sidebar roleSlug="finance_officer" />
    </MemoryRouter>,
  );
  const financeSection = screen.getByRole('button', { name: /^Finance/ });
  if (financeSection.getAttribute('aria-expanded') === 'false') fireEvent.click(financeSection);
  expect(screen.getByRole('link', { name: /Accounts Payable Bills/ })).toHaveAttribute(
    'href',
    '/accounting/bills?overdue=1',
  );
  fireEvent.click(screen.getByRole('button', { name: /View all Accounts Payable Bills signals/ }));
  expect(screen.getByRole('link', { name: 'Overdue bills payable: 2' })).toHaveAttribute(
    'href',
    '/accounting/bills?overdue=1',
  );
  expect(screen.getByRole('link', { name: 'Open bills with an unpaid balance: 5' })).toHaveAttribute(
    'href',
    '/accounting/bills?signal=unpaid',
  );
  const signalButton = screen.getByRole('button', { name: /View all Accounts Payable Bills signals/ });
  fireEvent.mouseEnter(signalButton);
  expect(screen.getByRole('link', { name: 'Overdue bills payable: 2' })).toBeVisible();
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(screen.queryByRole('dialog', { name: 'Accounts Payable Bills signals' })).not.toBeInTheDocument();
  fireEvent.blur(signalButton);
  fireEvent.focus(signalButton);
  expect(screen.getByRole('dialog', { name: 'Accounts Payable Bills signals' })).toBeVisible();
  fireEvent.keyDown(document, { key: 'Escape' });
  act(() => useSidebarStore.getState().setCollapsed(true));
  fireEvent.click(signalButton);
  expect(screen.getByRole('link', { name: 'Open bills with an unpaid balance: 5' })).toBeVisible();
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(signalButton).toHaveFocus();
});

it('opens the to-inspect queue when inspections has a positive badge', () => {
  badgeMock.getBadge.mockImplementation((key) =>
    key === 'pending_inspections'
      ? {
          count: 2,
          severity: 'warning',
          label: 'Inspections',
          description: 'Inspections awaiting completion',
        }
      : undefined,
  );
  render(
    <MemoryRouter initialEntries={['/quality/inspections']}>
      <Sidebar roleSlug="qc_inspector" />
    </MemoryRouter>,
  );
  expect(screen.getByRole('link', { name: /Quality Inspections/ })).toHaveAttribute(
    'href',
    '/quality/inspections?work_queue=to_inspect',
  );
});

it('opens the assigned complaint queue when complaints has a positive badge', () => {
  badgeMock.getBadge.mockImplementation((key) =>
    key === 'open_complaints'
      ? {
          count: 1,
          severity: 'warning',
          label: 'Complaints',
          description: 'Open complaints assigned to you',
        }
      : undefined,
  );
  render(
    <MemoryRouter initialEntries={['/crm/complaints']}>
      <Sidebar roleSlug="customer_service_officer" />
    </MemoryRouter>,
  );
  expect(screen.getByRole('link', { name: /Customer Complaints/ })).toHaveAttribute(
    'href',
    '/crm/complaints?signal=open',
  );
});

it('keeps the narrow-screen sidebar rail collapsed while restoring expand all separately', () => {
  vi.stubGlobal('innerWidth', 1024);
  useSidebarStore.getState().init(false, true, true);
  expect(useSidebarStore.getState().collapsed).toBe(true);
  expect(useSidebarStore.getState().expandAll).toBe(true);
  vi.unstubAllGlobals();
});

it('opens the actionable pending-transfer list when transfers have a positive badge', () => {
  badgeMock.getBadge.mockImplementation((key) =>
    key === 'pending_transfers'
      ? {
          count: 2,
          severity: 'warning',
          label: 'Budget transfers',
          description: 'Budget transfers awaiting approval',
        }
      : undefined,
  );
  render(
    <MemoryRouter initialEntries={['/budgeting/transfers']}>
      <Sidebar roleSlug="vice_president" />
    </MemoryRouter>,
  );
  expect(screen.getByRole('link', { name: /Budget Transfers/ })).toHaveAttribute(
    'href',
    '/budgeting/transfers?status=pending&actionable=1',
  );
});

it('opens budgets filtered to approvals waiting on the current user', () => {
  badgeMock.getBadge.mockImplementation((key) =>
    key === 'pending_budgets'
      ? {
          count: 2,
          severity: 'warning',
          label: 'Budgets',
          description: 'Budgets awaiting your approval',
        }
      : undefined,
  );
  render(
    <MemoryRouter initialEntries={['/budgeting']}>
      <Sidebar roleSlug="vice_president" />
    </MemoryRouter>,
  );
  expect(screen.getByRole('link', { name: /Budgeting/ })).toHaveAttribute(
    'href',
    '/budgeting?signal=awaiting_my_approval',
  );
});

it('expands the active module, expires unvisited peeks, and keeps visited modules open', () => {
  render(<MemoryRouter initialEntries={['/mrp/plans']}><Harness /></MemoryRouter>);
  expect(screen.getByRole('button', { name: 'Production Planning (MRP)' })).toHaveAttribute('aria-expanded', 'true');
  const production = screen.getByRole('button', { name: /^Production$/ });
  expect(production).toHaveAttribute('aria-expanded', 'false');
  fireEvent.click(production);
  act(() => vi.advanceTimersByTime(4999));
  expect(production).toHaveAttribute('aria-expanded', 'true');
  act(() => vi.advanceTimersByTime(1));
  expect(production).toHaveAttribute('aria-expanded', 'false');
  fireEvent.click(screen.getByText('Visit production'));
  act(() => vi.advanceTimersByTime(5000));
  expect(production).toHaveAttribute('aria-expanded', 'true');
  expect(screen.getByRole('button', { name: 'Production Planning (MRP)' })).toHaveAttribute('aria-expanded', 'false');
  fireEvent.click(screen.getByRole('button', { name: 'Expand all' }));
  fireEvent.click(screen.getByRole('button', { name: 'Collapse all' }));
  expect(production).toHaveAttribute('aria-expanded', 'true');
  expect(screen.getByRole('button', { name: 'Production Planning (MRP)' })).toHaveAttribute('aria-expanded', 'false');
  expect(screen.queryByRole('checkbox', { name: 'Peek sidebar' })).not.toBeInTheDocument();
});

it('keeps a filtered sidebar destination active', () => {
  render(<MemoryRouter initialEntries={['/crm/inquiries?status=new']}><Sidebar roleSlug="system_admin" /></MemoryRouter>);
  const link = screen.getByRole('link', { name: 'Customer Inquiries' });
  expect(link).toHaveAttribute('href', '/crm/inquiries?status=new');
  expect(link).toHaveAttribute('aria-current', 'page');
});

it('widens the rail after 150ms and reveals labels on keyboard focus', () => {
  useSidebarStore.getState().setCollapsed(true);
  render(<MemoryRouter><Sidebar roleSlug="system_admin" /></MemoryRouter>);
  const rail = screen.getByLabelText('Sidebar');
  expect(rail).toHaveClass('w-14');
  fireEvent.mouseEnter(rail);
  act(() => vi.advanceTimersByTime(149));
  expect(rail).toHaveClass('w-14');
  act(() => vi.advanceTimersByTime(1));
  expect(rail).toHaveClass('w-60');
  fireEvent.mouseLeave(rail);
  expect(rail).toHaveClass('w-14');
  fireEvent.focus(within(rail).getByRole('link', { name: 'Dashboard' }));
  expect(rail).toHaveClass('w-60');
});

it('traps mobile focus and closes the drawer on Escape', () => {
  render(<MemoryRouter><Sidebar roleSlug="system_admin" /></MemoryRouter>);
  act(() => useSidebarStore.getState().setMobileOpen(true));
  const drawer = screen.getByRole('dialog', { name: 'Menu' });
  const controls = Array.from(drawer.querySelectorAll<HTMLElement>(
    'button:not([disabled]), a[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]',
  )).filter((control) =>
    control.tabIndex >= 0
    && !control.matches(':disabled')
    && !control.closest('[hidden], [aria-hidden="true"]'),
  );
  act(() => controls.at(-1)?.focus());
  fireEvent.keyDown(document, { key: 'Tab' });
  expect(controls[0]).toHaveFocus();
  fireEvent.keyDown(document, { key: 'Escape' });
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('honors the peek preference and cancels brief hovers', () => {
  useSidebarStore.getState().setCollapsed(true);
  render(<MemoryRouter><Sidebar roleSlug="system_admin" /></MemoryRouter>);
  const rail = screen.getByLabelText('Sidebar');
  fireEvent.mouseEnter(rail);
  fireEvent.mouseLeave(rail);
  act(() => vi.advanceTimersByTime(150));
  expect(rail).toHaveClass('w-14');
  act(() => useSidebarStore.getState().setPeek(false));
  fireEvent.mouseEnter(rail);
  act(() => vi.advanceTimersByTime(150));
  expect(rail).toHaveClass('w-14');
});

it('toggles with Ctrl+. on desktop and mobile, while ignoring typing and repeats', () => {
  function Shortcuts() { useKeyboardShortcuts(); return <input aria-label="Edit" />; }
  render(<MemoryRouter><PageActionsProvider><Shortcuts /></PageActionsProvider></MemoryRouter>);
  vi.stubGlobal('innerWidth', 1440);
  fireEvent.keyDown(document, { key: '.', ctrlKey: true });
  expect(useSidebarStore.getState().collapsed).toBe(true);
  fireEvent.keyDown(document, { key: '.', ctrlKey: true, repeat: true });
  fireEvent.keyDown(screen.getByLabelText('Edit'), { key: '.', ctrlKey: true });
  expect(useSidebarStore.getState().collapsed).toBe(true);
  vi.stubGlobal('innerWidth', 390);
  fireEvent.keyDown(document, { key: '.', ctrlKey: true });
  expect(useSidebarStore.getState().mobileOpen).toBe(true);
  vi.unstubAllGlobals();
});

it('expands every visible module without peek expiry and keeps the active module on collapse', () => {
  render(<MemoryRouter initialEntries={['/mrp/plans']}><Sidebar roleSlug="system_admin" /></MemoryRouter>);
  const sidebar = screen.getByLabelText('Sidebar');
  expect(within(within(sidebar).getByRole('group', { name: 'Module controls' })).getAllByRole('button')).toHaveLength(1);
  expect(within(sidebar).queryByRole('button', { name: 'Collapse all' })).not.toBeInTheDocument();
  fireEvent.click(within(sidebar).getByRole('button', { name: /^Production$/ }));
  fireEvent.click(within(sidebar).getByRole('button', { name: 'Expand all' }));
  act(() => vi.advanceTimersByTime(5000));
  const headers = sidebar.querySelectorAll('button[aria-expanded]');
  expect(headers.length).toBeGreaterThan(1);
  headers.forEach((header) => expect(header).toHaveAttribute('aria-expanded', 'true'));
  expect(within(sidebar).queryByRole('button', { name: 'Expand all' })).not.toBeInTheDocument();
  fireEvent.click(within(sidebar).getByRole('button', { name: 'Collapse all' }));
  expect(within(sidebar).getByRole('button', { name: 'Production Planning (MRP)' })).toHaveAttribute('aria-expanded', 'true');
  expect(within(sidebar).getByRole('button', { name: /^Production$/ })).toHaveAttribute('aria-expanded', 'false');
  act(() => useSidebarStore.getState().setMobileOpen(true));
  const drawer = screen.getByRole('dialog', { name: 'Menu' });
  fireEvent.click(within(drawer).getByRole('button', { name: 'Expand all' }));
  expect(within(drawer).getByRole('button', { name: /^Production$/ })).toHaveAttribute('aria-expanded', 'true');
});

it('focuses Finance navigation on accounting and approval work with one active Dashboard link', () => {
  const permissions = new Set([
    'dashboard.accounting.view', 'dashboard.action_center.view', 'approvals.board.view',
    'budgeting.view', 'accounting.invoices.view', 'accounting.bills.view',
    'accounting.vendors.view', 'accounting.statements.view', 'payroll.periods.view', 'payroll.periods.approve', 'assets.view',
    'purchasing.view', 'purchasing.pr.approve', 'purchasing.po.approve', 'purchasing.rfq.view', 'hr.profile_updates.finance_review',
    'inventory.adjust.approve', 'accounting.customers.view', 'return_management.view',
    'supply_chain.deliveries.view', 'b2b.portal_access.view', 'dashboard.chain_recovery.view',
  ]);
  const features = new Set(['accounting', 'budgeting', 'payroll', 'assets', 'purchasing', 'inventory', 'crm', 'return_management', 'supply_chain', 'hr']);
  render(<MemoryRouter initialEntries={['/dashboard/finance']}><Sidebar roleSlug="finance_officer" permissions={permissions} features={features} /></MemoryRouter>);
  const sidebar = screen.getByLabelText('Sidebar');
  expect(within(sidebar).getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page');
  const finance = within(sidebar).getByRole('button', { name: 'Finance' });
  expect(finance).toHaveAttribute('aria-expanded', 'false');
  expect(finance).toBeEnabled();
  fireEvent.click(finance);
  act(() => vi.advanceTimersByTime(5000));
  expect(finance).toHaveAttribute('aria-expanded', 'false');
  expect(within(sidebar).queryByRole('link', { name: 'Finance Dashboard', hidden: true })).not.toBeInTheDocument();
  expect(Array.from(sidebar.querySelectorAll('[data-module]'), (module) => module.getAttribute('data-module'))).toEqual(['Finance', 'Procurement', 'Payroll', 'Assets']);
  expect(within(sidebar).getByRole('link', { name: 'Approval Queue' })).toBeVisible();
  fireEvent.click(within(sidebar).getByRole('button', { name: 'Expand all' }));
  expect(within(sidebar).getByRole('link', { name: 'Payroll Processing' })).toBeVisible();
  expect(within(sidebar).getByRole('link', { name: 'Bank Account Changes' })).toBeVisible();
  expect(within(sidebar).getByRole('link', { name: 'Accounts Receivable Invoices' })).toBeVisible();
  expect(within(sidebar).getByRole('link', { name: 'Fixed Assets' })).toBeVisible();
  expect(within(sidebar).getByRole('link', { name: 'Stock Adjustments' })).toBeVisible();
  expect(within(sidebar).getByRole('link', { name: 'Returns (RMA)' })).toBeVisible();
  expect(within(sidebar).getByRole('link', { name: 'Purchase Requests' })).toBeVisible();
  expect(within(sidebar).getByRole('link', { name: 'Purchase Orders' })).toBeVisible();
  expect(within(sidebar).getByRole('link', { name: 'Supplier RFQs' })).toBeVisible();
  expect(within(sidebar).queryByRole('link', { name: 'Procurement Chain', hidden: true })).not.toBeInTheDocument();
  expect(within(sidebar).queryByRole('link', { name: 'Approved Suppliers', hidden: true })).not.toBeInTheDocument();
  fireEvent.click(within(sidebar).getByRole('button', { name: 'Collapse all' }));
  fireEvent.click(finance);
  fireEvent.click(within(sidebar).getByRole('link', { name: 'Accounts Receivable Invoices' }));
  expect(finance).toHaveAttribute('aria-expanded', 'true');
  fireEvent.click(within(sidebar).getByRole('link', { name: 'Dashboard' }));
  expect(finance).toHaveAttribute('aria-expanded', 'false');
  act(() => useSidebarStore.getState().setMobileOpen(true));
  const drawer = screen.getByRole('dialog', { name: 'Menu' });
  expect(within(drawer).getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page');
  expect(Array.from(drawer.querySelectorAll('[data-module]'), (module) => module.getAttribute('data-module'))).toEqual(['Finance', 'Procurement', 'Payroll', 'Assets']);
});

it.each([
  ['system_admin', '/dashboard/admin'],
  ['vice_president', '/dashboard/plant-manager'],
  ['hr_officer', '/dashboard/hr'],
  ['finance_officer', '/dashboard/finance'],
  ['production_manager', '/dashboard/plant-manager'],
  ['ppc_head', '/dashboard/ppc'],
  ['purchasing_officer', '/dashboard/purchasing'],
  ['warehouse_staff', '/dashboard/warehouse'],
  ['qc_inspector', '/dashboard/quality'],
  ['maintenance_tech', '/dashboard/default'],
  ['impex_officer', '/dashboard/default'],
  ['department_head', '/dashboard/default'],
  ['sales_officer', '/dashboard/default'],
  ['customer_service_officer', '/dashboard/default'],
  ['driver', '/dashboard/default'],
  ['employee', '/dashboard/default'],
])('%s uses shared Dashboard and expires inactive module peeks', (role, path) => {
  render(<MemoryRouter initialEntries={[path]}><Sidebar roleSlug={role} /></MemoryRouter>);
  const sidebar = screen.getByLabelText('Sidebar');
  expect(within(sidebar).getByRole('link', { name: /^Dashboard$/ })).toHaveAttribute('aria-current', 'page');
  expect(sidebar.querySelectorAll('a[href="/dashboard"]')).toHaveLength(1);
  const header = sidebar.querySelector<HTMLButtonElement>('[data-module]:not([data-module="Overview"]) button[aria-expanded]');
  expect(header).not.toBeNull();
  expect(header).toHaveAttribute('aria-expanded', 'false');
  fireEvent.click(header!);
  expect(header).toHaveAttribute('aria-expanded', 'true');
  act(() => vi.advanceTimersByTime(5000));
  expect(header).toHaveAttribute('aria-expanded', 'false');
});
