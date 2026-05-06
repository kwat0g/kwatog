/**
 * WS-A.1 — Type-shape tests for the user-invites API client.
 *
 * The point of these tests is not to mock axios round-trips (the backend
 * tests already cover that). It is to lock down the contract the SPA
 * relies on so a backend rename surfaces here at compile time AND a
 * runtime rename of the route surfaces in the URL assertion.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { client } from '@/api/client';
import { userInvitesApi } from './user-invites';

describe('userInvitesApi', () => {
  beforeEach(() => {
    vi.spyOn(client, 'get').mockResolvedValue({ data: { data: [], meta: {}, links: {} } });
    vi.spyOn(client, 'post').mockResolvedValue({ data: { data: { id: 'X' } } });
    vi.spyOn(client, 'delete').mockResolvedValue({ data: null });
  });

  afterEach(() => vi.restoreAllMocks());

  it('list hits /auth/invites with the requested status filter', async () => {
    await userInvitesApi.list({ status: 'pending', page: 1 });
    expect(client.get).toHaveBeenCalledWith(
      '/auth/invites',
      { params: { status: 'pending', page: 1 } },
    );
  });

  it('create posts to /auth/invites and unwraps the resource envelope', async () => {
    const created = await userInvitesApi.create({
      employee_id: 'E_HASH',
      email: 'jane@x.test',
    });
    expect(client.post).toHaveBeenCalledWith('/auth/invites', {
      employee_id: 'E_HASH',
      email: 'jane@x.test',
    });
    expect(created).toEqual({ id: 'X' });
  });

  it('revoke targets DELETE /auth/invites/:id', async () => {
    await userInvitesApi.revoke('I_HASH');
    expect(client.delete).toHaveBeenCalledWith('/auth/invites/I_HASH');
  });

  it('accept posts to the public /auth/invites/accept endpoint', async () => {
    await userInvitesApi.accept({
      token: 'a'.repeat(64),
      name: 'Jane Doe',
      password: 'NewPassword1!',
      password_confirmation: 'NewPassword1!',
    });
    expect(client.post).toHaveBeenCalledWith(
      '/auth/invites/accept',
      expect.objectContaining({
        token: 'a'.repeat(64),
        name: 'Jane Doe',
        password: 'NewPassword1!',
        password_confirmation: 'NewPassword1!',
      }),
    );
  });
});
