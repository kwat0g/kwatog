import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { client } from './client';

function apiPath(url: string): string {
 return url.startsWith('/api/v1') ? url.slice('/api/v1'.length) || '/' : url;
}

function filenameFromDisposition(header: string | undefined): string | undefined {
 if (!header) return undefined;
 const utf8 = header.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
 if (utf8) return decodeURIComponent(utf8);
 return header.match(/filename="?([^";]+)"?/i)?.[1];
}

async function blobErrorMessage(error: AxiosError): Promise<string | undefined> {
 const body = error.response?.data;
 if (!(body instanceof Blob) || !body.type.includes('json')) return undefined;
 try {
 return (JSON.parse(await body.text()) as { message?: string }).message;
 } catch {
 return undefined;
 }
}

/**
 * Fetch a protected file through the shared authenticated client, then hand it
 * to the browser. Direct API links cannot recover stale sessions/CSRF state and
 * leave users staring at raw JSON in a new tab.
 */
export async function downloadAuthenticatedFile(
 url: string,
 options: { filename?: string; openInNewTab?: boolean; errorMessage?: string } = {},
): Promise<boolean> {
 const popup = options.openInNewTab ? window.open('', '_blank') : null;
 if (popup) popup.opener = null;

 try {
 const response = await client.get<Blob>(apiPath(url), { responseType: 'blob' });
 const blobUrl = URL.createObjectURL(response.data);
 const filename = options.filename
 ?? filenameFromDisposition(response.headers['content-disposition'])
 ?? 'download';

 if (options.openInNewTab && popup) {
 popup.location.href = blobUrl;
 } else {
 const anchor = document.createElement('a');
 anchor.href = blobUrl;
 anchor.download = filename;
 document.body.appendChild(anchor);
 anchor.click();
 anchor.remove();
 }

 window.setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000);
 return true;
 } catch (error) {
 popup?.close();
 const axiosError = error as AxiosError;
 // The shared client is already moving an expired session to /login.
 if (axiosError.response?.status !== 401) {
 toast.error(
 (await blobErrorMessage(axiosError))
 ?? options.errorMessage
 ?? 'Failed to download the file. Please try again.',
 );
 }
 return false;
 }
}
