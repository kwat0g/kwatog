import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { ProfileDropdown } from './ProfileDropdown';

describe('profile navigation', () => {
  it('exposes self-service approval cover to every signed-in user', () => {
    render(
      <MemoryRouter>
        <ProfileDropdown user={{ name: 'Avery Worker', email: 'avery@example.test' }} />
      </MemoryRouter>,
    );

    fireEvent.click(screen.getByRole('button', { name: /Profile menu/ }));

    expect(screen.getByRole('menuitem', { name: 'Approval cover' })).toHaveAttribute(
      'href',
      '/approvals/delegations',
    );
  });
});
