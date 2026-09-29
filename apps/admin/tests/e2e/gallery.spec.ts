import { test, expect, type Page } from '@playwright/test'
import { gotoReady, imageSummary, json, loginAsAdmin, pageOf } from './helpers'

// The date filters and the created date on the cards follow the admin's local calendar; pin the zone
// (UTC+3, no DST) and the locale so the expectations do not depend on the machine running the tests.
test.use({ timezoneId: 'Europe/Moscow', locale: 'ru-RU' })

function tag(id: number, name: string, extra: Record<string, unknown> = {}) {
  return {
    id,
    parent_id: null,
    name,
    slug: name.toLowerCase(),
    normalized_name: name.toLowerCase(),
    type: 'object',
    status: 'active',
    images_count: 3,
    aliases: [],
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...extra,
  }
}

const HELMET = tag(14, 'Каска')
const RIG = tag(87, 'Буровая')
const ENGINEER = tag(7, 'Инженер', { type: 'person' })
const OLD_LOGO = tag(31, 'Старый логотип', { status: 'archived' })

const ref = ({ id, name, type, status }: { id: number; name: string; type: string; status: string }) => ({
  id,
  name,
  type,
  status,
})

// 22:30 UTC on 25 Sep is already 26 Sep in Moscow.
const HELMET_PHOTO = imageSummary({
  id: 152,
  original_name: 'IMG_0001.jpg',
  status: 'ready',
  tags: [ref(HELMET), ref(ENGINEER)],
  created_at: '2026-09-25T22:30:00Z',
})
const QUEUED_PHOTO = imageSummary({
  id: 153,
  original_name: 'IMG_0002.jpg',
  status: 'queued',
  tags: [],
  urls: { thumbnail: null, preview: null, original: '/api/media/images/153/original' },
  created_at: '2026-09-24T09:00:00Z',
})
const RIG_PHOTO = imageSummary({
  id: 154,
  original_name: 'IMG_0003.jpg',
  status: 'ready',
  tags: [ref(RIG)],
  created_at: '2026-09-20T12:00:00Z',
})

/**
 * Mocks the endpoints the gallery reads. Image requests are recorded; `imagesFor` decides the answer
 * (by default every request gets all three photos).
 */
async function mockLibrary(
  page: Page,
  {
    images = [HELMET_PHOTO, QUEUED_PHOTO, RIG_PHOTO],
    imagesFor = () => images,
  }: { images?: unknown[]; imagesFor?: (request: URL) => unknown[] } = {}
) {
  const imageRequests: URL[] = []
  const tagRequests: URL[] = []

  await page.route(
    (url) => url.pathname === '/api/admin/images',
    (route) => {
      const url = new URL(route.request().url())
      imageRequests.push(url)
      return json(route, 200, pageOf(imagesFor(url)))
    }
  )
  await page.route(
    (url) => url.pathname === '/api/admin/tags',
    (route) => {
      const url = new URL(route.request().url())
      tagRequests.push(url)
      const q = url.searchParams.get('q')?.toLowerCase()
      const all = [HELMET, RIG, ENGINEER, OLD_LOGO]
      return json(route, 200, pageOf(q ? all.filter((t) => t.name.toLowerCase().includes(q)) : all, { per_page: 20 }))
    }
  )
  await page.route(
    (url) => url.pathname === '/api/admin/batches',
    (route) =>
      json(
        route,
        200,
        pageOf(
          [
            {
              id: 42,
              name: 'Site visit',
              status: 'completed',
              progress: { total: 3, processed: 3, failed: 0 },
              created_at: '2026-09-25T13:43:55Z',
              updated_at: '2026-09-25T14:00:00Z',
              completed_at: '2026-09-25T14:00:00Z',
            },
          ],
          { per_page: 100 }
        )
      )
  )

  return { imageRequests, tagRequests }
}

const lastOf = <T>(items: T[]) => items[items.length - 1]!

// Nuxt UI's SelectMenu trigger is a plain button (unlike USelect it is not exposed as a labelled combobox).
const tagFilter = (page: Page) => page.getByTestId('tag-filter')
const openTagFilter = (page: Page) => tagFilter(page).getByRole('button').click()

test.describe('gallery', () => {
  test('cards show thumbnail, status, tags and created date', async ({ page }) => {
    await loginAsAdmin(page)
    await mockLibrary(page)

    await gotoReady(page, '/gallery')
    const cards = page.getByTestId('gallery-card')
    await expect(cards).toHaveCount(3)

    const helmet = cards.filter({ hasText: 'IMG_0001.jpg' })
    await expect(helmet.getByRole('img', { name: 'IMG_0001.jpg' })).toHaveAttribute('src', '/api/media/images/152/thumbnail')
    await expect(helmet.getByText('Готово', { exact: true })).toBeVisible()
    await expect(helmet.getByTestId('gallery-card-tags')).toContainText('Каска')
    await expect(helmet.getByTestId('gallery-card-tags')).toContainText('Инженер')
    // 22:30 UTC on 25 Sep is the 26th on the admin's calendar.
    await expect(helmet.getByTestId('gallery-card-created')).toHaveText('26 сент. 2026 г.')
    await expect(helmet.getByTestId('gallery-card-created')).toHaveAttribute('datetime', '2026-09-25T22:30:00Z')

    const queued = cards.filter({ hasText: 'IMG_0002.jpg' })
    await expect(queued.getByText('В очереди', { exact: true })).toBeVisible()
    await expect(queued.getByText('Нет тегов')).toBeVisible()
    await expect(queued.getByTestId('gallery-card-created')).toHaveText('24 сент. 2026 г.')
    await expect(queued.getByRole('img')).toHaveCount(0)
  })

  test('long tag lists are cut after four tags with a counter', async ({ page }) => {
    await loginAsAdmin(page)
    const many = ['Каска', 'Буровая', 'Инженер', 'Вышка', 'Дорога', 'Зима'].map((name, i) =>
      ref(tag(200 + i, name))
    )
    await mockLibrary(page, { images: [imageSummary({ id: 160, tags: many })] })

    await gotoReady(page, '/gallery')
    const tags = page.getByTestId('gallery-card-tags')

    await expect(tags).toContainText('Каска')
    await expect(tags).toContainText('Вышка')
    await expect(tags).not.toContainText('Дорога')
    await expect(tags).toContainText('+2')
  })

  test('filtering by tags sends integer tag_ids and reloads the grid', async ({ page }) => {
    await loginAsAdmin(page)
    const { imageRequests } = await mockLibrary(page, {
      imagesFor: (url) => {
        const wanted = url.searchParams.getAll('tag_ids[]')
        if (wanted.length === 0) return [HELMET_PHOTO, QUEUED_PHOTO, RIG_PHOTO]
        // Same rule as the API: an image must carry all requested tags.
        return [HELMET_PHOTO, RIG_PHOTO].filter((photo) =>
          wanted.every((id) => (photo.tags as { id: number }[]).some((t) => t.id === Number(id)))
        )
      },
    })

    await gotoReady(page, '/gallery')
    await expect(page.getByTestId('gallery-card')).toHaveCount(3)
    // No tag filter, no tag_ids on the first load.
    expect(imageRequests[0]!.searchParams.has('tag_ids[]')).toBe(false)
    expect(imageRequests[0]!.searchParams.has('tag_ids')).toBe(false)

    await openTagFilter(page)
    await page.getByRole('option', { name: 'Каска (Объект)' }).click()

    await expect(page.getByTestId('gallery-card')).toHaveCount(1)
    await expect(page.getByTestId('gallery-card')).toContainText('IMG_0001.jpg')
    // An array of ids in the Laravel form, numbers only (no option objects, no labels).
    expect(lastOf(imageRequests).searchParams.getAll('tag_ids[]')).toEqual(['14'])
    expect(lastOf(imageRequests).searchParams.has('tag_ids')).toBe(false)

    // A second tag narrows the result further (AND): no photo has both.
    await page.getByRole('option', { name: 'Буровая (Объект)' }).click()
    await expect(page.getByText('Нет изображений, подходящих под фильтры.')).toBeVisible()
    expect(lastOf(imageRequests).searchParams.getAll('tag_ids[]')).toEqual(['14', '87'])
    for (const id of lastOf(imageRequests).searchParams.getAll('tag_ids[]')) {
      expect(id).toMatch(/^[1-9]\d*$/)
    }

    // Both tags show up as the selection, with their names.
    await expect(tagFilter(page)).toContainText('Каска')
    await expect(tagFilter(page)).toContainText('Буровая')
  })

  test('the tag menu searches on the server and keeps the selected tags', async ({ page }) => {
    await loginAsAdmin(page)
    const { imageRequests, tagRequests } = await mockLibrary(page)

    await gotoReady(page, '/gallery')
    await expect(page.getByTestId('gallery-card')).toHaveCount(3)
    // Filled once when the page opens, without a search term.
    expect(tagRequests[0]!.searchParams.has('q')).toBe(false)

    await openTagFilter(page)
    await page.getByRole('option', { name: 'Каска (Объект)' }).click()
    await expect.poll(() => lastOf(imageRequests).searchParams.getAll('tag_ids[]')).toEqual(['14'])

    // Typing asks the server; a match that the label alone would not show is still offered (ignore-filter).
    await page.getByPlaceholder('Поиск...').fill('вышк')
    await expect.poll(() => lastOf(tagRequests).searchParams.get('q')).toBe('вышк')
    await expect(page.getByRole('option', { name: 'Буровая (Объект)' })).toHaveCount(0)

    // The selected tag stays in the menu, checked, although the search result no longer contains it.
    await expect(page.getByRole('option', { name: 'Каска (Объект)' })).toBeVisible()
    await expect(tagFilter(page)).toContainText('Каска')
  })

  test('archived tags can be used as a filter and are labelled as such', async ({ page }) => {
    await loginAsAdmin(page)
    const { imageRequests } = await mockLibrary(page)

    await gotoReady(page, '/gallery')
    await openTagFilter(page)
    await page.getByRole('option', { name: 'Старый логотип (Объект, в архиве)' }).click()

    await expect.poll(() => lastOf(imageRequests).searchParams.getAll('tag_ids[]')).toEqual(['31'])
  })

  test('created date range is sent as RFC 3339 UTC bounds with an inclusive last day', async ({ page }) => {
    await loginAsAdmin(page)
    const { imageRequests } = await mockLibrary(page)

    await gotoReady(page, '/gallery')
    await expect(page.getByTestId('gallery-card')).toHaveCount(3)
    expect(imageRequests[0]!.searchParams.has('created_from')).toBe(false)
    expect(imageRequests[0]!.searchParams.has('created_to')).toBe(false)

    await page.getByLabel('Создано с').fill('2026-09-24')
    // 24 Sep 00:00 in Moscow (UTC+3) is 23 Sep 21:00 UTC; nothing else is sent for the end yet.
    await expect.poll(() => lastOf(imageRequests).searchParams.get('created_from')).toBe('2026-09-23T21:00:00Z')
    expect(lastOf(imageRequests).searchParams.has('created_to')).toBe(false)

    await page.getByLabel('Создано по').fill('2026-09-25')
    // The 25th is included: created_to (exclusive) is the start of the 26th, i.e. 25 Sep 21:00 UTC.
    await expect.poll(() => lastOf(imageRequests).searchParams.get('created_to')).toBe('2026-09-25T21:00:00Z')
    expect(lastOf(imageRequests).searchParams.get('created_from')).toBe('2026-09-23T21:00:00Z')

    // Emptying a field lifts that bound only.
    await page.getByLabel('Создано с').fill('')
    await expect.poll(() => lastOf(imageRequests).searchParams.has('created_from')).toBe(false)
    expect(lastOf(imageRequests).searchParams.get('created_to')).toBe('2026-09-25T21:00:00Z')
  })

  test('status, batch, tags and dates combine, and Reset filters clears them all', async ({ page }) => {
    await loginAsAdmin(page)
    const { imageRequests } = await mockLibrary(page)

    await gotoReady(page, '/gallery')
    await expect(page.getByTestId('gallery-card')).toHaveCount(3)
    await expect(page.getByRole('button', { name: 'Сбросить фильтры' })).toHaveCount(0)

    await page.getByRole('combobox', { name: 'Статус' }).click()
    await page.getByRole('option', { name: 'Готово' }).click()
    await page.getByRole('combobox', { name: 'Партия' }).click()
    await page.getByRole('option', { name: 'Site visit' }).click()
    await openTagFilter(page)
    await page.getByRole('option', { name: 'Каска (Объект)' }).click()
    await page.keyboard.press('Escape')
    await page.getByLabel('Создано с').fill('2026-09-24')

    await expect.poll(() => Object.fromEntries(lastOf(imageRequests).searchParams)).toMatchObject({
      status: 'ready',
      batch_id: '42',
      'tag_ids[]': '14',
      created_from: '2026-09-23T21:00:00Z',
      page: '1',
      per_page: '24',
    })

    await page.getByRole('button', { name: 'Сбросить фильтры' }).click()
    await expect.poll(() => Object.fromEntries(lastOf(imageRequests).searchParams)).toEqual({
      page: '1',
      per_page: '24',
    })
    await expect(page.getByRole('button', { name: 'Сбросить фильтры' })).toHaveCount(0)
    await expect(page.getByLabel('Создано с')).toHaveValue('')
    await expect(page.getByRole('combobox', { name: 'Статус' })).toContainText('Все')
    await expect(tagFilter(page)).not.toContainText('Каска')
  })

  test('only the answer to the latest filter change reaches the grid', async ({ page }) => {
    await loginAsAdmin(page)
    let releaseSlow: () => void = () => {}
    const slowAnswer = new Promise<void>((resolve) => (releaseSlow = resolve))
    await page.route(
      (url) => url.pathname === '/api/admin/images',
      async (route) => {
        const url = new URL(route.request().url())
        if (url.searchParams.get('status') === 'failed') {
          await slowAnswer // a slow answer to an outdated filter
          return json(route, 200, pageOf([imageSummary({ id: 1, original_name: 'OUTDATED.jpg', status: 'failed' })]))
        }
        if (url.searchParams.get('status') === 'ready') {
          return json(route, 200, pageOf([imageSummary({ id: 2, original_name: 'LATEST.jpg', status: 'ready' })]))
        }
        return json(route, 200, pageOf([HELMET_PHOTO]))
      }
    )
    await page.route((url) => url.pathname === '/api/admin/tags', (route) => json(route, 200, pageOf([])))
    await page.route((url) => url.pathname === '/api/admin/batches', (route) => json(route, 200, pageOf([])))

    await gotoReady(page, '/gallery')
    await expect(page.getByTestId('gallery-card')).toHaveCount(1)

    await page.getByRole('combobox', { name: 'Статус' }).click()
    await page.getByRole('option', { name: 'Ошибка' }).click()
    await page.getByRole('combobox', { name: 'Статус' }).click()
    await page.getByRole('option', { name: 'Готово' }).click()
    await expect(page.getByText('LATEST.jpg')).toBeVisible()

    releaseSlow()
    // Give the outdated answer time to arrive; it must not replace the newer one.
    await page.waitForTimeout(300)
    await expect(page.getByText('LATEST.jpg')).toBeVisible()
    await expect(page.getByText('OUTDATED.jpg')).toHaveCount(0)
  })

  test('AI search opens the search page in semantic mode and runs the query', async ({ page }) => {
    await loginAsAdmin(page)
    await mockLibrary(page)

    const searchRequests: unknown[] = []
    await page.route('**/api/search', (route) => {
      searchRequests.push(route.request().postDataJSON())
      return json(route, 200, {
        data: [{ ...HELMET_PHOTO, semantic_score: 0.873 }],
        meta: { page: 1, per_page: 24, total: 1, last_page: 1, mode: 'semantic' },
      })
    })

    await gotoReady(page, '/gallery')
    const submit = page.getByRole('button', { name: 'ИИ-поиск', exact: true })
    await expect(submit).toBeDisabled()

    await page.getByRole('textbox', { name: 'ИИ-поиск' }).fill('  мужчина в каске на промышленном объекте  ')
    await expect(submit).toBeEnabled()
    await submit.click()

    await page.waitForURL('**/search?**')
    const url = new URL(page.url())
    expect(url.pathname).toBe('/search')
    expect(url.searchParams.get('mode')).toBe('semantic')
    expect(url.searchParams.get('q')).toBe('мужчина в каске на промышленном объекте')

    // The search page shows the prefilled semantic form and the results of the query.
    await expect(page.getByTestId('search-results')).toBeVisible()
    await expect(page.getByTestId('search-results').getByText('IMG_0001.jpg')).toBeVisible()
    await expect(page.getByRole('combobox', { name: 'Режим поиска' })).toContainText('По смыслу запроса')
    await expect(page.getByPlaceholder(/рабочие в касках/)).toHaveValue('мужчина в каске на промышленном объекте')
    expect(searchRequests).toEqual([
      { mode: 'semantic', query: 'мужчина в каске на промышленном объекте', page: 1, per_page: 24, debug: false },
    ])
  })

  test('AI search can be submitted with Enter', async ({ page }) => {
    await loginAsAdmin(page)
    await mockLibrary(page)
    await page.route('**/api/search', (route) =>
      json(route, 200, { data: [], meta: { page: 1, per_page: 24, total: 0, last_page: 1, mode: 'semantic' } })
    )

    await gotoReady(page, '/gallery')
    await page.getByRole('textbox', { name: 'ИИ-поиск' }).fill('drilling rig at night')
    await page.getByRole('textbox', { name: 'ИИ-поиск' }).press('Enter')

    await page.waitForURL('**/search?**')
    expect(new URL(page.url()).searchParams.get('q')).toBe('drilling rig at night')
    await expect(page.getByText('Ничего не найдено.')).toBeVisible()
  })
})
