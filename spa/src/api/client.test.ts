import axios, { AxiosError, type AxiosAdapter, type AxiosResponse } from 'axios';
import toast from 'react-hot-toast';
import { afterEach, describe, it, expect, vi } from 'vitest';
import { client, unwrappingClient } from '@/api/client';

// Smoke tests for the axios client. The interceptors do real work in
// production (toast, redirects, log capture) and we don't want to dive
// into mocking them here — we just verify the base configuration that
// every request inherits.
describe('api/client', () => {
 const originalAdapter = unwrappingClient.defaults.adapter;

 afterEach(() => {
 unwrappingClient.defaults.adapter = originalAdapter;
 vi.restoreAllMocks();
 });

 it('targets the /api/v1 prefix with credentials', () => {
 expect(client.defaults.baseURL).toBe('/api/v1');
 expect(client.defaults.withCredentials).toBe(true);
 });

 it('sends JSON accept + ajax marker headers', () => {
 expect(client.defaults.headers.Accept).toBe('application/json');
 expect(client.defaults.headers['X-Requested-With']).toBe('XMLHttpRequest');
 });

 it('has a 30s default timeout', () => {
 expect(client.defaults.timeout).toBe(30_000);
 });

 it('unwrappingClient shares the same base configuration', () => {
 expect(unwrappingClient.defaults.baseURL).toBe('/api/v1');
 expect(unwrappingClient.defaults.withCredentials).toBe(true);
 expect(unwrappingClient.defaults.timeout).toBe(30_000);
 });

 it('refreshes the CSRF cookie and replays a stale-session request once', async () => {
 let attempts = 0;
 unwrappingClient.defaults.adapter = (async (config) => {
 attempts += 1;
 if (attempts === 1) {
 const response = { status: 419, statusText: 'Page Expired', data: { message: 'CSRF token mismatch.' }, headers: {}, config } as AxiosResponse;
 throw new AxiosError('Page Expired', 'ERR_BAD_REQUEST', config, undefined, response);
 }
 return { status: 200, statusText: 'OK', data: { data: { id: 'user-1' } }, headers: {}, config } as AxiosResponse;
 }) as AxiosAdapter;
 const csrf = vi.spyOn(axios, 'get').mockResolvedValue({ status: 204 } as AxiosResponse);

 const response = await unwrappingClient.post('/auth/login', { email: 'admin@ogami.ph', password: 'secret' });

 expect(csrf).toHaveBeenCalledOnce();
 expect(attempts).toBe(2);
 expect(response.data).toEqual({ id: 'user-1' });
 });

 it('does not globally toast an error that the calling page will handle', async () => {
 unwrappingClient.defaults.adapter = (async (config) => {
 const response = { status: 500, statusText: 'Server Error', data: { message: 'broken' }, headers: {}, config } as AxiosResponse;
 throw new AxiosError('Server Error', 'ERR_BAD_RESPONSE', config, undefined, response);
 }) as AxiosAdapter;
 const toastSpy = vi.spyOn(toast, 'error');

 await expect(unwrappingClient.post('/some-action')).rejects.toMatchObject({ response: { status: 500 } });

 expect(toastSpy).not.toHaveBeenCalled();
 });
});
