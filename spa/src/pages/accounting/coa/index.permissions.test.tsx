// M025 / F-004 + F-007 — the four legitimate COA permission combinations.
//
// `accounting.coa.manage` (metadata) and `accounting.coa.deactivate` (status)
// are independent grants on the API. A role holding only one of them must still
// get exactly the actions it is entitled to, and none of the other's — which is
// what regressed before: view+deactivate had no UI action at all, and
// manage-without-status was routed into a form whose required status control was
// disabled.
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { TreeRow } from './index';
import type { Account } from '@/types/accounting';

const account: Account = {
  id: 'yR3kLm',
  code: '1010',
  name: 'Cash on Hand',
  type: 'asset',
  type_label: 'Asset',
  normal_balance: 'debit',
  normal_balance_label: 'Debit',
  parent_id: null,
  is_active: true,
  description: null,
  current_balance: '1000.00',
  total_debit: '1000.00',
};

function renderRow(
  grants: { canManage: boolean; canChangeStatus: boolean },
  overrides: Partial<Account> = {},
): { onStatusChange: ReturnType<typeof vi.fn> } {
  const onStatusChange = vi.fn();
  render(
    <MemoryRouter>
      <TreeRow
        node={{ ...account, ...overrides }}
        depth={0}
        expanded={new Set<string>()}
        onToggle={vi.fn()}
        canManage={grants.canManage}
        canChangeStatus={grants.canChangeStatus}
        onStatusChange={onStatusChange}
        statusPending={false}
      />
    </MemoryRouter>,
  );

  return { onStatusChange };
}

const editLink = () => screen.queryByRole('link', { name: 'Edit' });
const statusButton = (label: string) => screen.queryByRole('button', { name: label });

describe('COA tree row honours the two independent grants', () => {
  it('view-only offers neither metadata nor status actions', () => {
    renderRow({ canManage: false, canChangeStatus: false });

    expect(editLink()).toBeNull();
    expect(statusButton('Deactivate 1010')).toBeNull();
    expect(statusButton('Activate 1010')).toBeNull();
    // The ledger link is a read action, so it stays.
    expect(screen.getByRole('link', { name: 'Cash on Hand' })).toBeTruthy();
  });

  it('manage-only can edit metadata but gets no status control', () => {
    renderRow({ canManage: true, canChangeStatus: false });

    expect(editLink()?.getAttribute('href')).toBe('/accounting/coa/yR3kLm/edit');
    expect(statusButton('Deactivate 1010')).toBeNull();
  });

  it('deactivate-only gets a status action and no edit link', () => {
    renderRow({ canManage: false, canChangeStatus: true });

    expect(editLink()).toBeNull();
    expect(statusButton('Deactivate 1010')).toBeTruthy();
  });

  it('full access gets both', () => {
    renderRow({ canManage: true, canChangeStatus: true });

    expect(editLink()).toBeTruthy();
    expect(statusButton('Deactivate 1010')).toBeTruthy();
  });

  it('offers Activate, and flags the row, when the account is inactive', () => {
    renderRow({ canManage: false, canChangeStatus: true }, { is_active: false });

    expect(statusButton('Activate 1010')).toBeTruthy();
    expect(statusButton('Deactivate 1010')).toBeNull();
    expect(screen.getByText('inactive')).toBeTruthy();
  });

  it('routes the status action through the confirmation handler, not a direct mutation', () => {
    const { onStatusChange } = renderRow({ canManage: false, canChangeStatus: true });

    screen.getByRole('button', { name: 'Deactivate 1010' }).click();

    expect(onStatusChange).toHaveBeenCalledTimes(1);
    expect(onStatusChange.mock.calls[0][0].code).toBe('1010');
  });
});
