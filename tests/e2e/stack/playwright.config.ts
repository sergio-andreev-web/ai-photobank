import { defineConfig, devices } from '@playwright/test'
import { env } from './lib/env'

/**
 * Browser acceptance run against an already running stack (`make setup`),
 * see README.md. Unlike apps/admin/playwright.config.ts there is no web
 * server and no route mocking: the browser talks to nginx like an admin would.
 */
export default defineConfig({
  testDir: './specs',
  // One shared stack whose data the specs change: run everything in one worker, in order.
  fullyParallel: false,
  workers: 1,
  // A retry would upload a second batch and moderate other candidates; fail loudly instead.
  retries: 0,
  forbidOnly: !!process.env.CI,
  timeout: env.testTimeoutMs,
  expect: { timeout: 15_000 },
  // The HTML report (plus trace, screenshot and video of a failed test) is what CI uploads on failure.
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  outputDir: 'test-results',
  use: {
    baseURL: env.baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },
  projects: [
    // Pure Node checks of the upload generator; needs no stack.
    { name: 'unit', testMatch: /png\.spec\.ts$/ },
    { name: 'acceptance', testMatch: /section-75\.spec\.ts$/, use: { ...devices['Desktop Chrome'] } },
    {
      // Opt-in (E2E_AI_OUTAGE=1), after the acceptance run that leaves processed, tagged images behind.
      name: 'ai-outage',
      testMatch: /ai-outage\.spec\.ts$/,
      dependencies: ['acceptance'],
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
