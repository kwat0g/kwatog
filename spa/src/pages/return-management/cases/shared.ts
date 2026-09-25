import type { ReturnCaseRealm } from '@/types/returnCases';

export function realmFromPath(pathname: string): ReturnCaseRealm {
  if (pathname.startsWith('/portal/customer/problems')) return 'customer';
  if (pathname.startsWith('/portal/supplier/problems')) return 'supplier';
  return 'internal';
}

export function casePath(realm: ReturnCaseRealm, id?: string): string {
  const root = realm === 'internal'
    ? '/return-management/cases'
    : `/portal/${realm}/problems`;
  return id ? `${root}/${id}` : root;
}

export function newCasePath(realm: ReturnCaseRealm): string {
  return `${casePath(realm)}/new`;
}

export function caseStatusVariant(status: string): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
  switch (status) {
    case 'resolved': return 'success';
    case 'under_review':
    case 'information_needed': return 'warning';
    case 'action_agreed':
    case 'in_progress': return 'info';
    case 'rejected': return 'danger';
    default: return 'neutral';
  }
}

export function toMilli(value: string): bigint | null {
  const match = /^(\d+)(?:\.(\d{0,3}))?$/.exec(value.trim());
  if (!match) return null;
  const whole = BigInt(match[1]);
  const fraction = BigInt((match[2] ?? '').padEnd(3, '0') || '0');
  return whole * 1000n + fraction;
}

export function fromMilli(value: bigint): string {
  const whole = value / 1000n;
  const fraction = (value % 1000n).toString().padStart(3, '0');
  return `${whole}.${fraction}`;
}

export function apiMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const response = (error as { response?: { data?: { message?: string } } }).response;
    if (response?.data?.message) return response.data.message;
  }
  return fallback;
}

export function apiFieldErrors(error: unknown): Record<string, string> {
  if (!error || typeof error !== 'object' || !('response' in error)) return {};
  const data = (error as { response?: { data?: { errors?: Record<string, string | string[]> } } }).response?.data;
  if (!data?.errors) return {};
  return Object.fromEntries(
    Object.entries(data.errors).map(([key, messages]) => [key, Array.isArray(messages) ? messages[0] : messages]),
  );
}

export function evidenceFileError(file: File): string | null {
  if (!/\.(jpe?g|png|webp|pdf)$/i.test(file.name)) return `${file.name}: choose a JPG, PNG, WebP or PDF file.`;
  if (file.size > 10 * 1024 * 1024) return `${file.name}: use a file smaller than 10 MB.`;
  return null;
}

export function createRequestKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const bytes: Uint8Array = typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function'
    ? crypto.getRandomValues(new Uint8Array(16))
    : Uint8Array.from({ length: 16 }, () => Math.floor(Math.random() * 256));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
