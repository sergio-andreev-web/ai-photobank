import { test, expect } from '@playwright/test'
import { gotoReady, imageSummary, json, loginAsAdmin, pageOf } from './helpers'

function detail(caption: string | null, edited: boolean) {
  return {
    ...imageSummary({ id: 21, original_name: 'plate.jpg' }),
    updated_at: '2026-09-25T13:51:12Z', mime_type: 'image/jpeg', extension: 'jpg', width: 10, height: 10,
    file_size: 1000, sha256: 'x', caption, caption_edited: edited, ocr_text: 'ЖИЛИЩНАЯ ИНСПЕКЦИЯ',
    error_code: null, error_message: null, image_tags: [], latest_analysis: null, processing_tasks: [],
  }
}

test('the caption is fixed by hand and can be reverted to the AI caption', async ({ page }) => {
  await loginAsAdmin(page)
  await page.route((url) => url.pathname === '/api/admin/tags', (route) => json(route, 200, pageOf([])))
  await page.route('**/api/admin/images/21/similar*', (route) => json(route, 200, pageOf([])))
  const patches: unknown[] = []
  await page.route('**/api/admin/images/21', (route) => {
    if (route.request().method() === 'PATCH') {
      const body = route.request().postDataJSON() as { caption: string | null }
      patches.push(body)
      return json(route, 200, body.caption === null ? detail('Синяя табличка с гербом.', false) : detail(body.caption, true))
    }
    return json(route, 200, detail('Синяя табличка с гербом.', false))
  })

  await gotoReady(page, '/gallery/21')
  const block = page.getByTestId('caption-block')
  await block.getByRole('button', { name: 'Изменить подпись' }).click()
  await block.getByLabel('Подпись').fill('  Табличка Государственной жилищной инспекции Москвы.  ')
  await block.getByRole('button', { name: 'Сохранить подпись' }).click()

  await expect(block.getByTestId('caption-text')).toContainText('Табличка Государственной жилищной инспекции Москвы.')
  await expect(block).toContainText('исправлено вручную')
  await expect(page.getByTestId('image-notice')).toContainText('Повторная обработка её не изменит.')

  await block.getByRole('button', { name: 'Вернуть подпись ИИ' }).click()
  await expect(block.getByTestId('caption-text')).toHaveText(/Синяя табличка с гербом\./)
  await expect(block).not.toContainText('исправлено вручную')
  expect(patches).toEqual([{ caption: 'Табличка Государственной жилищной инспекции Москвы.' }, { caption: null }])
})
