// Shared API response shapes mirroring our Laravel envelopes.

export interface ApiSuccess<T> {
  data: T;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

export interface PaginationLinks {
  first: string;
  last: string;
  prev: string | null;
  next: string | null;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: PaginationMeta;
  links: PaginationLinks;
}

export interface ListParams {
  search?: string;
  page?: number;
  per_page?: number;
  sort?: string;
  direction?: 'asc' | 'desc';
  [key: string]: unknown;
}

export interface ApiValidationError {
  message: string;
  code?: string;
  errors: Record<string, string[]>;
}

export interface ApiError {
  message: string;
  code?: string;
  module?: string;
}
