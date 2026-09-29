import { test, expect, type Page } from '@playwright/test'
import { apiError, gotoReady, json, loginAsAdmin, pageOf } from './helpers'

function candidate(id: number, name: string, extra: Record<string, unknown> = {}) {
  return {
    id,
    display_name: name,
    normalized_name: name,
    type: 'object',
    status: 'pending',
    occurrences_count: 10 - id,
    closest_tag: null,
    similarity: null,
    sample_images: [{ id: 150 + id, thumbnail_url: `/api/media/images/${150 + id}/thumbnail`, confidence: 0.88 }],
    created_at: '2026-09-20T10:00:00Z',
    updated_at: '2026-09-25T13:51:12Z',
    resolved_at: null,
    resolved_by: null,
    resolved_tag_id: null,
    ...extra,
  }
}

const RIG = { id: 87, name: 'Буровая', type: 'object', status: 'active' }
const WHITE = { id: 90, name: 'белый', type: 'color', status: 'active' }

const PLATFORM = candidate(1, 'drilling platform', { closest_tag: RIG, similarity: 0.71 })
const HELMET = candidate(2, 'hard hat')
const COLOR = candidate(3, 'white', { type: 'color', closest_tag: WHITE, similarity: 0.93 })

function catalogTag(t: typeof RIG) {
  return { ...t, parent_id: null, slug: t.name, normalized_name: t.name, images_count: 12, aliases: [], created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z' }
}

interface Call {
  path: string
  body: unknown
}

/** A pending queue that shrinks as candidates are resolved; `fail` turns chosen calls into errors. */
async function mockQueue(page: Page, fail: (call: Call) => [number, string, Record<string, unknown>?] | null = () => null) {
  const queue = [PLATFORM, HELMET, COLOR]
  const calls: Call[] = []
  const listRequests: URL[] = []
  await page.route((url) => url.pathname === '/api/admin/tag-candidates', (route) => {
    listRequests.push(new URL(route.request().url()))
    return json(route, 200, pageOf(queue, { per_page: 30 }))
  })
  await page.route((url) => url.pathname === '/api/admin/tags', (route) => json(route, 200, pageOf([catalogTag(RIG), catalogTag(WHITE)])))
  await page.route(/\/api\/admin\/tag-candidates\/\d+\/(approve|merge|reject)$/, (route) => {
    const path = new URL(route.request().url()).pathname
    const call = { path, body: route.request().postDataJSON() }
    calls.push(call)
    const failure = fail(call)
    if (failure) return apiError(route, failure[0], failure[1], 'server text', failure[2] ?? {})
    const id = Number(path.split('/')[4])
    const index = queue.findIndex((c) => c.id === id)
    const [resolvedCandidate] = queue.splice(index, 1)
    return json(route, 200, { candidate: { ...resolvedCandidate, status: 'created' }, tag: RIG, attached_images_count: 3, alias_created: false })
  })
  return { calls, listRequests }
}

const card = (page: Page, name: string) => page.getByTestId('candidate-card').filter({ has: page.getByText(name, { exact: true }) })

test.describe('tag candidate moderation', () => {
  test('creates a tag under a Russian name and type, sending only the changed fields', async ({ page }) => {
    await loginAsAdmin(page)
    const { calls } = await mockQueue(page)
    await gotoReady(page, '/candidates')

    const helmet = card(page, 'hard hat')
    await helmet.getByLabel('Название тега').fill('Каска')
    await helmet.getByRole('combobox', { name: 'Тип' }).click()
    await page.getByRole('option', { name: 'Бренд' }).click()
    await helmet.getByRole('button', { name: 'Создать тег' }).click()

    await expect(page.getByTestId('candidates-notice')).toContainText('Тег «Каска» создан.')
    await expect(card(page, 'hard hat')).toHaveCount(0)
    expect(calls).toEqual([{ path: '/api/admin/tag-candidates/2/approve', body: { name: 'Каска', type: 'brand' } }])
  })

  test('an unchanged candidate is approved without a body', async ({ page }) => {
    await loginAsAdmin(page)
    const { calls } = await mockQueue(page)
    await gotoReady(page, '/candidates')

    await card(page, 'hard hat').getByRole('button', { name: 'Создать тег' }).click()
    await expect(card(page, 'hard hat')).toHaveCount(0)
    expect(calls).toEqual([{ path: '/api/admin/tag-candidates/2/approve', body: null }])
  })

  test('a strong match to an existing tag merges in one click; a weak one is only shown', async ({ page }) => {
    await loginAsAdmin(page)
    const { calls } = await mockQueue(page)
    await gotoReady(page, '/candidates')

    await expect(card(page, 'drilling platform').getByTestId('closest-tag')).toContainText('Ближайший существующий тег Буровая (сходство 71%)')
    await expect(card(page, 'drilling platform').getByRole('button', { name: /^Объединить с «/ })).toHaveCount(0)

    await card(page, 'white').getByRole('button', { name: 'Объединить с «белый»' }).click()
    await expect(card(page, 'white')).toHaveCount(0)
    expect(calls).toEqual([{ path: '/api/admin/tag-candidates/3/merge', body: { tag_id: 90, create_alias: true } }])
  })

  test('the merge form preselects the closest tag and sends the chosen target as an integer', async ({ page }) => {
    await loginAsAdmin(page)
    const { calls } = await mockQueue(page)
    await gotoReady(page, '/candidates')

    const platform = card(page, 'drilling platform')
    await platform.getByRole('button', { name: 'Объединить с…' }).click()
    await expect(platform.getByTestId('merge-target')).toContainText('Буровая (Объект)')
    await platform.getByRole('button', { name: 'Объединить', exact: true }).click()

    await expect(card(page, 'drilling platform')).toHaveCount(0)
    expect(calls).toEqual([{ path: '/api/admin/tag-candidates/1/merge', body: { tag_id: 87, create_alias: true } }])
  })

  test('TAG_ALREADY_EXISTS on create explains it and opens the merge form', async ({ page }) => {
    await loginAsAdmin(page)
    await mockQueue(page, (call) => (call.path.endsWith('/1/approve') ? [409, 'TAG_ALREADY_EXISTS', { existing_tag_id: 87 }] : null))
    await gotoReady(page, '/candidates')

    const platform = card(page, 'drilling platform')
    await platform.getByRole('button', { name: 'Создать тег' }).click()
    await expect(platform.getByRole('alert')).toHaveText('Такой тег уже есть — объедините кандидата с ним.')
    await expect(platform.getByTestId('merge-target')).toBeVisible()
  })

  test('bulk: reject the selection, merge strong matches, report failures on the cards', async ({ page }) => {
    await loginAsAdmin(page)
    const { calls } = await mockQueue(page, (call) => (call.path.endsWith('/2/reject') ? [500, 'INTERNAL_ERROR'] : null))
    await gotoReady(page, '/candidates')

    await page.getByRole('checkbox', { name: 'Выбрать все на странице' }).click()
    const bar = page.getByTestId('candidates-bulk-bar')
    await expect(bar.getByTestId('candidates-bulk-count')).toHaveText('Выбрано: 3')
    await expect(bar.getByRole('button', { name: 'Объединить с похожими (1)' })).toBeVisible()

    await bar.getByRole('button', { name: 'Отклонить (3)' }).click()
    await expect(page.getByTestId('candidates-notice')).toContainText('Отклонено: 2 из 3.')
    await expect(page.getByTestId('candidate-card')).toHaveCount(1)
    await expect(card(page, 'hard hat').getByRole('alert')).toHaveText('Внутренняя ошибка сервера. Попробуйте ещё раз.')
    // The failed one stays selected for another try.
    await expect(bar.getByTestId('candidates-bulk-count')).toHaveText('Выбрано: 1')
    expect(calls.map((c) => c.path).sort()).toEqual([
      '/api/admin/tag-candidates/1/reject',
      '/api/admin/tag-candidates/2/reject',
      '/api/admin/tag-candidates/3/reject',
    ])
  })

  test('keyboard: arrows move the cursor, R rejects, Enter creates, X selects', async ({ page }) => {
    await loginAsAdmin(page)
    const { calls } = await mockQueue(page)
    await gotoReady(page, '/candidates')
    await expect(page.getByTestId('candidate-card')).toHaveCount(3)

    await page.keyboard.press('ArrowDown')
    await page.keyboard.press('r')
    await expect(card(page, 'hard hat')).toHaveCount(0)
    // The cursor stays in place, now on the next candidate.
    await expect(card(page, 'white')).toHaveClass(/is-active/)

    await page.keyboard.press('x')
    await expect(page.getByTestId('candidates-bulk-count')).toHaveText('Выбрано: 1')

    await page.keyboard.press('ArrowUp')
    await page.keyboard.press('Enter')
    await expect(card(page, 'drilling platform')).toHaveCount(0)
    expect(calls.map((c) => c.path)).toEqual(['/api/admin/tag-candidates/2/reject', '/api/admin/tag-candidates/1/approve'])
  })

  test('type, search and sort go to the API; the most frequent come first by default', async ({ page }) => {
    await loginAsAdmin(page)
    const { listRequests } = await mockQueue(page)
    await gotoReady(page, '/candidates')

    await expect.poll(() => listRequests.at(-1)?.searchParams.get('sort')).toBe('-occurrences_count')
    await page.getByRole('combobox', { name: 'Тип' }).first().click()
    await page.getByRole('option', { name: 'Цвет' }).click()
    await page.getByLabel('Поиск').fill('wh')
    await page.getByRole('combobox', { name: 'Сортировка' }).click()
    await page.getByRole('option', { name: 'Сначала похожие на теги' }).click()

    await expect.poll(() => Object.fromEntries(listRequests.at(-1)?.searchParams ?? [])).toMatchObject({
      status: 'pending',
      type: 'color',
      q: 'wh',
      sort: '-similarity',
    })
  })

  test('a candidate resolved elsewhere leaves the queue with an explanation', async ({ page }) => {
    await loginAsAdmin(page)
    await mockQueue(page, (call) => (call.path.endsWith('/1/reject') ? [409, 'TAG_CANDIDATE_ALREADY_RESOLVED', { status: 'merged' }] : null))
    await gotoReady(page, '/candidates')

    await card(page, 'drilling platform').getByRole('button', { name: 'Отклонить' }).click()
    await expect(page.getByTestId('candidates-notice')).toContainText('По этому кандидату уже принято решение.')
    await expect(card(page, 'drilling platform')).toHaveCount(0)
  })
})
