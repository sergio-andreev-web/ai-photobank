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
    await page.getByRole('combobox', { name: 'Search Mode' }).click()
    await page.getByRole('option', { name: 'Semantic query' }).click()

    await page.getByPlaceholder(/workers in hard hats/).fill('мужчина в каске на промышленном объекте')
    await page.getByRole('button', { name: 'Search', exact: true }).click()

    await expect(page.getByText('AI service unavailable')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Retry search' })).toBeVisible()

    await page.getByRole('button', { name: 'Retry search' }).click()
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
    await page.getByRole('combobox', { name: 'Search Mode' }).click()
    await page.getByRole('option', { name: 'Tags only' }).click()
    await page.getByText('Select tags (all must match)').click()
    await page.getByRole('option', { name: 'Каска (object)' }).click()
    await page.keyboard.press('Escape')
    await page.getByRole('button', { name: 'Search', exact: true }).click()

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
    await page.getByRole('combobox', { name: 'Search Mode' }).click()
    await page.getByRole('option', { name: 'Tags only' }).click()

    await expect(page.getByRole('button', { name: 'Search', exact: true })).toBeDisabled()
    await expect(page.getByText('Select at least one tag.')).toBeVisible()
  })
})
