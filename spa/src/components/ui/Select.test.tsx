import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Select } from './Select';

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

describe('searchable Select compatibility', () => {
  it('keeps React Hook Form resets, change handlers, and submitted record IDs', async () => {
    const submit = vi.fn();
    const changed = vi.fn();
    function Form() {
      const { register, handleSubmit, reset } = useForm({
        defaultValues: { customer_id: 'customer-2' },
      });
      return (
        <form onSubmit={handleSubmit(submit)}>
          <Select
            searchable
            label="Customer"
            required
            {...register('customer_id', { onChange: changed })}
          >
            <option value="">Select customer</option>
            <option value="customer-1">Acme</option>
            <option value="customer-2">Toyota</option>
          </Select>
          <button type="button" onClick={() => reset({ customer_id: 'customer-1' })}>
            Reset
          </button>
          <button type="submit">Save</button>
        </form>
      );
    }
    render(<Form />);
    expect(screen.getByRole('combobox', { name: 'Customer' })).toHaveValue('Toyota');
    fireEvent.click(screen.getByRole('button', { name: 'Reset' }));
    expect(screen.getByRole('combobox', { name: 'Customer' })).toHaveValue('Acme');
    fireEvent.focus(screen.getByRole('combobox', { name: 'Customer' }));
    fireEvent.click(screen.getByRole('option', { name: 'Toyota' }));
    expect(changed).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() =>
      expect(submit).toHaveBeenCalledWith({ customer_id: 'customer-2' }, expect.anything()),
    );
  });

  it('debounces search, retains selected records outside results, and cancels abandoned searches', async () => {
    vi.useFakeTimers();
    const searched = vi.fn();
    function Form({ limited = false }: { limited?: boolean }) {
      const [value, setValue] = useState('customer-2');
      return (
        <Select
          searchable
          label="Customer"
          value={value}
          onChange={(event) => setValue(event.target.value)}
          onSearch={searched}
        >
          <option value="">Choose customer</option>
          <option value="customer-1">Acme</option>
          {!limited && <option value="customer-2">Toyota</option>}
        </Select>
      );
    }
    const view = render(<Form />);
    const field = screen.getByRole('combobox', { name: 'Customer' });
    fireEvent.focus(field);
    fireEvent.change(field, { target: { value: 'A' } });
    act(() => vi.advanceTimersByTime(150));
    fireEvent.change(field, { target: { value: 'Ac' } });
    act(() => vi.advanceTimersByTime(299));
    expect(searched).not.toHaveBeenCalled();
    act(() => vi.advanceTimersByTime(1));
    expect(searched).toHaveBeenCalledExactlyOnceWith('Ac');
    view.rerender(<Form limited />);
    fireEvent.keyDown(field, { key: 'Escape' });
    expect(field).toHaveValue('Toyota');
    fireEvent.focus(field);
    fireEvent.change(field, { target: { value: 'Acme' } });
    fireEvent.blur(field);
    act(() => vi.advanceTimersByTime(300));
    expect(searched).toHaveBeenCalledTimes(1);
  });

  it('starts searching when a callback is enabled while the open query is unchanged', () => {
    vi.useFakeTimers();
    const searched = vi.fn();
    const view = render(
      <Select searchable label="Customer">
        <option value="">Choose customer</option>
        <option value="customer-1">Acme</option>
      </Select>,
    );
    const field = screen.getByRole('combobox', { name: 'Customer' });
    fireEvent.focus(field);
    view.rerender(
      <Select searchable label="Customer" onSearch={searched}>
        <option value="">Choose customer</option>
        <option value="customer-1">Acme</option>
      </Select>,
    );
    act(() => vi.advanceTimersByTime(300));
    expect(searched).toHaveBeenCalledExactlyOnceWith('');
  });

  it('keeps fixed-choice dropdowns native and skips disabled records with keyboard selection', () => {
    render(
      <>
        <Select label="Status">
          <option>Active</option>
          <option>Inactive</option>
        </Select>
        <Select searchable label="Product">
          <option value="">Choose product</option>
          <option value="p-1" disabled>
            Blocked
          </option>
          <option value="p-2">Available</option>
        </Select>
      </>,
    );
    expect(screen.getByRole('combobox', { name: 'Status' }).tagName).toBe('SELECT');
    const product = screen.getByRole('combobox', { name: 'Product' });
    fireEvent.focus(product);
    fireEvent.change(product, { target: { value: 'a' } });
    fireEvent.keyDown(product, { key: 'ArrowDown' });
    fireEvent.keyDown(product, { key: 'Enter' });
    expect(product).toHaveValue('Available');
  });

  it('clears dependent choices when their available records change and preserves blur validation', async () => {
    function Form() {
      const {
        register,
        watch,
        formState: { errors },
      } = useForm({
        mode: 'onBlur',
        defaultValues: { department: 'one', employee: '' },
      });
      return (
        <>
          <Select searchable label="Department" {...register('department')}>
            <option value="one">First department</option>
            <option value="two">Second department</option>
          </Select>
          <Select
            searchable
            label="Employee"
            error={errors.employee?.message}
            {...register('employee', { required: 'Choose an employee.' })}
          >
            <option value="">Select employee</option>
            {watch('department') === 'one' ? (
              <option value="alice">Alice</option>
            ) : (
              <option value="bob">Bob</option>
            )}
          </Select>
        </>
      );
    }
    render(<Form />);
    const employee = screen.getByRole('combobox', { name: 'Employee' });
    fireEvent.focus(employee);
    fireEvent.blur(employee);
    expect(await screen.findByText('Choose an employee.')).toBeVisible();
    fireEvent.focus(employee);
    fireEvent.click(screen.getByRole('option', { name: 'Alice' }));
    fireEvent.focus(screen.getByRole('combobox', { name: 'Department' }));
    fireEvent.click(screen.getByRole('option', { name: 'Second department' }));
    expect(employee).toHaveValue('Select employee');
  });

  it('preserves a controlled replacement when the previous selected option disappears', () => {
    const changed = vi.fn();
    const view = render(
      <Select searchable label="Employee" value="alice" onChange={changed}>
        <option value="">Select employee</option>
        <option value="alice">Alice</option>
      </Select>,
    );
    view.rerender(
      <Select searchable label="Employee" value="bob" onChange={changed}>
        <option value="">Select employee</option>
        <option value="bob">Bob</option>
      </Select>,
    );
    expect(screen.getByRole('combobox', { name: 'Employee' })).toHaveValue('Bob');
    expect(changed).not.toHaveBeenCalled();
  });
});
