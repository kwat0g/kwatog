import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { SidePanel } from './SidePanel';

describe('SidePanel', () => {
  it('reuses the named modal surface and exposes its close control', () => {
    render(
      <SidePanel isOpen onClose={() => undefined} title="Employee preview">
        <p>Record details</p>
      </SidePanel>,
    );
    const dialog = screen.getByRole('dialog', { name: 'Employee preview' });
    expect(dialog.querySelector('[data-presentation]')).toHaveAttribute('data-presentation', 'side');
    expect(screen.getByRole('button', { name: 'Close preview' })).toBeVisible();
    expect(screen.getByText('Record details')).toBeVisible();
  });

  it('closes on Escape and keeps keyboard focus inside', () => {
    const onClose = vi.fn();
    render(
      <SidePanel isOpen onClose={onClose} title="Employee preview">
        <button type="button">Open full record</button>
      </SidePanel>,
    );
    const close = screen.getByRole('button', { name: 'Close preview' });
    const openRecord = screen.getByRole('button', { name: 'Open full record' });

    openRecord.focus();
    fireEvent.keyDown(openRecord, { key: 'Tab' });
    expect(close).toHaveFocus();

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledOnce();
  });
});
