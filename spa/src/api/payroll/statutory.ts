/**
 * Statutory remittance export downloads (OGAMI-102/103).
 * Protected downloads go through the shared client so expired sessions are
 * handled consistently instead of rendering a raw API error.
 */
import { downloadAuthenticatedFile } from '@/api/download';

export const statutoryApi = {
 bir1601c: (year: number, month: number): Promise<boolean> =>
 downloadAuthenticatedFile(`/api/v1/payroll/statutory/1601c?year=${year}&month=${month}`, {
 errorMessage: 'Failed to download BIR 1601-C.',
 }),
 philhealthRf1: (year: number, month: number): Promise<boolean> =>
 downloadAuthenticatedFile(`/api/v1/payroll/statutory/rf1?year=${year}&month=${month}`, {
 errorMessage: 'Failed to download PhilHealth RF-1.',
 }),
 pagibigMcrf: (year: number, month: number): Promise<boolean> =>
 downloadAuthenticatedFile(`/api/v1/payroll/statutory/mcrf?year=${year}&month=${month}`, {
 errorMessage: 'Failed to download Pag-IBIG MCRF.',
 }),
 bir1604cf: (year: number): Promise<boolean> =>
 downloadAuthenticatedFile(`/api/v1/payroll/statutory/1604cf?year=${year}`, {
 errorMessage: 'Failed to download BIR 1604-CF.',
 }),
};
