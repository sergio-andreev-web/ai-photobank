import { test, expect, type Page } from '@playwright/test'
import { apiError, gotoReady, imageSummary, json, loginAsAdmin, pageOf } from './helpers'

async function mockGallery(page: Page) {
  await page.route((url) => url.pathname === '/api/admin/images', (route) =>
    json(route, 200, pageOf([imageSummary({ id: 1, original_name: 'A.jpg' }), imageSummary({ id: 2, original_name: 'B.jpg' })])))
  await page.route((url) => url.pathname === '/api/admin/tags', (route) => json(route, 200, pageOf([])))
  await page.route((url) => url.pathname === '/api/admin/batches', (route) => json(route, 200, pageOf([])))
}

test.describe('download originals', () => {
  test('the selection downloads as one ZIP', async ({ page }) => {
    await loginAsAdmin(page)
    await mockGallery(page)
    const bodies: unknown[] = []
    await page.route('**/api/admin/images/download', (route) => {
      bodies.push(route.request().postDataJSON())
      return route.fulfill({
        status: 200,
        contentType: 'application/zip',
        headers: { 'Content-Disposition': 'attachment; filename=photobank-20260930-101500.zip' },
        body: Buffer.from('PK\x05\x06' + '\0'.repeat(18), 'binary'),
      })
    })
    await gotoReady(page, '/gallery')

    await page.getByRole('checkbox', { name: 'Выбрать все на странице' }).click()
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByTestId('bulk-bar').getByRole('button', { name: 'Скачать (2)' }).click(),
    ])

    expect(download.suggestedFilename()).toBe('photobank-20260930-101500.zip')
    expect(bodies).toEqual([{ image_ids: [1, 2] }])
    await expect(page.getByTestId('gallery-notice')).toContainText('Архив photobank-20260930-101500.zip скачивается.')
  })

  test('an error answer is shown in Russian', async ({ page }) => {
    await loginAsAdmin(page)
    await mockGallery(page)
    await page.route('**/api/admin/images/download', (route) => apiError(route, 404, 'MEDIA_NOT_AVAILABLE', 'Original is not available'))
    await gotoReady(page, '/gallery')

    await page.getByRole('checkbox', { name: 'Выбрать A.jpg' }).click()
    await page.getByTestId('bulk-bar').getByRole('button', { name: 'Скачать (1)' }).click()
    await expect(page.getByTestId('gallery-notice')).toContainText('Не удалось скачать: Файл изображения недоступен.')
  })

  test('the image page links the original for download', async ({ page }) => {
    await loginAsAdmin(page)
    await page.route((url) => url.pathname === '/api/admin/tags', (route) => json(route, 200, pageOf([])))
    await page.route('**/api/admin/images/5/similar*', (route) => json(route, 200, pageOf([])))
    await page.route('**/api/admin/images/5', (route) => json(route, 200, {
      ...imageSummary({ id: 5, original_name: 'Каска.jpg' }), updated_at: '', mime_type: 'image/jpeg', extension: 'jpg',
      width: 1, height: 1, file_size: 1, sha256: 'x', caption: null, caption_edited: false, ocr_text: null,
      error_code: null, error_message: null, image_tags: [], latest_analysis: null, processing_tasks: [],
    }))
    await gotoReady(page, '/gallery/5')

    const link = page.getByRole('link', { name: 'Скачать оригинал' })
    await expect(link).toHaveAttribute('href', '/api/media/images/5/original')
    await expect(link).toHaveAttribute('download', 'Каска.jpg')
  })
})
