export function assertE2EEnvironment(env?: Record<string, string | undefined>): {
  baseURL: string;
  database: 'ogami_e2e';
  project: 'ogami-erp-e2e';
};

export function assertE2EEnvironmentOrExit(env?: Record<string, string | undefined>): {
  baseURL: string;
  database: 'ogami_e2e';
  project: 'ogami-erp-e2e';
} | null;
