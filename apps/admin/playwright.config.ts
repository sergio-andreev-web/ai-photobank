import { defineConfig, devices } from '@playwright/test'

// E2E_PORT lets the suite run next to something else already on :3000 (e.g. the
// Compose admin container), which `reuseExistingServer` would otherwise pick up.
const port = Number(process.env.E2E_PORT ?? 3000)
const baseURL = `http://localhost:${port}`

export default defineConfig({
  testDir: './tests/e2e',
  webServer: {
    command: `npm run dev -- --port ${port}`,
    url: baseURL,
    reuseExistingServer: !process.env.CI,
  },
  use: {
    baseURL,
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
