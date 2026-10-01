import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter, useNavigate } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { Sidebar } from './Sidebar';
import { useKeyboardShortcuts } from '@/hooks/useKeyboardShortcuts';
import { PageActionsProvider } from '@/contexts/PageActionsContext';
import { useSidebarStore } from '@/stores/sidebarStore';

vi.mock('@/hooks/useBadges', () => ({ useBadges: () => ({ getBadge: () => undefined }) }));
vi.mock('@/api/auth', () => ({ authApi: { updatePreferences: vi.fn().mockResolvedValue({}) } }));

function Harness() {
  const navigate = useNavigate();
  return <><button onClick={() => navigate('/production/work-orders')}>Visit production</button><Sidebar roleSlug="system_admin" /></>;
}

beforeEach(() => {
  vi.useFakeTimers();
  useSidebarStore.getState().init(false);
  useSidebarStore.getState().setCollapsed(false);
});
afterEach(() => { cleanup(); vi.useRealTimers(); });

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
  const controls = drawer.querySelectorAll<HTMLElement>('button, a[href], input');
  act(() => controls[controls.length - 1].focus());
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
