import { test, expect, type Page } from '@playwright/test'
import { apiError, gotoReady, json, loginAsAdmin, pageOf } from './helpers'

const HELMET = { id: 14, name: 'Каска', type: 'object', status: 'active' }

function imageWith(tags: { id: number; name: string; type: string; status: string }[]) {
  return {
    id: 152,
    batch_id: 42,
    original_name: 'IMG_0001.jpg',
    status: 'ready',
    urls: { thumbnail: null, preview: null, original: '/api/media/images/152/original' },
    created_at: '2026-09-25T13:50:00Z',
    updated_at: '2026-09-25T13:51:12Z',
    mime_type: 'image/jpeg',
    extension: 'jpg',
    width: 10,
    height: 10,
    file_size: 1000,
    sha256: 'x',
    caption: 'Two engineers wearing helmets.',
    ocr_text: null,
    error_code: null,
    error_message: null,
    image_tags: tags.map((tag) => ({ tag, source: 'manual', confidence: null, locked: true, created_at: '', updated_at: '' })),
    latest_analysis: {
      id: 1,
      vision_model: 'Qwen/Qwen3-VL-4B-Instruct',
      vision_model_version: null,
      embedding_model: 'google/siglip2-so400m-patch14-384',
      embedding_model_version: null,
      prompt_version: 'image-analysis-v2',
      caption: 'Two engineers wearing helmets.',
      concepts: [
        { type: 'object', label: 'hard hat', confidence: 0.97 },
        { type: 'scene', label: 'factory floor', confidence: 0.81 },
      ],
      ocr: [],
      processing_ms: 1000,
      created_at: '2026-09-25T13:51:12Z',
    },
    processing_tasks: [],
  }
}

async function mockImage(page: Page, { createFails = false } = {}) {
  const tags: { id: number; name: string; type: string; status: string }[] = []
  const calls: { path: string; body: unknown }[] = []
  await page.route('**/api/admin/images/152', (route) => json(route, 200, imageWith(tags)))
  await page.route((url) => url.pathname === '/api/admin/tags', (route) => {
    if (route.request().method() === 'POST') {
      calls.push({ path: '/api/admin/tags', body: route.request().postDataJSON() })
      if (createFails) return apiError(route, 409, 'TAG_ALREADY_EXISTS', 'exists', { existing_tag_id: 14 })
      return json(route, 201, { ...HELMET, id: 99, name: 'Каска защитная', parent_id: null, slug: 'k', normalized_name: 'k', images_count: 0, aliases: [], created_at: '', updated_at: '' })
    }
    const q = new URL(route.request().url()).searchParams.get('q')
    const catalog = [{ ...HELMET, parent_id: null, slug: 'kaska', normalized_name: 'каска', images_count: 3, aliases: [], created_at: '', updated_at: '' }]
    return json(route, 200, pageOf(q && !'каска'.includes(q.toLowerCase()) ? [] : catalog))
  })
  await page.route('**/api/admin/images/152/tags', (route) => {
    const body = route.request().postDataJSON() as { tag_id: number }
    calls.push({ path: '/api/admin/images/152/tags', body })
    tags.push(body.tag_id === 14 ? HELMET : { id: body.tag_id, name: 'Каска защитная', type: 'object', status: 'active' })
    return json(route, 201, { tag: HELMET, source: 'manual', confidence: null, locked: true, created_at: '', updated_at: '' })
  })
  return { calls }
}

test.describe('AI concepts on the image page', () => {
  test('a concept becomes a new tag under a Russian name and is attached', async ({ page }) => {
    await loginAsAdmin(page)
    const { calls } = await mockImage(page)
    await gotoReady(page, '/gallery/152')

    await page.getByTestId('concepts').getByRole('button', { name: /hard hat/ }).click()
    const tagger = page.getByTestId('concept-tagger')
    await expect(page.getByLabel('Название нового тега')).toHaveValue('hard hat')
    await page.getByLabel('Название нового тега').fill('Каска защитная')
    await tagger.getByRole('button', { name: 'Создать тег и добавить' }).click()

    await expect(page.getByTestId('image-notice')).toContainText('Тег «Каска защитная» добавлен к фото.')
    await expect(page.getByTestId('image-tags')).toContainText('Каска защитная')
    await expect(page.getByTestId('concept-tagger')).toHaveCount(0)
    expect(calls).toEqual([
      { path: '/api/admin/tags', body: { name: 'Каска защитная', type: 'object', parent_id: null } },
      { path: '/api/admin/images/152/tags', body: { tag_id: 99 } },
    ])
  })

  test('an existing tag is attached; a name clash reuses the existing tag', async ({ page }) => {
    await loginAsAdmin(page)
    const { calls } = await mockImage(page, { createFails: true })
    await gotoReady(page, '/gallery/152')

    await page.getByTestId('concepts').getByRole('button', { name: /hard hat/ }).click()
    await page.getByTestId('concept-existing').click()
    await page.getByRole('option', { name: 'Каска (Объект)' }).click()
    await page.getByRole('button', { name: 'Добавить к фото' }).click()
    await expect(page.getByTestId('image-notice')).toContainText('Тег «Каска» добавлен к фото.')
    expect(calls).toEqual([{ path: '/api/admin/images/152/tags', body: { tag_id: 14 } }])

    calls.length = 0
    await page.getByTestId('concepts').getByRole('button', { name: /factory floor/ }).click()
    await page.getByLabel('Название нового тега').fill('Каска')
    await page.getByRole('button', { name: 'Создать тег и добавить' }).click()
    // The notice from the first attach is still shown, so wait for the requests themselves.
    await expect.poll(() => calls).toEqual([
      { path: '/api/admin/tags', body: { name: 'Каска', type: 'scene', parent_id: null } },
      { path: '/api/admin/images/152/tags', body: { tag_id: 14 } },
    ])
  })
})
