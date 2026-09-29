import { test, expect } from '@playwright/test'
import { apiError, gotoReady, json, loginAsAdmin } from './helpers'

const RESULT_IMAGE = {
  id: 152,
  batch_id: 42,
  original_name: 'IMG_0001.jpg',
  status: 'ready',
  tags: [{ id: 14, name: 'Каска', type: 'object', status: 'active' }],
  urls: {
    thumbnail: '/api/media/images/152/thumbnail',
    preview: '/api/media/images/152/preview',
    original: '/api/media/images/152/original',
  },
  created_at: '2026-09-25T13:50:00Z',
  semantic_score: 0.873,
}

test.describe('search', () => {
  test('semantic search retries after AI_SERVICE_UNAVAILABLE and then succeeds', async ({ page }) => {
    await loginAsAdmin(page)
    await page.route('**/api/admin/tags?*', (route) =>
      json(route, 200, { data: [], meta: { page: 1, per_page: 20, total: 0, last_page: 1 } })
    )

    let attempt = 0
    await page.route('**/api/search', (route) => {
      attempt++
      if (attempt === 1) {
        return apiError(route, 503, 'AI_SERVICE_UNAVAILABLE', 'AI service is unavailable')
      }
      return json(route, 200, {
        data: [RESULT_IMAGE],
        meta: { page: 1, per_page: 24, total: 1, last_page: 1, mode: 'semantic' },
      })
    })

    await gotoReady(page, '/search')
    // Nuxt UI v3 USelect renders role="combobox", not a native <select> or <button>.
    await page.getByRole('combobox', { name: 'Режим поиска' }).click()
    await page.getByRole('option', { name: 'По смыслу запроса' }).click()

    await page.getByPlaceholder(/рабочие в касках/).fill('мужчина в каске на промышленном объекте')
    await page.getByRole('button', { name: 'Найти', exact: true }).click()

    await expect(page.getByText('ИИ-сервис недоступен')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Повторить поиск' })).toBeVisible()

    await page.getByRole('button', { name: 'Повторить поиск' }).click()
    await expect(page.getByTestId('search-results')).toBeVisible()
    await expect(page.getByText('IMG_0001.jpg')).toBeVisible()
  })

  test('tags-only search sends the selected tag ids as integers', async ({ page }) => {
    await loginAsAdmin(page)
    await page.route('**/api/admin/tags?*', (route) =>
      json(route, 200, {
        data: [{ id: 14, parent_id: null, name: 'Каска', slug: 'kaska', normalized_name: 'каска', type: 'object', status: 'active', images_count: 3, aliases: [], created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z' }],
        meta: { page: 1, per_page: 20, total: 1, last_page: 1 },
      })
    )
    let requestBody: { tag_ids?: unknown } | undefined
    await page.route('**/api/search', (route) => {
      requestBody = route.request().postDataJSON()
      return json(route, 200, {
        data: [RESULT_IMAGE],
        meta: { page: 1, per_page: 24, total: 1, last_page: 1, mode: 'tags' },
      })
    })

    await gotoReady(page, '/search')
    await page.getByRole('combobox', { name: 'Режим поиска' }).click()
    await page.getByRole('option', { name: 'Только теги' }).click()
    await page.getByText('Выберите теги (нужны все)').click()
    await page.getByRole('option', { name: 'Каска (Объект)' }).click()
    await page.keyboard.press('Escape')
    await page.getByRole('button', { name: 'Найти', exact: true }).click()

    // The contract requires integer ids; the select must not submit its option objects.
    await expect.poll(() => requestBody?.tag_ids).toEqual([14])
    await expect(page.getByText('IMG_0001.jpg')).toBeVisible()
  })

  test('tags-only search requires at least one tag before submitting', async ({ page }) => {
    await loginAsAdmin(page)
    await page.route('**/api/admin/tags?*', (route) =>
      json(route, 200, {
        data: [{ id: 14, parent_id: null, name: 'Каска', slug: 'kaska', normalized_name: 'каска', type: 'object', status: 'active', images_count: 3, aliases: [], created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z' }],
        meta: { page: 1, per_page: 20, total: 1, last_page: 1 },
      })
    )

    await gotoReady(page, '/search')
    await page.getByRole('combobox', { name: 'Режим поиска' }).click()
    await page.getByRole('option', { name: 'Только теги' }).click()

    await expect(page.getByRole('button', { name: 'Найти', exact: true })).toBeDisabled()
    await expect(page.getByText('Выберите хотя бы один тег.')).toBeVisible()
  })

  test('opens from a link with mode and q, prefills the form and runs the semantic search', async ({ page }) => {
    await loginAsAdmin(page)
    await page.route('**/api/admin/tags?*', (route) =>
      json(route, 200, { data: [], meta: { page: 1, per_page: 20, total: 0, last_page: 1 } })
    )
    const requests: unknown[] = []
    await page.route('**/api/search', (route) => {
      requests.push(route.request().postDataJSON())
      return json(route, 200, {
        data: [RESULT_IMAGE],
        meta: { page: 1, per_page: 24, total: 1, last_page: 1, mode: 'semantic' },
      })
    })

    await gotoReady(page, `/search?mode=semantic&q=${encodeURIComponent('мужчина в каске & без очков')}`)

    await expect(page.getByTestId('search-results')).toBeVisible()
    await expect(page.getByText('IMG_0001.jpg')).toBeVisible()
    await expect(page.getByRole('combobox', { name: 'Режим поиска' })).toContainText('По смыслу запроса')
    await expect(page.getByPlaceholder(/рабочие в касках/)).toHaveValue('мужчина в каске & без очков')
    // Exactly one request, in the shape of the contract's semantic mode.
    expect(requests).toEqual([
      { mode: 'semantic', query: 'мужчина в каске & без очков', page: 1, per_page: 24, debug: false },
    ])
  })

  test('a link with a query but without a runnable form only prefills it', async ({ page }) => {
    await loginAsAdmin(page)
    await page.route('**/api/admin/tags?*', (route) =>
      json(route, 200, { data: [], meta: { page: 1, per_page: 20, total: 0, last_page: 1 } })
    )
    let searches = 0
    await page.route('**/api/search', (route) => {
      searches++
      return json(route, 200, { data: [], meta: { page: 1, per_page: 24, total: 0, last_page: 1, mode: 'hybrid' } })
    })

    // Hybrid cannot run without tags.
    await gotoReady(page, '/search?mode=hybrid&q=helmet')

    await expect(page.getByPlaceholder(/рабочие в касках/)).toHaveValue('helmet')
    await expect(page.getByRole('combobox', { name: 'Режим поиска' })).toContainText('Гибридный')
    await expect(page.getByText('Для гибридного поиска нужны запрос и хотя бы один тег.')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Найти', exact: true })).toBeDisabled()
    await page.waitForTimeout(300)
    expect(searches).toBe(0)
  })

  test('opens in semantic mode; an unknown mode in a link falls back to it and runs', async ({ page }) => {
    await loginAsAdmin(page)
    await page.route('**/api/admin/tags?*', (route) =>
      json(route, 200, { data: [], meta: { page: 1, per_page: 20, total: 0, last_page: 1 } })
    )
    const requests: unknown[] = []
    await page.route('**/api/search', (route) => {
      requests.push(route.request().postDataJSON())
      return json(route, 200, { data: [RESULT_IMAGE], meta: { page: 1, per_page: 24, total: 1, last_page: 1, mode: 'semantic' } })
    })

    await gotoReady(page, '/search?mode=fulltext&q=helmet')
    await expect(page.getByRole('combobox', { name: 'Режим поиска' })).toContainText('По смыслу запроса')
    await expect(page.getByTestId('search-results')).toBeVisible()
    expect(requests).toEqual([{ mode: 'semantic', query: 'helmet', page: 1, per_page: 24, debug: false }])
  })

  test('a plain /search does not run anything', async ({ page }) => {
    await loginAsAdmin(page)
    await page.route('**/api/admin/tags?*', (route) =>
      json(route, 200, { data: [], meta: { page: 1, per_page: 20, total: 0, last_page: 1 } })
    )
    let searches = 0
    await page.route('**/api/search', (route) => {
      searches++
      return json(route, 200, { data: [], meta: { page: 1, per_page: 24, total: 0, last_page: 1, mode: 'hybrid' } })
    })

    await gotoReady(page, '/search')

    await expect(page.getByRole('button', { name: 'Найти', exact: true })).toBeDisabled()
    await page.waitForTimeout(300)
    expect(searches).toBe(0)
  })
})
