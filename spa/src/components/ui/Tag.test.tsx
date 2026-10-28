import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Tag } from './Tag';

describe('Tag', () => {
  it('keeps category name visible with a deterministic category mark', () => {
    const { rerender } = render(<Tag categoryKey="department-1">Production</Tag>);
    const first = screen.getByText('Production');
    const category = Array.from(first.classList).find((name) => /^border-l-category-\d$/.test(name));
    expect(category).toBeDefined();

    rerender(<Tag categoryKey="department-1">Production</Tag>);
    expect(Array.from(screen.getByText('Production').classList).find((name) => /^border-l-category-\d$/.test(name))).toBe(category);
  });
});
