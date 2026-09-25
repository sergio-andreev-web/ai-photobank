import { test, expect } from '@playwright/test'
import { apiError, gotoReady, json, loginAsAdmin } from './helpers'

const TINY_JPEG = Buffer.from(
  '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=',
  'base64'
)

async function createBatch(page: import('@playwright/test').Page) {
  await page.route('**/api/admin/batches', (route) => {
    if (route.request().method() !== 'POST') return route.fallback()
    return json(route, 201, {
      id: 42,
      name: null,
      status: 'created',
      progress: { total: 0, processed: 0, failed: 0 },
      created_at: '2026-09-25T13:43:55Z',
      updated_at: '2026-09-25T13:43:55Z',
      completed_at: null,
    })
  })
}

test.describe('batch upload', () => {
  test('uploads several files in parallel and reports success, duplicate and error per file', async ({ page }) => {
    await loginAsAdmin(page)
    await createBatch(page)

    let nextImageId = 100
    await page.route('**/api/admin/batches/42/images', (route) => {
      const body = route.request().postData() || ''
      if (body.includes('dup.jpg')) {
        return apiError(route, 409, 'IMAGE_ALREADY_EXISTS', 'Image already exists', { existing_image_id: 999 })
      }
      if (body.includes('bad.jpg')) {
        return apiError(route, 415, 'UNSUPPORTED_MEDIA_TYPE', 'Unsupported media type')
      }
      const id = nextImageId++
      return json(route, 201, {
        id,
        batch_id: 42,
        original_name: 'ok.jpg',
        status: 'queued',
        tags: [],
        urls: { thumbnail: null, preview: null, original: `/api/media/images/${id}/original` },
        created_at: '2026-09-25T13:50:02Z',
      })
    })
    // The uploader starts polling batch progress as soon as the first file
    // succeeds (§49), well before "complete" is pressed — the batch is still
    // `uploading` and accepting files at that point (state-machines.md).
    await page.route('**/api/admin/batches/42', (route) =>
      json(route, 200, {
        id: 42,
        name: 'E2E batch',
        status: 'uploading',
        progress: { total: 1, processed: 0, failed: 0 },
        created_at: '2026-09-25T13:43:55Z',
        updated_at: '2026-09-25T13:50:05Z',
        completed_at: null,
      })
    )

    await gotoReady(page, '/uploader')
    await page.getByPlaceholder('e.g., Site visit - September 2026').fill('E2E batch')
    await page.getByRole('button', { name: 'Create Batch' }).click()

    await expect(page.getByText('Batch #42')).toBeVisible()

    const fileInput = page.locator('input[type="file"]')
    await fileInput.setInputFiles([
      { name: 'ok.jpg', mimeType: 'image/jpeg', buffer: TINY_JPEG },
      { name: 'dup.jpg', mimeType: 'image/jpeg', buffer: TINY_JPEG },
      { name: 'bad.jpg', mimeType: 'image/jpeg', buffer: TINY_JPEG },
    ])

    const list = page.getByTestId('upload-list')
    await expect(list.getByText('ok.jpg')).toBeVisible()
    await expect(list.getByText('dup.jpg')).toBeVisible()
    await expect(list.getByText('bad.jpg')).toBeVisible()

    await expect(list.getByText('Uploaded, queued for processing')).toBeVisible()
    await expect(list.getByText(/Skipped: duplicate of image #999/)).toBeVisible()
    await expect(list.getByText('Unsupported media type')).toBeVisible()

    // Failed row exposes a retry action; the duplicate row does not.
    await expect(page.getByRole('button', { name: 'Retry' })).toHaveCount(1)
  })

  test('completing a batch polls progress until a final status', async ({ page }) => {
    await loginAsAdmin(page)
    await createBatch(page)

    await page.route('**/api/admin/batches/42/images', (route) =>
      json(route, 201, {
        id: 200,
        batch_id: 42,
        original_name: 'ok.jpg',
        status: 'queued',
        tags: [],
        urls: { thumbnail: null, preview: null, original: '/api/media/images/200/original' },
        created_at: '2026-09-25T13:50:02Z',
      })
    )

    // Batch only leaves created/uploading once POST .../complete is called
    // (state-machines.md); polls before that must keep reporting `uploading`
    // or the "Mark upload finished" button would disappear out from under
    // the test. `complete` is its own endpoint (§26), not a POST to the
    // batch's own URL, so it needs its own route.
    let completeCalled = false
    let pollsAfterComplete = 0
    await page.route('**/api/admin/batches/42/complete', (route) => {
      completeCalled = true
      return json(route, 200, {
        id: 42,
        name: null,
        status: 'processing',
        progress: { total: 1, processed: 0, failed: 0 },
        created_at: '2026-09-25T13:43:55Z',
        updated_at: '2026-09-25T13:50:05Z',
        completed_at: null,
      })
    })
    await page.route('**/api/admin/batches/42', (route) => {
      if (!completeCalled) {
        return json(route, 200, {
          id: 42,
          name: null,
          status: 'uploading',
          progress: { total: 1, processed: 0, failed: 0 },
          created_at: '2026-09-25T13:43:55Z',
          updated_at: '2026-09-25T13:50:05Z',
          completed_at: null,
        })
      }
      pollsAfterComplete++
      const done = pollsAfterComplete >= 2
      return json(route, 200, {
        id: 42,
        name: null,
        status: done ? 'completed' : 'processing',
        progress: { total: 1, processed: done ? 1 : 0, failed: 0 },
        created_at: '2026-09-25T13:43:55Z',
        updated_at: '2026-09-25T13:50:05Z',
        completed_at: done ? '2026-09-25T13:51:00Z' : null,
      })
    })

    await gotoReady(page, '/uploader')
    await page.getByRole('button', { name: 'Create Batch' }).click()
    await expect(page.getByText('Batch #42')).toBeVisible()

    await page.locator('input[type="file"]').setInputFiles([{ name: 'ok.jpg', mimeType: 'image/jpeg', buffer: TINY_JPEG }])
    await expect(page.getByText('Uploaded, queued for processing')).toBeVisible()

    await page.getByRole('button', { name: /Mark upload finished/ }).click()
    await expect(page.getByTestId('batch-progress')).toBeVisible()
    await expect(page.getByText('Status: completed', { exact: true })).toBeVisible({ timeout: 10000 })
  })
})
