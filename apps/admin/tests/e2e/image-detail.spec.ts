import { test, expect } from '@playwright/test'
import { apiError, gotoReady, json, loginAsAdmin } from './helpers'

const READY_IMAGE = {
  id: 152,
  batch_id: 42,
  original_name: 'IMG_0001.jpg',
  status: 'ready',
  urls: {
    thumbnail: '/api/media/images/152/thumbnail',
    preview: '/api/media/images/152/preview',
    original: '/api/media/images/152/original',
  },
  created_at: '2026-09-25T13:50:00Z',
  updated_at: '2026-09-25T13:51:12Z',
  mime_type: 'image/jpeg',
  extension: 'jpg',
  width: 4032,
  height: 3024,
  file_size: 3481122,
  sha256: '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a0',
  caption: 'Two engineers wearing helmets near industrial equipment.',
  ocr_text: null,
  error_code: null,
  error_message: null,
  image_tags: [
    {
      tag: { id: 7, name: 'Инженер', type: 'person', status: 'active' },
      source: 'ai',
      confidence: 0.96,
      locked: false,
      created_at: '2026-09-25T13:51:12Z',
      updated_at: '2026-09-25T13:51:12Z',
    },
  ],
  latest_analysis: null,
  processing_tasks: [],
}

const FAILED_IMAGE = {
  ...READY_IMAGE,
  id: 153,
  status: 'failed',
  error_code: 'AI_SERVICE_UNAVAILABLE',
  error_message: 'AI service is unavailable',
  image_tags: [],
  processing_tasks: [
    {
      type: 'ai_analysis',
      status: 'failed',
      attempts: 3,
      max_attempts: 3,
      error_code: 'AI_SERVICE_UNAVAILABLE',
      error_message: 'AI service is unavailable',
      started_at: '2026-09-25T13:51:05Z',
      finished_at: '2026-09-25T13:51:10Z',
    },
  ],
}

test.describe('image detail', () => {
  test('shows tags and lets an admin remove one', async ({ page }) => {
    await loginAsAdmin(page)

    let removed = false
    await page.route('**/api/admin/images/152', (route) =>
      json(route, 200, removed ? { ...READY_IMAGE, image_tags: [] } : READY_IMAGE)
    )
    await page.route('**/api/admin/images/152/tags/7', (route) => {
      removed = true
      return route.fulfill({ status: 204 })
    })

    await gotoReady(page, '/gallery/152')
    await expect(page.getByText('IMG_0001.jpg')).toBeVisible()

    const tags = page.getByTestId('image-tags')
    await expect(tags.getByText('Инженер')).toBeVisible()

    await page.getByRole('button', { name: 'Remove Инженер' }).click()
    await expect(page.getByText('No tags assigned yet.')).toBeVisible()
  })

  test('adding a tag sends the selected tag id as an integer', async ({ page }) => {
    await loginAsAdmin(page)

    const KASKA = { id: 14, name: 'Каска', type: 'object', status: 'active' }
    let added = false
    await page.route('**/api/admin/images/152', (route) =>
      json(
        route,
        200,
        added
          ? {
              ...READY_IMAGE,
              image_tags: [
                ...READY_IMAGE.image_tags,
                { tag: KASKA, source: 'manual', confidence: null, locked: true, created_at: '2026-09-25T14:00:00Z', updated_at: '2026-09-25T14:00:00Z' },
              ],
            }
          : READY_IMAGE
      )
    )
    await page.route('**/api/admin/tags?*', (route) =>
      json(route, 200, {
        data: [
          { ...KASKA, parent_id: null, slug: 'kaska', normalized_name: 'каска', images_count: 3, aliases: [], created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z' },
        ],
        meta: { page: 1, per_page: 20, total: 1, last_page: 1 },
      })
    )
    let requestBody: { tag_id?: unknown } | undefined
    await page.route('**/api/admin/images/152/tags', (route) => {
      requestBody = route.request().postDataJSON()
      added = true
      return json(route, 201, { tag: KASKA, source: 'manual', confidence: null, locked: true, created_at: '2026-09-25T14:00:00Z', updated_at: '2026-09-25T14:00:00Z' })
    })

    await gotoReady(page, '/gallery/152')
    await page.getByRole('button', { name: 'Add Tag' }).click()
    await page.getByText('Select a tag').click()
    await page.getByRole('option', { name: 'Каска (object)' }).click()
    await page.getByRole('button', { name: 'Add', exact: true }).click()

    // The contract requires an integer id; the select must not submit its option object.
    await expect.poll(() => requestBody?.tag_id).toBe(14)
    await expect(page.getByTestId('image-tags').getByText('Каска')).toBeVisible()
  })

  test('retrying a failed image shows the updated status', async ({ page }) => {
    await loginAsAdmin(page)

    let retried = false
    await page.route('**/api/admin/images/153', (route) =>
      json(route, 200, retried ? { ...FAILED_IMAGE, status: 'queued', error_code: null, error_message: null } : FAILED_IMAGE)
    )
    await page.route('**/api/admin/images/153/retry', (route) => {
      retried = true
      return json(route, 202, { ...FAILED_IMAGE, status: 'queued', error_code: null, error_message: null })
    })

    await gotoReady(page, '/gallery/153')
    await expect(page.getByText('AI_SERVICE_UNAVAILABLE', { exact: true })).toBeVisible()

    await page.getByRole('button', { name: 'Retry Processing' }).click()
    await expect(page.getByText('queued', { exact: true })).toBeVisible()
  })
})

test.describe('image detail: rename and delete', () => {
  // GET serves the current image; PATCH/DELETE are handled by the individual specs through `onWrite`.
  async function mockImage(
    page: import('@playwright/test').Page,
    onWrite: (route: import('@playwright/test').Route, method: string) => ReturnType<typeof json> | undefined,
    current: () => typeof READY_IMAGE = () => READY_IMAGE,
  ) {
    await page.route('**/api/admin/images/152', (route) => {
      const method = route.request().method()
      if (method === 'GET') return json(route, 200, current())
      return onWrite(route, method)
    })
  }

  test('renames the image, sending only original_name', async ({ page }) => {
    await loginAsAdmin(page)

    let name = READY_IMAGE.original_name
    const bodies: unknown[] = []
    await mockImage(
      page,
      (route, method) => {
        expect(method).toBe('PATCH')
        const body = route.request().postDataJSON() as { original_name: string }
        bodies.push(body)
        name = body.original_name
        return json(route, 200, { ...READY_IMAGE, original_name: name })
      },
      () => ({ ...READY_IMAGE, original_name: name }),
    )

    await gotoReady(page, '/gallery/152')
    await expect(page.getByTestId('image-name')).toHaveText('IMG_0001.jpg')

    await page.getByRole('button', { name: 'Rename image' }).click()
    const field = page.getByLabel('Filename', { exact: true })
    await expect(field).toHaveValue('IMG_0001.jpg')
    // Nothing to save until the name actually changes.
    await expect(page.getByRole('button', { name: 'Save name' })).toBeDisabled()

    await field.fill('  site-visit-042.jpg ')
    await page.getByRole('button', { name: 'Save name' }).click()

    await expect(page.getByTestId('image-name')).toHaveText('site-visit-042.jpg')
    await expect(field).toHaveCount(0)
    // ADR-0006: original_name is the only editable field.
    expect(bodies).toEqual([{ original_name: 'site-visit-042.jpg' }])
  })

  test('cancelling a rename keeps the old name and sends nothing', async ({ page }) => {
    await loginAsAdmin(page)
    let writes = 0
    await mockImage(page, (route) => {
      writes++
      return json(route, 200, READY_IMAGE)
    })

    await gotoReady(page, '/gallery/152')
    await page.getByRole('button', { name: 'Rename image' }).click()
    await page.getByLabel('Filename', { exact: true }).fill('something-else.jpg')
    await page.getByRole('button', { name: 'Cancel' }).click()

    await expect(page.getByTestId('image-name')).toHaveText('IMG_0001.jpg')
    expect(writes).toBe(0)
  })

  test('a blank name is rejected before any request', async ({ page }) => {
    await loginAsAdmin(page)
    let writes = 0
    await mockImage(page, (route) => {
      writes++
      return json(route, 200, READY_IMAGE)
    })

    await gotoReady(page, '/gallery/152')
    await page.getByRole('button', { name: 'Rename image' }).click()
    await page.getByLabel('Filename', { exact: true }).fill('   ')

    await expect(page.getByText('Enter a file name.')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Save name' })).toBeDisabled()
    await page.getByLabel('Filename', { exact: true }).press('Enter')
    expect(writes).toBe(0)
  })

  test('shows the 422 validation error from the API and stays in edit mode', async ({ page }) => {
    await loginAsAdmin(page)
    await mockImage(page, (route) =>
      json(route, 422, {
        error: {
          code: 'VALIDATION_ERROR',
          message: 'The given data was invalid',
          details: { fields: { original_name: ['The original name field must not be greater than 1024 characters.'] } },
          trace_id: 'e2e-trace-id',
        },
      }),
    )

    await gotoReady(page, '/gallery/152')
    await page.getByRole('button', { name: 'Rename image' }).click()
    await page.getByLabel('Filename', { exact: true }).fill('renamed.jpg')
    await page.getByRole('button', { name: 'Save name' }).click()

    await expect(page.getByText('The original name field must not be greater than 1024 characters.')).toBeVisible()
    await expect(page.getByRole('alert')).toContainText('The given data was invalid')
    await expect(page.getByLabel('Filename', { exact: true })).toHaveValue('renamed.jpg')
  })

  test('renaming an image that was deleted elsewhere shows IMAGE_NOT_FOUND', async ({ page }) => {
    await loginAsAdmin(page)
    await mockImage(page, (route) => apiError(route, 404, 'IMAGE_NOT_FOUND', 'Image not found'))

    await gotoReady(page, '/gallery/152')
    await page.getByRole('button', { name: 'Rename image' }).click()
    await page.getByLabel('Filename', { exact: true }).fill('renamed.jpg')
    await page.getByRole('button', { name: 'Save name' }).click()

    await expect(page.getByRole('alert')).toContainText('Image not found')
    await expect(page.getByTestId('image-name')).toHaveCount(0)
  })

  test('deletes the image after confirmation and returns to a fresh gallery', async ({ page }) => {
    await loginAsAdmin(page)

    let deleted = false
    const deleteCalls: string[] = []
    await mockImage(page, (route, method) => {
      deleteCalls.push(`${method} ${new URL(route.request().url()).pathname}`)
      deleted = true
      return route.fulfill({ status: 204 })
    })
    let listRequests = 0
    await page.route('**/api/admin/images?*', (route) => {
      listRequests++
      const image = { id: 152, batch_id: 42, original_name: 'IMG_0001.jpg', status: 'ready', tags: [], urls: READY_IMAGE.urls, created_at: READY_IMAGE.created_at }
      return json(route, 200, {
        data: deleted ? [] : [image],
        meta: { page: 1, per_page: 24, total: deleted ? 0 : 1, last_page: 1 },
      })
    })
    await page.route('**/api/admin/batches?*', (route) =>
      json(route, 200, { data: [], meta: { page: 1, per_page: 100, total: 0, last_page: 1 } }),
    )

    await gotoReady(page, '/gallery/152')
    await page.getByRole('button', { name: 'Delete image' }).click()

    const confirmation = page.getByRole('region', { name: 'Delete confirmation' })
    await expect(confirmation.getByRole('heading', { name: 'Delete this image?' })).toBeVisible()
    // The opener gives way to the confirmation, and nothing is sent yet.
    await expect(page.getByRole('button', { name: 'Delete image' })).toHaveCount(0)
    expect(deleteCalls).toEqual([])

    await confirmation.getByRole('button', { name: 'Confirm delete' }).click()

    await page.waitForURL('**/gallery')
    expect(deleteCalls).toEqual(['DELETE /api/admin/images/152'])
    await expect(page.getByRole('heading', { name: 'Gallery' })).toBeVisible()
    await expect(page.getByText('No images found. Start by uploading a batch.')).toBeVisible()
    expect(listRequests).toBeGreaterThan(0)
  })

  test('cancelling the delete confirmation keeps the image', async ({ page }) => {
    await loginAsAdmin(page)
    let writes = 0
    await mockImage(page, (route) => {
      writes++
      return route.fulfill({ status: 204 })
    })

    await gotoReady(page, '/gallery/152')
    await page.getByRole('button', { name: 'Delete image' }).click()
    await page.getByRole('region', { name: 'Delete confirmation' }).getByRole('button', { name: 'Cancel' }).click()

    await expect(page.getByRole('region', { name: 'Delete confirmation' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Delete image' })).toBeVisible()
    await expect(page.getByTestId('image-name')).toHaveText('IMG_0001.jpg')
    expect(writes).toBe(0)
  })

  test('a repeated delete surfaces IMAGE_NOT_FOUND and keeps the page', async ({ page }) => {
    await loginAsAdmin(page)
    await mockImage(page, (route) => apiError(route, 404, 'IMAGE_NOT_FOUND', 'Image not found'))

    await gotoReady(page, '/gallery/152')
    await page.getByRole('button', { name: 'Delete image' }).click()
    await page.getByRole('button', { name: 'Confirm delete' }).click()

    await expect(page.getByRole('alert')).toContainText('Image not found')
    await expect(page).toHaveURL(/\/gallery\/152$/)
    // Still on the page, and the admin can back out of the confirmation.
    await page.getByRole('button', { name: 'Cancel' }).click()
    await expect(page.getByRole('button', { name: 'Delete image' })).toBeVisible()
  })
})
