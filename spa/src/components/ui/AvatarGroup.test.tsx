import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { AvatarGroup } from './AvatarGroup';

describe('AvatarGroup', () => {
  it('names visible people and reports the hidden count', () => {
    render(
      <AvatarGroup
        label="Approvers"
        people={[
          { id: '1', name: 'Ada Lovelace' },
          { id: '2', name: 'Grace Hopper' },
          { id: '3', name: 'Katherine Johnson' },
        ]}
        max={2}
      />,
    );
    expect(screen.getByRole('group', { name: 'Approvers' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Ada Lovelace' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: '1 more person' })).toHaveTextContent('+1');
  });
});
