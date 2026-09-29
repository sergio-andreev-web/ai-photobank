import { test, expect, type Page } from '@playwright/test'
import { apiError, gotoReady, imageSummary, json, loginAsAdmin, pageOf } from './helpers'

async function mockTrash(page: Page, failId: number | null = null) {
  const trash = [
    { ...imageSummary({ id: 11, original_name: 'a.jpg' }), deleted_at: '2026-09-29T10:00:00Z' },
    { ...imageSummary({ id: 12, original_name: 'b.jpg' }), deleted_at: '2026-09-28T10:00:00Z' },
    { ...imageSummary({ id: 13, original_name: 'c.jpg' }), deleted_at: '2026-09-27T10:00:00Z' },
  ]
  const restored: number[] = []
  await page.route((url) => url.pathname === '/api/admin/images/trash', (route) => json(route, 200, pageOf(trash, { per_page: 24 })))
  await page.route(/\/api\/admin\/images\/\d+\/restore$/, (route) => {
    const id = Number(new URL(route.request().url()).pathname.split('/')[4])
    if (id === failId) return apiError(route, 500, 'INTERNAL_ERROR', 'x')
    restored.push(id)
    trash.splice(trash.findIndex((image) => image.id === id), 1)
    return json(route, 200, { ...imageSummary({ id }), image_tags: [], processing_tasks: [], latest_analysis: null })
  })
  return { restored }
}

test.describe('trash', () => {
  test('lists deleted images and restores one', async ({ page }) => {
    await loginAsAdmin(page)
    const { restored } = await mockTrash(page)
    await gotoReady(page, '/trash')

    await expect(page.getByTestId('trash-card')).toHaveCount(3)
    await expect(page.getByText('В корзине: 3')).toBeVisible()
    await page.getByTestId('trash-card').filter({ hasText: 'b.jpg' }).getByRole('button', { name: 'Восстановить' }).click()

    await expect(page.getByTestId('trash-notice')).toContainText('Восстановлено: 1 изображение.')
    await expect(page.getByTestId('trash-card')).toHaveCount(2)
    expect(restored).toEqual([12])
  })

  test('restores the selection in bulk and keeps failures selected', async ({ page }) => {
    await loginAsAdmin(page)
    const { restored } = await mockTrash(page, 13)
    await gotoReady(page, '/trash')

    await page.getByRole('checkbox', { name: 'Выбрать все на странице' }).click()
    await page.getByTestId('trash-bulk-bar').getByRole('button', { name: 'Восстановить (3)' }).click()

    await expect(page.getByTestId('trash-notice')).toContainText('Восстановлено: 2 изображения.')
    await expect(page.getByTestId('trash-notice')).toContainText('Не удалось: 1')
    await expect(page.getByTestId('trash-card')).toHaveCount(1)
    await expect(page.getByRole('checkbox', { name: 'Выбрать c.jpg' })).toBeChecked()
    expect(restored.sort()).toEqual([11, 12])
  })

  test('an empty trash says so', async ({ page }) => {
    await loginAsAdmin(page)
    await page.route((url) => url.pathname === '/api/admin/images/trash', (route) => json(route, 200, pageOf([], { per_page: 24 })))
    await gotoReady(page, '/trash')
    await expect(page.getByText('Корзина пуста.')).toBeVisible()
  })
})
