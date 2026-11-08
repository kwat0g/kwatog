/** Runtime Compose label; network and volume names remain explicitly E2E-only. */
export const E2E_RUNTIME_PROJECT = 'ogami-erp-e2e-session';

export function composeArgs(args) {
  return [
    'compose',
    '--project-name', E2E_RUNTIME_PROJECT,
    '--env-file', '.env.e2e',
    '--file', 'docker-compose.e2e.yml',
    ...args,
  ];
}
