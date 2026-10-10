import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { DevErrorPanel } from './DevErrorPanel';
import { useErrorLogStore } from '@/stores/errorLogStore';

afterEach(() => {
  cleanup();
  useErrorLogStore.getState().clear();
});

it('does not cover page controls when no server errors were captured', () => {
  render(<DevErrorPanel />);
  expect(screen.queryByRole('button', { name: 'Toggle server error log' })).not.toBeInTheDocument();
});

it('makes captured server errors available to developers', () => {
  useErrorLogStore.getState().push({
    method: 'POST',
    url: '/api/v1/test',
    status: 500,
    message: 'Request failed.',
  });
  render(<DevErrorPanel />);
  expect(screen.getByRole('button', { name: 'Toggle server error log' })).toBeInTheDocument();
});
