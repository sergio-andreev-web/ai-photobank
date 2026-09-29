import { test, expect } from '@playwright/test'
import { gotoReady, imageSummary, json, loginAsAdmin, pageOf } from './helpers'

function detail(id: number, name: string, status = 'ready') {
  return {
    ...imageSummary({ id, original_name: name, status }),
    updated_at: '2026-09-25T13:51:12Z',
    mime_type: 'image/jpeg',
    extension: 'jpg',
    width: 10,
    height: 10,
    file_size: 1000,
    sha256: 'x',
    caption: null,
    ocr_text: null,
    error_code: null,
    error_message: null,
    image_tags: [],
    latest_analysis: null,
    processing_tasks: [],
  }
}

test.describe('similar images', () => {
  test('shows the neighbours and opens one, reloading the page for it', async ({ page }) => {
    await loginAsAdmin(page)
    await page.route((url) => url.pathname === '/api/admin/tags', (route) => json(route, 200, pageOf([])))
    await page.route(/\/api\/admin\/images\/\d+$/, (route) => {
      const id = Number(new URL(route.request().url()).pathname.split('/').pop())
      return json(route, 200, detail(id, id === 5 ? 'skater-1.jpg' : 'skater-2.jpg'))
    })
    const similarCalls: string[] = []
    await page.route(/\/api\/admin\/images\/\d+\/similar/, (route) => {
      const url = new URL(route.request().url())
      similarCalls.push(url.pathname + url.search)
      return url.pathname.includes('/5/')
        ? json(route, 200, pageOf([imageSummary({ id: 7, original_name: 'skater-2.jpg' }), imageSummary({ id: 6, original_name: 'skater-3.jpg' })], { per_page: 12 }))
        : json(route, 200, pageOf([], { per_page: 12 }))
    })

    await gotoReady(page, '/gallery/5')
    const block = page.getByTestId('similar-images')
    await expect(block.getByRole('heading')).toHaveText('Похожие фото (2)')
    await expect(block.getByRole('link')).toHaveCount(2)
    await expect(block.getByRole('link').first()).toHaveAttribute('href', '/gallery/7')

    await block.getByRole('link').first().click()
    await page.waitForURL('**/gallery/7')
    await expect(page.getByTestId('image-name')).toHaveText('skater-2.jpg')
    await expect(block).toContainText('Похожих фото не найдено.')
    expect(similarCalls).toEqual(['/api/admin/images/5/similar?per_page=12', '/api/admin/images/7/similar?per_page=12'])
  })

  test('an unprocessed image explains that neighbours come later', async ({ page }) => {
    await loginAsAdmin(page)
    await page.route((url) => url.pathname === '/api/admin/tags', (route) => json(route, 200, pageOf([])))
    await page.route('**/api/admin/images/8', (route) => json(route, 200, detail(8, 'new.jpg', 'queued')))
    await page.route('**/api/admin/images/8/similar*', (route) => json(route, 200, pageOf([], { per_page: 12 })))

    await gotoReady(page, '/gallery/8')
    await expect(page.getByTestId('similar-images')).toContainText('Появятся после обработки фото.')
  })
})
