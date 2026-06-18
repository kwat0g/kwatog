/**
 * Statutory remittance export downloads (OGAMI-102/103).
 * Mirrors exportsApi.download: a transient <a download> hands the response
 * stream to the browser and carries the auth cookie automatically.
 */

function triggerDownload(url: string): void {
  const a = document.createElement('a');
  a.href = url;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

export const statutoryApi = {
  bir1601c: (year: number, month: number): string => {
    const url = `/api/v1/payroll/statutory/1601c?year=${year}&month=${month}`;
    triggerDownload(url);
    return url;
  },
  philhealthRf1: (year: number, month: number): string => {
    const url = `/api/v1/payroll/statutory/rf1?year=${year}&month=${month}`;
    triggerDownload(url);
    return url;
  },
  pagibigMcrf: (year: number, month: number): string => {
    const url = `/api/v1/payroll/statutory/mcrf?year=${year}&month=${month}`;
    triggerDownload(url);
    return url;
  },
  bir1604cf: (year: number): string => {
    const url = `/api/v1/payroll/statutory/1604cf?year=${year}`;
    triggerDownload(url);
    return url;
  },
};
