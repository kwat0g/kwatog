import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { client } from '@/api/client';
import { CommandPalette } from './CommandPalette';

/** Minimal palette group in the shape `GET /search` returns. */
function group(label: string, itemLabel: string) {
  return {
    group: 'sales_order',
    label,
    type: 'sales_order',
    items: [{ id: '1', label: itemLabel, sublabel: null, status: null, amount: null, url: '/x' }],
  };
}

function renderPalette() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <CommandPalette open onClose={() => {}} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return screen.getByPlaceholderText(/search/i);
}

const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));

describe('CommandPalette search', () => {
  beforeEach(() => vi.restoreAllMocks());

  /*
   * Regression: the search used `setTimeout` + `await client.get` in an effect,
   * whose cleanup cleared the timer but never the in-flight request. A slow
   * response for an earlier term could resolve last and overwrite the newer
   * results — the list then showed records for a query the user had moved past.
   */
  it('never renders results belonging to a superseded query', async () => {
    vi.spyOn(client, 'get').mockImplementation((_url, config) => {
      const q = (config as { params?: { q?: string } })?.params?.q;
      /*
       * The stale term must answer *last* or there is no race to observe.
       * Timeline: "ab" is sent at ~200ms (debounce) and lands at ~700ms;
       * "abcd" is sent at ~450ms and lands immediately. The buggy version
       * then repaints STALE over FRESH at 700ms.
       */
      const delay = q === 'abcd' ? 0 : 500;
      const payload = q === 'abcd' ? group('Orders', 'FRESH-abcd') : group('Orders', 'STALE-ab');
      return new Promise((resolve) =>
        setTimeout(() => resolve({ data: { data: [payload], query: q } }), delay),
      ) as ReturnType<typeof client.get>;
    });

    const input = renderPalette();

    fireEvent.change(input, { target: { value: 'ab' } });
    // Let the debounce fire for "ab" so its slow request is genuinely in flight.
    await wait(250);
    fireEvent.change(input, { target: { value: 'abcd' } });

    expect(await screen.findByText('FRESH-abcd')).toBeInTheDocument();

    // Past the slow response's arrival: it must not overwrite the fresh rows.
    await wait(600);
    expect(screen.queryByText('STALE-ab')).not.toBeInTheDocument();
    expect(screen.getByText('FRESH-abcd')).toBeInTheDocument();
  });

  it('does not query until the term reaches two characters', async () => {
    const get = vi
      .spyOn(client, 'get')
      .mockResolvedValue({ data: { data: [], query: 'a' } } as never);

    const input = renderPalette();
    fireEvent.change(input, { target: { value: 'a' } });
    await wait(300);

    expect(get).not.toHaveBeenCalled();
  });

  it('debounces typing into a single request', async () => {
    const get = vi
      .spyOn(client, 'get')
      .mockResolvedValue({ data: { data: [group('Orders', 'ROW')], query: 'abcd' } } as never);

    const input = renderPalette();
    for (const value of ['a', 'ab', 'abc', 'abcd']) {
      fireEvent.change(input, { target: { value } });
    }

    await waitFor(() => expect(get).toHaveBeenCalledTimes(1));
    expect((get.mock.calls[0][1] as { params: { q: string } }).params.q).toBe('abcd');
  });
});
