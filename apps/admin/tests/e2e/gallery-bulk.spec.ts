import { test, expect, type Page } from '@playwright/test'
import { apiError, gotoReady, imageSummary, json, loginAsAdmin, pageOf } from './helpers'

const HELMET = { id: 14, name: 'Каска', type: 'object', status: 'active' }
const RIG = { id: 87, name: 'Буровая', type: 'object', status: 'active' }

function catalogTag(t: typeof HELMET) {
  return { ...t, parent_id: null, slug: t.name, normalized_name: t.name.toLowerCase(), images_count: 1, aliases: [], created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z' }
}

interface Call {
  method: string
  path: string
  body: unknown
}

/**
 * A small in-memory library: the grid, the tag catalog and every per-image operation the bulk bar
 * uses. `fail` makes chosen requests answer with an error instead.
 */
async function mockLibrary(page: Page, fail: (call: Call) => [number, string] | null = () => null) {
  const images = [
    imageSummary({ id: 1, original_name: 'A.jpg', status: 'ready', tags: [HELMET] }),
    imageSummary({ id: 2, original_name: 'B.jpg', status: 'failed', tags: [] }),
    imageSummary({ id: 3, original_name: 'C.jpg', status: 'ready', tags: [RIG] }),
    imageSummary({ id: 4, original_name: 'D.jpg', status: 'failed', tags: [HELMET] }),
    imageSummary({ id: 5, original_name: 'E.jpg', status: 'processing', tags: [] }),
  ]
  const calls: Call[] = []
  const listRequests: URL[] = []

  await page.route((url) => url.pathname === '/api/admin/images', (route) => {
    listRequests.push(new URL(route.request().url()))
    return json(route, 200, pageOf(images))
  })
  await page.route((url) => url.pathname === '/api/admin/tags', (route) => json(route, 200, pageOf([catalogTag(HELMET), catalogTag(RIG)])))
  await page.route((url) => url.pathname === '/api/admin/batches', (route) => json(route, 200, pageOf([])))
  await page.route(/\/api\/admin\/images\/\d+(\/.*)?$/, (route) => {
    const request = route.request()
    const path = new URL(request.url()).pathname
    const call = { method: request.method(), path, body: request.postDataJSON() }
    calls.push(call)
    const failure = fail(call)
    if (failure) return apiError(route, failure[0], failure[1], 'server text')

    const id = Number(path.split('/')[4])
    const image = images.find((it) => it.id === id)!
    if (call.method === 'DELETE' && /^\/api\/admin\/images\/\d+$/.test(path)) {
      images.splice(images.indexOf(image), 1)
      return route.fulfill({ status: 204 })
    }
    if (path.endsWith('/retry') || path.endsWith('/reprocess')) {
      image.status = 'queued'
      return json(route, 202, image)
    }
    if (call.method === 'POST' && path.endsWith('/tags')) {
      const added = [HELMET, RIG].find((t) => t.id === (call.body as { tag_id: number }).tag_id)!
      ;(image.tags as unknown[]).push(added)
      return json(route, 201, { tag: added, source: 'manual', confidence: null, locked: true, created_at: '', updated_at: '' })
    }
    if (call.method === 'DELETE') {
      const tagId = Number(path.split('/').pop())
      image.tags = (image.tags as { id: number }[]).filter((t) => t.id !== tagId) as never
      return route.fulfill({ status: 204 })
    }
    return route.fallback()
  })

  return { images, calls, listRequests }
}

const card = (page: Page, name: string) => page.getByTestId('gallery-card').filter({ hasText: name })
const check = (page: Page, name: string) => page.getByRole('checkbox', { name: `Выбрать ${name}` })
const bar = (page: Page) => page.getByTestId('bulk-bar')

test.describe('gallery bulk actions', () => {
  test('selects with checkboxes, Shift ranges and "select all", then clears', async ({ page }) => {
    await loginAsAdmin(page)
    await mockLibrary(page)
    await gotoReady(page, '/gallery')

    await expect(bar(page)).toHaveCount(0)
    await check(page, 'A.jpg').click()
    await expect(bar(page).getByTestId('bulk-count')).toHaveText('Выбрано: 1')

    await check(page, 'D.jpg').click({ modifiers: ['Shift'] })
    await expect(bar(page).getByTestId('bulk-count')).toHaveText('Выбрано: 4')
    await expect(card(page, 'E.jpg')).toHaveAttribute('data-selected', 'false')

    await page.getByRole('checkbox', { name: 'Выбрать все на странице' }).click()
    await expect(bar(page).getByTestId('bulk-count')).toHaveText('Выбрано: 5')

    await bar(page).getByRole('button', { name: 'Снять выделение' }).click()
    await expect(bar(page)).toHaveCount(0)
  })

  test('while something is selected, clicking a card selects it instead of opening it', async ({ page }) => {
    await loginAsAdmin(page)
    await mockLibrary(page)
    await gotoReady(page, '/gallery')

    await check(page, 'A.jpg').click()
    await card(page, 'B.jpg').getByText('B.jpg').click()
    await expect(page).toHaveURL(/\/gallery$/)
    await expect(bar(page).getByTestId('bulk-count')).toHaveText('Выбрано: 2')
  })

  test('deletes the selection after confirmation, reports a failure and keeps it selected', async ({ page }) => {
    await loginAsAdmin(page)
    const { calls } = await mockLibrary(page, (call) =>
      call.method === 'DELETE' && call.path === '/api/admin/images/3' ? [404, 'IMAGE_NOT_FOUND'] : null,
    )
    await gotoReady(page, '/gallery')

    await check(page, 'A.jpg').click()
    await check(page, 'C.jpg').click({ modifiers: ['Shift'] })
    await bar(page).getByRole('button', { name: 'Удалить', exact: true }).click()

    const confirmation = bar(page).getByRole('group', { name: 'Подтверждение удаления' })
    await expect(confirmation).toContainText('Удалить 3 изображения?')
    expect(calls).toEqual([])
    await confirmation.getByRole('button', { name: 'Удалить', exact: true }).click()

    const notice = page.getByTestId('gallery-notice')
    await expect(notice).toContainText('Удалено: 2 из 3.')
    await expect(notice).toContainText('Не удалось: 1 — Изображение не найдено.')
    expect(calls.map((c) => `${c.method} ${c.path}`).sort()).toEqual([
      'DELETE /api/admin/images/1',
      'DELETE /api/admin/images/2',
      'DELETE /api/admin/images/3',
    ])
    await expect(page.getByTestId('gallery-card')).toHaveCount(3)
    await expect(bar(page).getByTestId('bulk-count')).toHaveText('Выбрано: 1')
    await expect(card(page, 'C.jpg')).toHaveAttribute('data-selected', 'true')
  })

  test('retry and reprocess go only to images they apply to', async ({ page }) => {
    await loginAsAdmin(page)
    const { calls } = await mockLibrary(page)
    await gotoReady(page, '/gallery')

    await page.getByRole('checkbox', { name: 'Выбрать все на странице' }).click()
    await expect(bar(page).getByRole('button', { name: 'Повторить обработку (2)' })).toBeVisible()
    await expect(bar(page).getByRole('button', { name: 'Обработать заново (4)' })).toBeVisible()

    await bar(page).getByRole('button', { name: 'Повторить обработку (2)' }).click()
    await expect(page.getByTestId('gallery-notice')).toContainText('Отправлено на повторную обработку: 2 из 2.')
    await expect(page.getByTestId('gallery-notice')).toContainText('Пропущено: 3')
    expect(calls.map((c) => c.path).sort()).toEqual(['/api/admin/images/2/retry', '/api/admin/images/4/retry'])
    // Nothing failed, so the selection is cleared.
    await expect(bar(page)).toHaveCount(0)
  })

  test('adds a tag where it is missing and removes it where it is present', async ({ page }) => {
    await loginAsAdmin(page)
    const { calls } = await mockLibrary(page)
    await gotoReady(page, '/gallery')

    await check(page, 'A.jpg').click()
    await check(page, 'C.jpg').click({ modifiers: ['Shift'] })
    await bar(page).getByRole('button', { name: 'Добавить тег' }).click()
    await bar(page).getByRole('group', { name: 'Добавление тега' }).getByRole('button').first().click()
    await page.getByRole('option', { name: 'Каска (Объект)' }).click()
    await expect(bar(page)).toContainText('У 1 изображения этот тег уже есть.')
    await bar(page).getByRole('button', { name: 'Добавить к 2 изображениям' }).click()

    await expect(page.getByTestId('gallery-notice')).toContainText('Тег добавлен: 2 из 2.')
    expect(calls.map((c) => [c.path, c.body])).toEqual(
      expect.arrayContaining([
        ['/api/admin/images/2/tags', { tag_id: 14 }],
        ['/api/admin/images/3/tags', { tag_id: 14 }],
      ]),
    )
    expect(calls).toHaveLength(2)

    calls.length = 0
    await page.getByRole('checkbox', { name: 'Выбрать все на странице' }).click()
    await bar(page).getByRole('button', { name: 'Убрать тег' }).click()
    await bar(page).getByRole('combobox').click()
    await page.getByRole('option', { name: 'Каска (Объект)' }).click()
    await bar(page).getByRole('button', { name: 'Убрать у 4 изображений' }).click()
    await expect(page.getByTestId('gallery-notice')).toContainText('Тег убран: 4 из 4.')
    expect(calls.every((c) => c.method === 'DELETE' && c.path.endsWith('/tags/14'))).toBe(true)
    expect(calls).toHaveLength(4)
  })

  test('status tabs and sort order are sent to the API and clear the selection', async ({ page }) => {
    await loginAsAdmin(page)
    const { listRequests } = await mockLibrary(page)
    await gotoReady(page, '/gallery')

    await check(page, 'A.jpg').click()
    await page.getByRole('button', { name: 'С ошибками', exact: true }).click()
    await expect.poll(() => listRequests.at(-1)?.searchParams.get('status')).toBe('failed')
    await expect(bar(page)).toHaveCount(0)

    await page.getByRole('combobox', { name: 'Сортировка' }).click()
    await page.getByRole('option', { name: 'Сначала старые' }).click()
    await expect.poll(() => listRequests.at(-1)?.searchParams.get('sort')).toBe('created_at')
    expect(listRequests.at(-1)?.searchParams.get('status')).toBe('failed')
  })
})

test('every selected image shows a checked box, also after a Shift range', async ({ page }) => {
  await loginAsAdmin(page)
  await mockLibrary(page)
  await gotoReady(page, '/gallery')

  await check(page, 'A.jpg').click()
  await check(page, 'C.jpg').click({ modifiers: ['Shift'] })
  for (const name of ['A.jpg', 'B.jpg', 'C.jpg']) await expect(check(page, name)).toBeChecked()
  await expect(check(page, 'D.jpg')).not.toBeChecked()

  await check(page, 'B.jpg').click()
  await expect(check(page, 'B.jpg')).not.toBeChecked()
  await expect(check(page, 'A.jpg')).toBeChecked()
})
