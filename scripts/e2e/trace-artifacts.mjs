const sanitizer = 'scripts/e2e/sanitize_traces.py';
const artifactDirectory = 'spa/test-results/process';

export function sanitizeTraceArtifacts(run, environment) {
  const commands = [
    ['python3', [sanitizer, '--sanitize', artifactDirectory]],
    ['python3', [sanitizer, '--check', artifactDirectory]],
  ];
  for (const [command, args] of commands) {
    if (!run(command, args, environment)) return false;
  }
  return true;
}
