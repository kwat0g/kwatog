import { fireEvent, render, screen } from '@testing-library/react';
import { Link, MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { useUnsavedChangesGuard } from './useUnsavedChangesGuard';

function DirtyPage({ protectNavigation = true }: { protectNavigation?: boolean }) {
  useUnsavedChangesGuard(true, protectNavigation);
  const navigate = useNavigate();
  return <><Link to="/next">Leave</Link><button onClick={() => navigate('/next')}>Navigate</button></>;
}
afterEach(() => vi.restoreAllMocks());
it('protects links and programmatic navigation in the current navigator', () => {
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
  render(<MemoryRouter initialEntries={['/edit']}><Routes><Route path="/edit" element={<DirtyPage />} /><Route path="/next" element={<p>Next page</p>} /></Routes></MemoryRouter>);
  fireEvent.click(screen.getByRole('link', { name: 'Leave' }));
  expect(confirm).toHaveBeenCalledOnce();
  expect(screen.queryByText('Next page')).not.toBeInTheDocument();
  confirm.mockReturnValue(true);
  fireEvent.click(screen.getByRole('button', { name: 'Navigate' }));
  expect(screen.getByText('Next page')).toBeVisible();
});
it('preserves existing forms without navigation protection opt-in', () => {
  const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
  render(<MemoryRouter initialEntries={['/edit']}><Routes><Route path="/edit" element={<DirtyPage protectNavigation={false} />} /><Route path="/next" element={<p>Next page</p>} /></Routes></MemoryRouter>);
  fireEvent.click(screen.getByRole('link', { name: 'Leave' }));
  expect(confirm).not.toHaveBeenCalled();
  expect(screen.getByText('Next page')).toBeVisible();
});
