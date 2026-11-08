const playwrightCommand = [
  'run', '--rm', '--no-deps', 'playwright', 'npx', 'playwright', 'test',
  '-c', 'playwright.process.config.ts',
];
const tabletJourneyIds = new Set(['PROD01-HAPPY']);

export function authSetupCommand() {
  return [...playwrightCommand, '--project=auth-setup', '--no-deps'];
}

export function validateJourneyId(journeyId) {
  if (typeof journeyId !== 'string' || !/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/.test(journeyId)) {
    throw new Error('A valid journey selector is required.');
  }
  return journeyId;
}

export function processJourneyCommand(grep) {
  const journeyId = validateJourneyId(grep);
  const project = tabletJourneyIds.has(journeyId) ? 'tablet-floor' : 'chromium-process';
  return [...playwrightCommand, `--project=${project}`, '--no-deps', '--grep', journeyId];
}
