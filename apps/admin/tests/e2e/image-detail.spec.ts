import { test, expect } from '@playwright/test'
import { gotoReady, json, loginAsAdmin } from './helpers'

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
