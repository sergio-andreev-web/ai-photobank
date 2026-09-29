import { test, expect, type Page } from '@playwright/test'
import { gotoReady, imageSummary, json, loginAsAdmin, pageOf } from './helpers'

const PHOTOS = [
  imageSummary({ id: 1, original_name: 'A.jpg', status: 'ready', tags: [{ id: 14, name: 'Каска', type: 'object', status: 'active' }] }),
  imageSummary({ id: 2, original_name: 'B.jpg', status: 'failed' }),
  imageSummary({ id: 3, original_name: 'C.jpg', status: 'ready' }),
]

function batch(id: number, status: string, progress: { total: number; processed: number; failed: number }, name: string | null = null) {
  return { id, name, status, progress, created_at: '2026-09-25T13:43:55Z', updated_at: '2026-09-25T14:00:00Z', completed_at: null }
}

const BATCHES = [
  batch(43, 'uploading', { total: 2, processed: 0, failed: 0 }, 'Незаконченная'),
  batch(42, 'completed_with_errors', { total: 20, processed: 20, failed: 2 }, 'Выезд на объект'),
]

async function mockGallery(page: Page) {
  const imageRequests: URL[] = []
  await page.route((url) => url.pathname === '/api/admin/images', (route) => {
    imageRequests.push(new URL(route.request().url()))
    return json(route, 200, pageOf(PHOTOS))
  })
  await page.route((url) => url.pathname === '/api/admin/tags', (route) => json(route, 200, pageOf([])))
  await page.route((url) => url.pathname === '/api/admin/batches', (route) => json(route, 200, pageOf(BATCHES, { per_page: 10 })))
  return { imageRequests }
}

test.describe('full-screen viewer', () => {
  test('opens from a card, pages with the arrow keys and buttons, closes with Escape', async ({ page }) => {
    await loginAsAdmin(page)
    await mockGallery(page)
    await gotoReady(page, '/gallery')

    await page.getByRole('button', { name: 'Просмотр B.jpg' }).click()
    const viewer = page.getByRole('dialog', { name: 'Просмотр: B.jpg' })
    await expect(viewer).toBeVisible()
    await expect(viewer.getByTestId('lightbox-counter')).toHaveText('2 / 3')
    await expect(viewer.getByRole('img', { name: 'B.jpg' })).toHaveAttribute('src', '/api/media/images/2/preview')

    await page.keyboard.press('ArrowRight')
    await expect(page.getByTestId('lightbox-name')).toHaveText('C.jpg')
    await expect(page.getByRole('button', { name: 'Следующее изображение' })).toBeDisabled()

    await page.getByRole('button', { name: 'Предыдущее изображение' }).click()
    await page.keyboard.press('ArrowLeft')
    await expect(page.getByTestId('lightbox-name')).toHaveText('A.jpg')
    await expect(page.getByTestId('lightbox')).toContainText('Каска')
    await expect(page.getByRole('button', { name: 'Предыдущее изображение' })).toBeDisabled()

    await expect(page.getByTestId('lightbox').getByRole('link', { name: 'Открыть карточку' })).toHaveAttribute('href', '/gallery/1')

    await page.keyboard.press('Escape')
    await expect(page.getByTestId('lightbox')).toHaveCount(0)
    await expect(page).toHaveURL(/\/gallery$/)
  })
})

test.describe('batch history', () => {
  test('lists recent batches and links to the gallery filtered by batch and by failures', async ({ page }) => {
    await loginAsAdmin(page)
    const { imageRequests } = await mockGallery(page)
    await gotoReady(page, '/uploader')

    const rows = page.getByTestId('batch-row')
    await expect(rows).toHaveCount(2)
    const done = rows.filter({ hasText: 'Выезд на объект' })
    await expect(done).toContainText('Завершена с ошибками')
    await expect(done).toContainText('20 / 20')
    await expect(done.getByRole('button', { name: 'Продолжить загрузку' })).toHaveCount(0)

    await done.getByRole('link', { name: 'С ошибками: 2' }).click()
    await page.waitForURL('**/gallery?batch_id=42&status=failed')
    await expect.poll(() => Object.fromEntries(imageRequests.at(-1)?.searchParams ?? [])).toMatchObject({
      batch_id: '42',
      status: 'failed',
    })
    await expect(page.getByRole('button', { name: 'С ошибками', exact: true })).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByRole('combobox', { name: 'Партия' })).toContainText('Выезд на объект')
  })

  test('an unfinished batch can be resumed to add files', async ({ page }) => {
    await loginAsAdmin(page)
    await mockGallery(page)
    await gotoReady(page, '/uploader')

    await page.getByTestId('batch-row').filter({ hasText: 'Незаконченная' }).getByRole('button', { name: 'Продолжить загрузку' }).click()
    await expect(page.getByText('Партия #43: Незаконченная')).toBeVisible()
    await expect(page.getByText('Перетащите изображения сюда или выберите файлы')).toBeVisible()
    // The open batch is not offered for resuming again.
    await expect(page.getByRole('button', { name: 'Продолжить загрузку' })).toHaveCount(0)
  })
})
