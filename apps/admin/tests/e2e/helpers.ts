import type { Page, Route } from '@playwright/test'

export function json(route: Route, status: number, body: unknown, headers: Record<string, string> = {}) {
  return route.fulfill({
    status,
    contentType: 'application/json',
    headers: { 'X-Request-ID': 'e2e-trace-id', ...headers },
    body: JSON.stringify(body),
  })
}

export function apiError(route: Route, status: number, code: string, message: string, details: Record<string, any> = {}) {
  return json(route, status, { error: { code, message, details, trace_id: 'e2e-trace-id' } })
}

/** A list item of `GET /api/admin/images` (contract ImageSummary). */
export function imageSummary(overrides: Record<string, unknown> & { id: number }) {
  const { id } = overrides
  return {
    batch_id: 42,
    original_name: `IMG_${id}.jpg`,
    status: 'ready',
    tags: [],
    urls: {
      thumbnail: `/api/media/images/${id}/thumbnail`,
      preview: `/api/media/images/${id}/preview`,
      original: `/api/media/images/${id}/original`,
    },
    created_at: '2026-09-25T13:50:00Z',
    ...overrides,
  }
}

/** A collection answer `{ data, meta }` (contract ADR-0002). */
export function pageOf<T>(data: T[], meta: Record<string, unknown> = {}) {
  return { data, meta: { page: 1, per_page: 24, total: data.length, last_page: 1, ...meta } }
}

/** Mocks the Sanctum CSRF cookie + login/me endpoints so pages behind the `auth` middleware load. */
export async function mockAuth(page: Page) {
  await page.route('**/sanctum/csrf-cookie', (route) =>
    route.fulfill({
      status: 204,
      headers: { 'set-cookie': 'XSRF-TOKEN=e2e-token; Path=/' },
    })
  )

  await page.route('**/api/auth/login', (route) =>
    json(route, 200, { id: 1, name: 'Administrator', email: 'admin@example.com', role: 'admin' })
  )

  await page.route('**/api/auth/me', (route) =>
    json(route, 200, { id: 1, name: 'Administrator', email: 'admin@example.com', role: 'admin' })
  )
}

/**
 * This app is a client-only SPA (nuxt.config `ssr: false`). Every `page.goto`
 * loads a blank shell and mounts the Vue app from scratch, so interacting
 * before that mount completes hits elements with no listeners yet. Every
 * navigation waits for the `data-app-ready` marker (set in app.vue's
 * onMounted) before the caller touches the page.
 */
export async function gotoReady(page: Page, url: string) {
  await page.goto(url)
  await page.waitForSelector('[data-app-ready="true"]')
}

export async function loginAsAdmin(page: Page) {
  await mockAuth(page)
  await gotoReady(page, '/login')
  await page.getByPlaceholder('admin@example.com').fill('admin@example.com')
  await page.getByPlaceholder('••••••••').fill('secret-password')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.waitForURL('**/dashboard')
}
