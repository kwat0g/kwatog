import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError, type AxiosResponse } from 'axios';
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

/** An axios rejection the palette can classify — `isAxiosError` must be true. */
function httpError(status: number): AxiosError {
  return new AxiosError('request failed', 'ERR_BAD_RESPONSE', undefined, undefined, {
    status,
    statusText: '',
    data: {},
    headers: {},
    config: {} as never,
  } as AxiosResponse);
}

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

  it('does not query until the trimmed term reaches two characters', async () => {
    const get = vi
      .spyOn(client, 'get')
      .mockResolvedValue({ data: { data: [], query: 'a' } } as never);

    const input = renderPalette();
    fireEvent.change(input, { target: { value: '  a  ' } });
    await wait(300);

    expect(get).not.toHaveBeenCalled();
  });

  it('sends a valid padded term in its normalized form', async () => {
    const get = vi
      .spyOn(client, 'get')
      .mockResolvedValue({ data: { data: [], query: 'abcd' } } as never);

    const input = renderPalette();
    fireEvent.change(input, { target: { value: '  abcd  ' } });

    await waitFor(() => expect(get).toHaveBeenCalledTimes(1));
    expect((get.mock.calls[0][1] as { params: { q: string } }).params.q).toBe('abcd');
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

/*
 * M009-F06. The palette read only `data` and `isFetching`, so every failure
 * rendered as "No results for …" — the one message that is definitely wrong,
 * because nothing was searched. Each status has a different remedy and only
 * two of the three are worth retrying, so they must not collapse into one
 * state.
 */
describe('CommandPalette failure states', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('reports a permission failure rather than an empty result set', async () => {
    vi.spyOn(client, 'get').mockRejectedValue(httpError(403));

    const input = renderPalette();
    fireEvent.change(input, { target: { value: 'abcd' } });

    expect(await screen.findByText(/not available to your account/i)).toBeInTheDocument();
    expect(screen.queryByText(/no results for/i)).not.toBeInTheDocument();
    // A 403 will not resolve by trying again — offering Retry would be a lie.
    expect(screen.queryByRole('button', { name: /retry/i })).not.toBeInTheDocument();
  });

  it('reports throttling as throttling, with a way to try again', async () => {
    vi.spyOn(client, 'get').mockRejectedValue(httpError(429));

    const input = renderPalette();
    fireEvent.change(input, { target: { value: 'abcd' } });

    expect(await screen.findByText(/too many searches/i)).toBeInTheDocument();
    expect(screen.queryByText(/no results for/i)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
  });

  it('does not retry automatically — a retry spends another rate-limit token', async () => {
    const get = vi.spyOn(client, 'get').mockRejectedValue(httpError(429));

    const input = renderPalette();
    fireEvent.change(input, { target: { value: 'abcd' } });
    await screen.findByText(/too many searches/i);

    // The client-wide default is `retry: 1`; this query overrides it to false.
    await wait(400);
    expect(get).toHaveBeenCalledTimes(1);
  });

  it('drops the previous term rows when the next term fails', async () => {
    vi.spyOn(client, 'get').mockImplementation((_url, config) => {
      const q = (config as { params?: { q?: string } })?.params?.q;
      if (q === 'abcd') {
        return Promise.resolve({
          data: { data: [group('Orders', 'GOOD-abcd')], query: q },
        }) as ReturnType<typeof client.get>;
      }
      return Promise.reject(httpError(500)) as ReturnType<typeof client.get>;
    });

    const input = renderPalette();
    fireEvent.change(input, { target: { value: 'abcd' } });
    expect(await screen.findByText('GOOD-abcd')).toBeInTheDocument();

    fireEvent.change(input, { target: { value: 'abcde' } });

    expect(await screen.findByText(/failed on the server/i)).toBeInTheDocument();
    // `placeholderData` keeps the old rows in `data` across the failure. Left
    // alone they would sit under the new term with nothing marking them stale.
    expect(screen.queryByText('GOOD-abcd')).not.toBeInTheDocument();
  });

  it('refetches when the user asks it to', async () => {
    const get = vi.spyOn(client, 'get').mockRejectedValue(httpError(500));

    const input = renderPalette();
    fireEvent.change(input, { target: { value: 'abcd' } });

    const retry = await screen.findByRole('button', { name: /retry/i });
    const before = get.mock.calls.length;
    fireEvent.click(retry);

    await waitFor(() => expect(get.mock.calls.length).toBeGreaterThan(before));
  });
});

/*
 * M009-F07. `aria-modal` moves focus into the dialog; dropping it when the
 * dialog closes strands keyboard and screen-reader users at the top of the
 * document, which is the usual reason a palette appears to "lose" the page.
 */
describe('CommandPalette focus handling', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('gives focus back to whatever opened it', async () => {
    const opener = document.createElement('button');
    opener.textContent = 'Search…';
    document.body.appendChild(opener);
    opener.focus();
    expect(document.activeElement).toBe(opener);

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const tree = (open: boolean) => (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <CommandPalette open={open} onClose={() => {}} />
        </MemoryRouter>
      </QueryClientProvider>
    );

    const { rerender } = render(tree(true));
    await waitFor(() => expect(document.activeElement).toBe(screen.getByLabelText('Search query')));

    rerender(tree(false));
    await waitFor(() => expect(document.activeElement).toBe(opener));

    opener.remove();
  });
});
