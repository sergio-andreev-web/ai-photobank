import { execFileSync } from 'node:child_process'
import { expect, type Locator, type Page, type Response } from '@playwright/test'
import { env } from './env'

/**
 * The admin is a client-only SPA (nuxt.config `ssr: false`): every full
 * navigation mounts Vue from scratch, so wait for app.vue's `data-app-ready`
 * marker before touching the page (same helper as apps/admin/tests/e2e).
 */
export async function gotoReady(page: Page, url: string): Promise<void> {
  await page.goto(url)
  await page.waitForSelector('[data-app-ready="true"]')
}

/** ТЗ §75 step 1 — the seeded admin signs in through the login form. */
export async function login(page: Page): Promise<void> {
  await gotoReady(page, '/login')
  await page.getByPlaceholder('admin@example.com').fill(env.adminEmail)
  await page.getByPlaceholder('••••••••').fill(env.adminPassword)
  const [response] = await Promise.all([
    waitForApi(page, 'POST', '/api/auth/login'),
    // "Sign in" today, "Sign in to Photobank" after the admin redesign (#44).
    page.getByRole('button', { name: /^Sign in/ }).click(),
  ])
  expect(response.status(), 'POST /api/auth/login').toBe(200)
  await page.waitForURL('**/dashboard')
}

/** Resolves with the next response of `method` whose URL path equals `path` (or matches it). */
export function waitForApi(page: Page, method: string, path: string | RegExp): Promise<Response> {
  return page.waitForResponse((response) => {
    if (response.request().method() !== method) return false
    const pathname = new URL(response.url()).pathname
    return typeof path === 'string' ? pathname === path : path.test(pathname)
  })
}

export interface ApiResult<T> {
  status: number
  body: T
}

/**
 * GET through the page's own fetch, so the request carries the SPA session
 * cookie and a same-origin Referer exactly like the app's calls (Sanctum
 * stateful auth). Used only to read state for choosing and cross-checking
 * data; every §75 action goes through the UI.
 */
export async function apiGet<T>(page: Page, path: string): Promise<T> {
  const result = await page.evaluate(async (url) => {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    const text = await response.text()
    let body: unknown = text
    try {
      body = JSON.parse(text)
    } catch {
      // Keep the raw text for the assertion message below.
    }
    return { status: response.status, body }
  }, path)
  expect(result.status, `GET ${path}: ${JSON.stringify(result.body).slice(0, 500)}`).toBe(200)
  return result.body as T
}

/** Opens a Nuxt UI USelect/USelectMenu by its trigger and picks an option by its exact label. */
export async function pickOption(page: Page, trigger: Locator, label: string, searchTerm?: string): Promise<void> {
  await trigger.click()
  if (searchTerm !== undefined) {
    // USelectMenu renders an autofocused search input (Nuxt UI locale placeholder "Search...");
    // typing narrows both the server-side tag lookup and the client-side filter, so the
    // wanted tag is found even when the catalog is larger than one page of options.
    await page.getByPlaceholder('Search...').fill(searchTerm)
  }
  await page.getByRole('option', { name: label, exact: true }).click()
}

export function escapeRegExp(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/** `docker compose --profile ai <args>` in the stack's project directory. */
export function compose(args: string[], timeoutMs = 120_000): string {
  return execFileSync('docker', ['compose', '--profile', 'ai', ...args], {
    cwd: env.composeDir,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: timeoutMs,
  })
}

/** Polls the AI service's own readiness endpoint (ai-api.openapi.yaml `/ready`, no auth). */
export async function waitForAiReady(timeoutMs = env.aiReadyTimeoutMs): Promise<void> {
  const deadline = Date.now() + timeoutMs
  let last = 'no response yet'
  while (Date.now() < deadline) {
    try {
      const response = await fetch(env.aiReadyURL, { signal: AbortSignal.timeout(5_000) })
      if (response.ok) return
      last = `HTTP ${response.status}`
    } catch (error) {
      last = error instanceof Error ? error.message : String(error)
    }
    await new Promise((resolve) => setTimeout(resolve, 1_000))
  }
  throw new Error(`AI service not ready at ${env.aiReadyURL} after ${timeoutMs} ms (${last})`)
}
