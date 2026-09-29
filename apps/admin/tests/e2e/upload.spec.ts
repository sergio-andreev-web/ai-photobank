import { test, expect, type Locator, type Page } from '@playwright/test'
import { apiError, gotoReady, imageSummary, json, loginAsAdmin, pageOf } from './helpers'

const TINY_JPEG = Buffer.from(
  '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=',
  'base64'
)

async function createBatch(page: Page) {
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

/** The uploader polls the batch's images (§52); by default the batch has none. */
async function mockBatchImages(
  page: Page,
  answer: (request: URL, poll: number) => unknown | Promise<unknown> = () => pageOf([], { per_page: 100 })
) {
  const requests: URL[] = []
  await page.route(
    (url) => url.pathname === '/api/admin/images',
    async (route) => {
      const url = new URL(route.request().url())
      requests.push(url)
      return json(route, 200, await answer(url, requests.length))
    }
  )
  return requests
}

function rowFor(page: Page, fileName: string): Locator {
  return page
    .getByTestId('upload-list')
    .locator('[data-testid^="upload-row-"]')
    .filter({ has: page.getByText(fileName, { exact: true }) })
}

function stateOf(row: Locator): Locator {
  return row.locator('[data-testid^="upload-state-"]')
}

const BATCH_42 = {
  id: 42,
  name: null,
  progress: { total: 2, processed: 0, failed: 0 },
  created_at: '2026-09-25T13:43:55Z',
  updated_at: '2026-09-25T13:50:05Z',
  completed_at: null,
}

test.describe('batch upload', () => {
  test('uploads several files in parallel and reports success, duplicate and error per file', async ({ page }) => {
    await loginAsAdmin(page)
    await createBatch(page)
    await mockBatchImages(page)

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

    // Every file carries one of the per-file states of §52.
    await expect(stateOf(rowFor(page, 'ok.jpg'))).toHaveText('uploaded')
    await expect(stateOf(rowFor(page, 'dup.jpg'))).toHaveText('duplicate')
    await expect(stateOf(rowFor(page, 'bad.jpg'))).toHaveText('failed')

    // Failed row exposes a retry action; the duplicate row does not.
    await expect(page.getByRole('button', { name: 'Retry' })).toHaveCount(1)
  })

  test('completing a batch polls progress until a final status', async ({ page }) => {
    await loginAsAdmin(page)
    await createBatch(page)
    await mockBatchImages(page)

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
  test('shows every file moving from uploaded to processing to ready or failed, then stops polling', async ({ page }) => {
    await loginAsAdmin(page)
    await createBatch(page)

    // Two stored files: ok.jpg becomes image 300, broken.jpg image 301.
    await page.route('**/api/admin/batches/42/images', (route) => {
      const broken = (route.request().postData() || '').includes('broken.jpg')
      return json(
        route,
        201,
        imageSummary({
          id: broken ? 301 : 300,
          original_name: broken ? 'broken.jpg' : 'ok.jpg',
          status: 'queued',
          urls: { thumbnail: null, preview: null, original: `/api/media/images/${broken ? 301 : 300}/original` },
        })
      )
    })

    // The batch is `uploading` until "Mark upload finished"; afterwards everything is already final.
    let completed = false
    let batchPolls = 0
    await page.route('**/api/admin/batches/42/complete', (route) => {
      completed = true
      return json(route, 200, { ...BATCH_42, status: 'completed_with_errors', progress: { total: 2, processed: 2, failed: 1 } })
    })
    await page.route('**/api/admin/batches/42', (route) => {
      batchPolls++
      return json(
        route,
        200,
        completed
          ? { ...BATCH_42, status: 'completed_with_errors', progress: { total: 2, processed: 2, failed: 1 } }
          : { ...BATCH_42, status: 'uploading' }
      )
    })

    // Image list: the first poll sees the AI queue, the second (held back until the test has looked at
    // the first) sees the outcome.
    let releaseOutcome: () => void = () => {}
    const outcomeReleased = new Promise<void>((resolve) => (releaseOutcome = resolve))
    const imageRequests = await mockBatchImages(page, async (_url, poll) => {
      const status = (id: number) => {
        if (poll === 1) return id === 300 ? 'queued' : 'processing'
        return id === 300 ? 'ready' : 'failed'
      }
      if (poll > 1) await outcomeReleased
      return pageOf(
        [300, 301].map((id) => imageSummary({ id, status: status(id) })),
        { per_page: 100 }
      )
    })

    await gotoReady(page, '/uploader')
    await page.getByRole('button', { name: 'Create Batch' }).click()
    await expect(page.getByText('Batch #42')).toBeVisible()
    await page.locator('input[type="file"]').setInputFiles([
      { name: 'ok.jpg', mimeType: 'image/jpeg', buffer: TINY_JPEG },
      { name: 'broken.jpg', mimeType: 'image/jpeg', buffer: TINY_JPEG },
    ])

    const ok = rowFor(page, 'ok.jpg')
    const broken = rowFor(page, 'broken.jpg')

    // Straight after the 201 the files are only "uploaded".
    await expect(stateOf(ok)).toHaveText('uploaded')
    await expect(stateOf(broken)).toHaveText('uploaded')
    await expect(ok).toContainText('Uploaded, queued for processing')

    // First poll: the images are in the AI queue / being analysed.
    await expect(stateOf(ok)).toHaveText('processing', { timeout: 10_000 })
    await expect(stateOf(broken)).toHaveText('processing')
    await expect(ok).toContainText('Waiting for AI analysis')
    await expect(broken).toContainText('AI analysis in progress')
    await expect(page.getByTestId('processing-summary')).toContainText('2 in progress')
    expect(imageRequests[0]!.searchParams.get('batch_id')).toBe('42')
    expect(imageRequests[0]!.searchParams.get('per_page')).toBe('100')

    // Second poll: one image is ready, the other failed.
    releaseOutcome()
    await expect(stateOf(ok)).toHaveText('ready', { timeout: 10_000 })
    await expect(stateOf(broken)).toHaveText('failed')
    await expect(ok).toContainText('Analysis complete')
    await expect(ok.getByRole('link', { name: 'View image' })).toHaveAttribute('href', '/gallery/300')
    await expect(broken).toContainText('Processing failed')
    await expect(broken.getByRole('link', { name: 'See error details' })).toHaveAttribute('href', '/gallery/301')
    await expect(page.getByTestId('processing-summary')).toContainText('0 in progress')
    await expect(page.getByTestId('processing-summary')).toContainText('1 ready')
    await expect(page.getByTestId('processing-summary')).toContainText('1 failed')
    // The upload line of the row is still there next to the new state.
    await expect(ok).toContainText('Uploaded, queued for processing')

    // Everything is final: the batch keeps being polled (it is not complete yet) but the image list is not.
    const imagePollsWhenSettled = imageRequests.length
    const batchPollsWhenSettled = batchPolls
    await expect.poll(() => batchPolls, { timeout: 10_000 }).toBeGreaterThan(batchPollsWhenSettled)
    expect(imageRequests).toHaveLength(imagePollsWhenSettled)

    await page.getByRole('button', { name: /Mark upload finished/ }).click()
    await expect(page.getByText('Status: completed_with_errors', { exact: true })).toBeVisible()
    await expect(page.getByTestId('batch-progress')).toBeVisible()
  })

  test('keeps polling images after the batch is final until the last file is settled', async ({ page }) => {
    await loginAsAdmin(page)
    await createBatch(page)

    await page.route('**/api/admin/batches/42/images', (route) =>
      json(route, 201, imageSummary({ id: 400, original_name: 'slow.jpg', status: 'uploaded' }))
    )
    // The batch counters are already final, but the image list still lags behind for one poll.
    await page.route('**/api/admin/batches/42', (route) =>
      json(route, 200, { ...BATCH_42, status: 'completed', progress: { total: 1, processed: 1, failed: 0 } })
    )
    const imageRequests = await mockBatchImages(page, (_url, poll) =>
      pageOf([imageSummary({ id: 400, status: poll === 1 ? 'processing' : 'ready' })], { per_page: 100 })
    )

    await gotoReady(page, '/uploader')
    await page.getByRole('button', { name: 'Create Batch' }).click()
    await page.locator('input[type="file"]').setInputFiles([{ name: 'slow.jpg', mimeType: 'image/jpeg', buffer: TINY_JPEG }])

    const row = rowFor(page, 'slow.jpg')
    await expect(stateOf(row)).toHaveText('processing', { timeout: 10_000 })
    await expect(stateOf(row)).toHaveText('ready', { timeout: 10_000 })
    expect(imageRequests).toHaveLength(2)
  })
})
