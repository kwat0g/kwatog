import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { FileInput } from './FileInput';
import { Input } from './Input';
import { Modal } from './Modal';
import { Select } from './Select';
import { Textarea } from './Textarea';

describe('form primitives', () => {
  it('keeps decorative required markers out of accessible field names', () => {
    const { container } = render(
      <>
        <Input label="Full name" required />
        <Select label="Department" required>
          <option value="">Choose one</option>
        </Select>
        <Textarea label="Reason" required />
        <FileInput label="Evidence" required />
      </>,
    );

    expect(screen.getByRole('textbox', { name: 'Full name' })).toBeRequired();
    expect(screen.getByRole('combobox', { name: 'Department' })).toBeRequired();
    expect(screen.getByRole('textbox', { name: 'Reason' })).toBeRequired();
    const evidence = container.querySelector('input[type="file"]');
    expect(evidence).toHaveAccessibleName('Evidence');
    expect(evidence).toBeRequired();
  });

  it('keeps required validation and stable generated labels', () => {
    const { rerender } = render(<Input label="Full name" required />);
    const input = screen.getByLabelText(/^Full name/);
    const id = input.id;

    expect(input).toBeRequired();

    rerender(<Input label="Full name" required />);
    expect(screen.getByLabelText(/^Full name/)).toHaveAttribute('id', id);

    render(
      <>
        <Select label="Department" required>
          <option value="">Choose one</option>
        </Select>
        <Textarea label="Reason" required />
        <FileInput label="Evidence" required error="Choose a file" />
      </>,
    );

    expect(screen.getByLabelText(/^Department/)).toBeRequired();
    expect(screen.getByLabelText(/^Reason/)).toBeRequired();
    expect(screen.getByLabelText(/^Evidence/)).toBeRequired();
    expect(screen.getByRole('alert')).toHaveTextContent('Choose a file');
  });
});

describe('Modal', () => {
  it('closes when its backdrop is clicked', () => {
    const onClose = vi.fn();
    render(
      <Modal isOpen onClose={onClose} title="Example">
        <button type="button">Continue</button>
      </Modal>,
    );

    const dialog = screen.getByRole('dialog', { name: 'Example' });
    const backdrop = dialog.querySelector('[aria-hidden="true"]');
    expect(backdrop).not.toBeNull();

    backdrop?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    expect(onClose).toHaveBeenCalledOnce();
  });
});
