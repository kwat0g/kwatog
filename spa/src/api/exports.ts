/**
 * WS-E.1 — Generic export client.
 *
 * Resolves the download URL the SPA should hit for a given resource +
 * filter set. Uses a same-origin <a download> click instead of an axios
 * blob fetch so the browser handles streaming + Content-Disposition for
 * very large exports.
 */
export type ExportFormat = 'csv';

export interface ExportArgs {
  resource: string;
  format?: ExportFormat;
  filters?: Record<string, string | number | boolean | undefined>;
}

export function buildExportUrl({ resource, format = 'csv', filters = {} }: ExportArgs): string {
  const qs = new URLSearchParams({ format });
  for (const [k, v] of Object.entries(filters)) {
    if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
  }
  return `/api/v1/exports/${encodeURIComponent(resource)}?${qs.toString()}`;
}

export function triggerBrowserDownload(url: string): void {
  // Use a temporary anchor instead of window.location so the page does
  // not navigate away if the server returns an error envelope.
  const a = document.createElement('a');
  a.href = url;
  a.rel = 'noopener';
  a.target = '_blank';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}
