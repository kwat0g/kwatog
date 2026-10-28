import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Avatar } from './Avatar';

describe('Avatar', () => {
  it('uses initials, an accessible name, and a deterministic tint without a photo', () => {
    const { rerender } = render(<Avatar name="Ada Lovelace" identityKey="employee-7" />);
    const first = screen.getByRole('img', { name: 'Ada Lovelace' });
    expect(first).toHaveTextContent('AL');
    const tint = Array.from(first.classList).find((name) => /^bg-avatar-\d-bg$/.test(name));
    expect(tint).toBeDefined();

    rerender(<Avatar name="Ada Lovelace" identityKey="employee-7" />);
    expect(Array.from(screen.getByRole('img').classList).find((name) => /^bg-avatar-\d-bg$/.test(name))).toBe(tint);
  });

  it('uses photo data without exposing it as a second accessible name', () => {
    render(<Avatar name="Ada Lovelace" src="/ada.jpg" />);
    expect(screen.getByRole('img', { name: 'Ada Lovelace' }).querySelector('img')).toHaveAttribute('alt', '');
  });
});
