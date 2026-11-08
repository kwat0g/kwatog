import { randomUUID } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import type { Page, TestInfo } from '@playwright/test';
import { test } from './fixtures';
import { captureAnnotatedStep } from './helpers/annotated-screenshot';

export type JourneyContext = {
  journeyId: string;
  runId: string;
  startedAt: string;
  values: Record<string, unknown>;
  steps: Array<{
    name: string;
    actor: string;
    uiPath: string;
    expectedStatus: string;
    state: 'running' | 'passed' | 'failed' | 'blocked';
    startedAt: string;
    finishedAt?: string;
    error?: string;
    screenshot?: string;
  }>;
};

export function createJourneyContext(journeyId: string): JourneyContext {
  const runId = randomUUID();
  return {
    journeyId,
    runId,
    startedAt: new Date().toISOString(),
    values: { runPrefix: `E2E-${runId.slice(0, 12)}` },
    steps: [],
  };
}

export type ProcessStep = {
  name: string;
  actor: string;
  uiPath: string;
  expectedStatus: string;
  page: Page;
  plannedSteps: Array<{ name: string; actor: string; uiPath: string; expectedStatus: string }>;
  context: JourneyContext;
  testInfo: TestInfo;
  action: (page: Page, context: JourneyContext) => Promise<void>;
  verify: (page: Page, context: JourneyContext) => Promise<void>;
  assertInvariants: (page: Page, context: JourneyContext) => Promise<void>;
  captureScreenshot?: boolean;
  gapId?: string;
  gapReason?: string;
};

async function persistContext(context: JourneyContext, testInfo: TestInfo): Promise<void> {
  const outputPath = testInfo.outputPath('journey-context.json');
  await mkdir(path.dirname(outputPath), { recursive: true });
  await writeFile(outputPath, JSON.stringify(context, null, 2), { mode: 0o600 });
}

export async function runProcessStep(step: ProcessStep): Promise<void> {
  let blockedReason: string | undefined;
  await test.step(`${step.actor}: ${step.name}`, async () => {
    const currentIndex = step.context.steps.length;
    const result = {
      name: step.name,
      actor: step.actor,
      uiPath: step.uiPath,
      expectedStatus: step.expectedStatus,
      state: 'running' as const,
      startedAt: new Date().toISOString(),
    };
    step.context.steps.push(result);

    try {
      await step.action(step.page, step.context);
      await step.verify(step.page, step.context);
      await step.assertInvariants(step.page, step.context);
      if (step.captureScreenshot !== false) {
        step.context.steps[currentIndex].screenshot = await captureAnnotatedStep(
          step.page,
          step.actor,
          step.name,
          currentIndex + 1,
          step.testInfo,
        );
      }
      if (step.gapId) {
        blockedReason = `${step.gapId}: ${step.gapReason ?? 'Process step is unavailable through the UI.'}`;
        step.context.steps[currentIndex].state = 'blocked';
        step.context.steps[currentIndex].error = blockedReason;
      } else {
        step.context.steps[currentIndex].state = 'passed';
      }
    } catch (error) {
      step.context.steps[currentIndex].state = 'failed';
      step.context.steps[currentIndex].error = error instanceof Error ? error.message : 'Unknown step failure';
      for (const remaining of step.plannedSteps.slice(currentIndex + 1)) {
        step.context.steps.push({
          ...remaining,
          state: 'blocked',
          startedAt: new Date().toISOString(),
          error: `Blocked because step ${step.name} failed.`,
        });
      }
      throw error;
    } finally {
      step.context.steps[currentIndex].finishedAt = new Date().toISOString();
      await persistContext(step.context, step.testInfo);
    }
  });
  if (blockedReason) test.fixme(true, blockedReason);
}
