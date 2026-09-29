import { expect, test, type Page } from '@playwright/test'
import type { ApiError, Paginated, SearchResponse, Tag } from '../lib/api'
import { apiGet, compose, gotoReady, login, pickOption, tagOptionLabel, waitForAiReady, waitForApi } from '../lib/app'
import { env } from '../lib/env'

/**
 * ТЗ §46 on the real stack: with the AI container stopped, semantic search
 * reports AI_SERVICE_UNAVAILABLE with a retry action while tag search keeps
 * working; after the container is back, the same retry returns results.
 *
 * Opt-in (E2E_AI_OUTAGE=1) because it stops and starts a container of the
 * stack it runs against. Needs processed images and at least one tag with
 * images — the `acceptance` project (a dependency in playwright.config.ts)
 * leaves both behind.
 */

const QUERY = 'мужчина в каске на промышленном объекте'

let aiStopped = false

async function restoreAi(): Promise<void> {
  if (!aiStopped) return
  compose(['start', env.aiService])
  aiStopped = false
  await waitForAiReady()
}

test.afterAll(async () => {
  // Safety net if the test failed or timed out while the AI container was stopped.
  await restoreAi()
})

async function openSearch(page: Page, mode: 'Только теги' | 'По смыслу запроса'): Promise<void> {
  await gotoReady(page, '/search')
  await pickOption(page, page.getByRole('combobox', { name: 'Режим поиска' }), mode)
}

test('§46: AI down → semantic search offers retry, tag search still works; AI back → retry succeeds', async ({ page, context }) => {
  test.skip(!env.aiOutage, 'opt-in: set E2E_AI_OUTAGE=1 (stops and restarts the AI container)')
  test.setTimeout(5 * 60_000 + env.aiReadyTimeoutMs)

  await login(page)
  const tags = await apiGet<Paginated<Tag>>(page, '/api/admin/tags?status=active&sort=-images_count&per_page=1')
  const tag = tags.data[0]
  expect(tag?.images_count ?? 0, 'an active tag with images (run the acceptance project first)').toBeGreaterThan(0)
  await waitForAiReady()

  await test.step(`docker compose stop ${env.aiService}`, async () => {
    compose(['stop', env.aiService])
    aiStopped = true
  })

  await test.step('Semantic search: AI service unavailable + Retry search', async () => {
    await openSearch(page, 'По смыслу запроса')
    await page.getByPlaceholder('Например: рабочие в касках возле промышленного оборудования').fill(QUERY)
    const [response] = await Promise.all([
      waitForApi(page, 'POST', '/api/search'),
      page.getByRole('button', { name: 'Найти', exact: true }).click(),
    ])
    expect(response.status(), 'semantic search with the AI stopped').toBe(503)
    const body = (await response.json()) as ApiError
    expect(body.error.code).toBe('AI_SERVICE_UNAVAILABLE')
    // Contract: every response carries X-Request-ID, equal to error.trace_id for errors (§64).
    expect(response.headers()['x-request-id']).toBe(body.error.trace_id)

    await expect(page.getByRole('alert')).toContainText('ИИ-сервис недоступен')
    await expect(page.getByRole('button', { name: 'Повторить поиск' })).toBeVisible()
  })

  await test.step('Tag search keeps working without AI', async () => {
    const tagPage = await context.newPage()
    try {
      await openSearch(tagPage, 'Только теги')
      await pickOption(tagPage, tagPage.getByText('Выберите теги (нужны все)', { exact: true }), tagOptionLabel(tag!), tag!.name)
      await tagPage.keyboard.press('Escape')
      const [response] = await Promise.all([
        waitForApi(tagPage, 'POST', '/api/search'),
        tagPage.getByRole('button', { name: 'Найти', exact: true }).click(),
      ])
      expect(response.status(), 'tag search with the AI stopped').toBe(200)
      const result = (await response.json()) as SearchResponse
      expect(result.meta.mode).toBe('tags')
      expect(result.data.length).toBeGreaterThan(0)
      await expect(tagPage.getByTestId('search-results').getByRole('link')).toHaveCount(result.data.length)
    } finally {
      await tagPage.close()
    }
  })

  await test.step(`docker compose start ${env.aiService} and wait for /ready`, async () => {
    await restoreAi()
  })

  await test.step('Retry search returns results', async () => {
    const [response] = await Promise.all([
      waitForApi(page, 'POST', '/api/search'),
      page.getByRole('button', { name: 'Повторить поиск' }).click(),
    ])
    expect(response.status(), 'semantic search after the AI is back').toBe(200)
    const result = (await response.json()) as SearchResponse
    expect(result.meta.mode).toBe('semantic')
    expect(result.data.length).toBeGreaterThan(0)
    await expect(page.getByTestId('search-results')).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Повторить поиск' })).toHaveCount(0)
  })
})
