import { test, expect } from '@playwright/test'
import type { Locator, Page } from '@playwright/test'
import { apiError, gotoReady, json, loginAsAdmin } from './helpers'
import { makeTag, mockTagsApi } from './tags-api'
import type { MockTag } from './tags-api'

// Catalog used by most specs: a small hierarchy with aliases, a tag in use and an archived one.
function catalog(): MockTag[] {
  return [
    makeTag(1, 'Транспорт'),
    makeTag(2, 'Автомобиль', {
      parent_id: 1,
      images_count: 128,
      aliases: [
        { id: 10, alias: 'машина', locale: 'ru' },
        { id: 11, alias: 'car', locale: 'en' },
      ],
    }),
    makeTag(3, 'Самолёт', { parent_id: 1, images_count: 1 }),
    makeTag(4, 'Каска', { type: 'object' }),
    makeTag(5, 'Старый тег', { status: 'archived', images_count: 5 }),
  ]
}

async function openTags(page: Page, tags: MockTag[] = catalog()) {
  await loginAsAdmin(page)
  const api = await mockTagsApi(page, tags)
  await gotoReady(page, '/tags')
  await expect(page.getByTestId('tags-total')).toBeVisible()
  return api
}

function row(page: Page, name: string): Locator {
  return page.getByTestId('tag-row').filter({ has: page.getByText(name, { exact: true }) })
}

function editorOf(page: Page): Locator {
  return page.getByRole('region', { name: 'Редактор тега' })
}

test.describe('tags management', () => {
  test('shows the hierarchy with images_count, aliases and archived tags', async ({ page }) => {
    await openTags(page)

    await expect(page.getByTestId('tags-total')).toHaveText('Всего: 5 · в архиве: 1')

    // Roots are visible, children stay hidden until the parent is expanded.
    await expect(row(page, 'Транспорт')).toBeVisible()
    await expect(row(page, 'Автомобиль')).toHaveCount(0)
    await page.getByRole('button', { name: 'Развернуть Транспорт' }).click()

    const car = row(page, 'Автомобиль')
    await expect(car).toBeVisible()
    await expect(car.getByTestId('tag-images-count')).toHaveText('128 изображений')
    await expect(car.getByTestId('tag-aliases')).toHaveText('Синонимы: машина, car')
    await expect(row(page, 'Самолёт').getByTestId('tag-images-count')).toHaveText('1 изображение')
    await expect(row(page, 'Транспорт').getByTestId('tag-images-count')).toHaveText('0 изображений')

    // Archived tags are shown distinctly and can be filtered out.
    const archived = row(page, 'Старый тег')
    await expect(archived.getByText('В архиве')).toBeVisible()
    await expect(archived.getByTestId('tag-images-count')).toHaveText('5 изображений')
    await page.getByLabel('Показывать архивные').uncheck()
    await expect(archived).toHaveCount(0)
    await page.getByLabel('Показывать архивные').check()
    await expect(archived).toBeVisible()

    await page.getByRole('button', { name: 'Свернуть Транспорт' }).click()
    await expect(row(page, 'Автомобиль')).toHaveCount(0)
    await page.getByRole('button', { name: 'Развернуть все' }).click()
    await expect(row(page, 'Автомобиль')).toBeVisible()
    await page.getByRole('button', { name: 'Свернуть все' }).click()
    await expect(row(page, 'Автомобиль')).toHaveCount(0)
  })

  test('creates a tag under a parent and stays on it so aliases can be added', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Новый тег' }).click()
    const editor = editorOf(page)
    await expect(editor.getByRole('button', { name: 'Создать тег' })).toBeDisabled()
    // An untouched form doesn't nag; a name that was typed and cleared again says what is wrong.
    await expect(editor.getByText('Введите название тега.')).toHaveCount(0)
    await editor.getByLabel('Название', { exact: true }).fill('x')
    await editor.getByLabel('Название', { exact: true }).fill('')
    await expect(editor.getByText('Введите название тега.')).toBeVisible()

    await editor.getByLabel('Название', { exact: true }).fill('  Грузовик  ')
    await editor.getByRole('combobox', { name: 'Тип' }).click()
    await page.getByRole('option', { name: 'Человек' }).click()
    await editor.getByRole('combobox', { name: 'Родительский тег' }).click()
    await page.getByRole('option', { name: 'Транспорт (Объект)' }).click()
    await editor.getByRole('button', { name: 'Создать тег' }).click()

    await expect.poll(() => api.mutations('POST')).toHaveLength(1)
    expect(api.mutations('POST')[0]).toMatchObject({
      path: '/api/admin/tags',
      body: { name: 'Грузовик', type: 'person', parent_id: 1 },
    })

    await expect(page.getByTestId('tags-notice')).toContainText('Грузовик')
    // The parent got expanded so the new tag is visible, and the editor switched to editing it.
    await expect(row(page, 'Грузовик')).toBeVisible()
    await expect(editor.getByRole('heading', { name: 'Тег «Грузовик»' })).toBeVisible()
    await expect(editor.getByRole('button', { name: 'Сохранить' })).toBeDisabled()
    await expect(editor.getByText('Синонимов пока нет.')).toBeVisible()
  })

  test('adds a child from the row with the parent preselected', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Добавить дочерний тег к Транспорт' }).click()
    const editor = editorOf(page)
    await expect(editor.getByRole('combobox', { name: 'Родительский тег' })).toContainText('Транспорт')

    await editor.getByLabel('Название', { exact: true }).fill('Поезд')
    await editor.getByRole('button', { name: 'Создать тег' }).click()

    await expect.poll(() => api.mutations('POST')).toHaveLength(1)
    expect(api.mutations('POST')[0]?.body).toEqual({ name: 'Поезд', type: 'object', parent_id: 1 })
    await expect(row(page, 'Поезд')).toBeVisible()
  })

  test('renames a tag and moves it, sending only the changed fields', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Изменить Каска' }).click()
    const editor = editorOf(page)
    await expect(editor.getByRole('heading', { name: 'Тег «Каска»' })).toBeVisible()
    await expect(editor.getByRole('button', { name: 'Сохранить' })).toBeDisabled()

    await editor.getByLabel('Название', { exact: true }).fill('Защитная каска')
    await editor.getByRole('button', { name: 'Сохранить' }).click()
    await expect.poll(() => api.mutations('PATCH')).toHaveLength(1)
    expect(api.mutations('PATCH')[0]).toMatchObject({ path: '/api/admin/tags/4', body: { name: 'Защитная каска' } })
    expect(Object.keys(api.mutations('PATCH')[0]!.body!)).toEqual(['name'])
    await expect(row(page, 'Защитная каска')).toBeVisible()
    await expect(page.getByTestId('tags-notice')).toContainText('сохранены')

    await editor.getByRole('combobox', { name: 'Родительский тег' }).click()
    await page.getByRole('option', { name: 'Транспорт (Объект)' }).click()
    await editor.getByRole('button', { name: 'Сохранить' }).click()
    await expect.poll(() => api.mutations('PATCH')).toHaveLength(2)
    expect(api.mutations('PATCH')[1]?.body).toEqual({ parent_id: 1 })

    // Moved under Транспорт: it is now a child (the parent was expanded to show it).
    await expect(page.getByRole('button', { name: 'Свернуть Транспорт' })).toBeVisible()
    await expect(row(page, 'Защитная каска')).toBeVisible()
  })

  test('does not offer the tag itself, its descendants or archived tags as a parent', async ({ page }) => {
    await openTags(page)

    await page.getByRole('button', { name: 'Изменить Транспорт' }).click()
    await editorOf(page).getByRole('combobox', { name: 'Родительский тег' }).click()
    const options = page.getByRole('option')
    await expect(options.filter({ hasText: 'Каска' })).toHaveCount(1)
    await expect(options.filter({ hasText: 'Без родителя' })).toHaveCount(1)
    await expect(options.filter({ hasText: 'Автомобиль' })).toHaveCount(0)
    await expect(options.filter({ hasText: 'Самолёт' })).toHaveCount(0)
    await expect(options.filter({ hasText: 'Старый тег' })).toHaveCount(0)
    await expect(options.filter({ hasText: /^Транспорт/ })).toHaveCount(0)
  })

  test('creating a duplicate surfaces TAG_ALREADY_EXISTS and points at the existing tag', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Новый тег' }).click()
    const editor = editorOf(page)
    await editor.getByLabel('Название', { exact: true }).fill('каска')
    await editor.getByRole('button', { name: 'Создать тег' }).click()

    const alert = editor.getByRole('alert')
    await expect(alert).toContainText('Не удалось создать тег')
    await expect(alert).toContainText('Тег с таким названием и типом уже существует.')
    await expect(alert).toContainText('код запроса e2e-trace-id')
    await expect(alert).toContainText('существующий тег: Каска, Объект (#4)')
    expect(api.mutations('POST')).toHaveLength(1)
    // The form keeps what was typed so the admin can adjust it.
    await expect(editor.getByLabel('Название', { exact: true })).toHaveValue('каска')
  })

  test('an archived parent chosen from a stale form surfaces TAG_PARENT_INVALID', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Изменить Каска' }).click()
    const editor = editorOf(page)
    await editor.getByRole('combobox', { name: 'Родительский тег' }).click()
    await page.getByRole('option', { name: 'Транспорт (Объект)' }).click()

    // Somebody archives the parent meanwhile.
    api.tags.find(tag => tag.id === 1)!.status = 'archived'
    await editor.getByRole('button', { name: 'Сохранить' }).click()

    const alert = editor.getByRole('alert')
    await expect(alert).toContainText('Не удалось сохранить тег')
    await expect(alert).toContainText('Этот тег нельзя выбрать родителем.')
    await expect(alert).toContainText('код запроса e2e-trace-id')
  })

  test('shows server-side field errors from a 422 next to the field', async ({ page }) => {
    await openTags(page)
    await page.route('**/api/admin/tags', (route) => {
      if (route.request().method() !== 'POST') return route.fallback()
      return json(route, 422, {
        error: {
          code: 'VALIDATION_ERROR',
          message: 'The given data was invalid',
          details: { fields: { name: ['The name field must not be greater than 255 characters.'], parent_id: ['The parent id field must be at least 1.'] } },
          trace_id: 'e2e-trace-id',
        },
      })
    })

    await page.getByRole('button', { name: 'Новый тег' }).click()
    const editor = editorOf(page)
    await editor.getByLabel('Название', { exact: true }).fill('Что-то')
    await editor.getByRole('button', { name: 'Создать тег' }).click()

    // English server texts are replaced by a Russian hint under each field (Russian-only UI).
    await expect(editor.getByText('Недопустимое значение.')).toHaveCount(2)
    await expect(editor.getByText('The name field must not be greater than 255 characters.')).toHaveCount(0)
    await expect(editor.getByRole('alert')).toContainText('Проверьте введённые данные.')
  })

  test('archives and restores a tag from the tree', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'В архив Каска' }).click()
    await expect.poll(() => api.mutations('PATCH')).toHaveLength(1)
    expect(api.mutations('PATCH')[0]).toMatchObject({ path: '/api/admin/tags/4', body: { status: 'archived' } })
    await expect(row(page, 'Каска').getByText('В архиве')).toBeVisible()
    await expect(page.getByTestId('tags-total')).toHaveText('Всего: 5 · в архиве: 2')
    await expect(page.getByTestId('tags-notice')).toContainText('в архив')

    await page.getByRole('button', { name: 'Восстановить Каска' }).click()
    await expect.poll(() => api.mutations('PATCH')).toHaveLength(2)
    expect(api.mutations('PATCH')[1]?.body).toEqual({ status: 'active' })
    await expect(row(page, 'Каска').getByText('В архиве')).toHaveCount(0)

    // Archived tags cannot become parents, so they cannot get children.
    await expect(page.getByRole('button', { name: 'Добавить дочерний тег к Старый тег' })).toBeDisabled()
  })

  test('archives and restores a tag from the editor', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Изменить Каска' }).click()
    const editor = editorOf(page)
    await expect(editor.getByTestId('tag-status')).toHaveText('Активен')
    await editor.getByRole('button', { name: 'В архив', exact: true }).click()
    await expect(editor.getByTestId('tag-status')).toHaveText('В архиве')
    await editor.getByRole('button', { name: 'Восстановить', exact: true }).click()
    await expect(editor.getByTestId('tag-status')).toHaveText('Активен')
    expect(api.mutations('PATCH').map(r => r.body)).toEqual([{ status: 'archived' }, { status: 'active' }])
  })

  test('deleting an unused tag removes it after confirmation', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Удалить Каска' }).click()
    const confirmation = page.getByRole('region', { name: 'Подтверждение удаления' })
    await expect(confirmation.getByRole('heading', { name: 'Удалить «Каска»?' })).toBeVisible()
    // Nothing is sent until the admin confirms.
    expect(api.mutations('DELETE')).toHaveLength(0)

    await confirmation.getByRole('button', { name: 'Удалить тег' }).click()
    await expect.poll(() => api.mutations('DELETE')).toHaveLength(1)
    expect(api.mutations('DELETE')[0]?.path).toBe('/api/admin/tags/4')
    await expect(row(page, 'Каска')).toHaveCount(0)
    await expect(confirmation).toHaveCount(0)
    await expect(page.getByTestId('tags-notice')).toContainText('удалён')
    await expect(page.getByTestId('tags-total')).toHaveText('Всего: 4 · в архиве: 1')
  })

  test('deleting a tag that is in use archives it instead', async ({ page }) => {
    const api = await openTags(page)
    await page.getByRole('button', { name: 'Развернуть Транспорт' }).click()

    await page.getByRole('button', { name: 'Удалить Автомобиль' }).click()
    const confirmation = page.getByRole('region', { name: 'Подтверждение удаления' })
    await expect(confirmation).toContainText('привязан к 128 изображениям')
    await confirmation.getByRole('button', { name: 'Удалить тег' }).click()

    await expect.poll(() => api.mutations('DELETE')).toHaveLength(1)
    // 200 with the archived tag: it stays in the tree, marked as archived.
    await expect(row(page, 'Автомобиль').getByText('В архиве')).toBeVisible()
    await expect(page.getByTestId('tags-notice')).toContainText('перенесён в архив, а не удалён')
    await expect(confirmation).toHaveCount(0)
  })

  test('cancelling the delete confirmation sends nothing', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Удалить Каска' }).click()
    await page.getByRole('region', { name: 'Подтверждение удаления' }).getByRole('button', { name: 'Отмена' }).click()

    await expect(page.getByRole('region', { name: 'Подтверждение удаления' })).toHaveCount(0)
    await expect(row(page, 'Каска')).toBeVisible()
    expect(api.mutations()).toHaveLength(0)
  })

  test('adds and removes an alias by id', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Изменить Каска' }).click()
    const editor = editorOf(page)
    await expect(editor.getByText('Синонимов пока нет.')).toBeVisible()

    await editor.getByLabel('Синоним', { exact: true }).fill('  helmet ')
    await editor.getByLabel('Язык', { exact: true }).fill('en')
    await editor.getByRole('button', { name: 'Добавить синоним' }).click()

    await expect.poll(() => api.mutations('POST')).toHaveLength(1)
    expect(api.mutations('POST')[0]).toMatchObject({ path: '/api/admin/tags/4/aliases', body: { alias: 'helmet', locale: 'en' } })
    const aliases = editor.getByTestId('alias-list')
    await expect(aliases).toContainText('helmet')
    await expect(aliases).toContainText('en')
    await expect(row(page, 'Каска').getByTestId('tag-aliases')).toHaveText('Синонимы: helmet')
    await expect(page.getByTestId('tags-notice')).toContainText('helmet')
    // The form is ready for the next alias.
    await expect(editor.getByLabel('Синоним', { exact: true })).toHaveValue('')

    // A second alias without a locale sends no locale at all.
    await editor.getByLabel('Синоним', { exact: true }).fill('hard hat')
    await editor.getByRole('button', { name: 'Добавить синоним' }).click()
    await expect.poll(() => api.mutations('POST')).toHaveLength(2)
    expect(api.mutations('POST')[1]?.body).toEqual({ alias: 'hard hat' })

    // Removal addresses the alias by its numeric id, not by its text (ADR-0007).
    await editor.getByRole('button', { name: 'Удалить синоним helmet' }).click()
    await expect.poll(() => api.mutations('DELETE')).toHaveLength(1)
    expect(api.mutations('DELETE')[0]?.path).toMatch(/^\/api\/admin\/tags\/4\/aliases\/\d+$/)
    await expect(aliases).not.toContainText('helmet')
    await expect(aliases).toContainText('hard hat')
    await expect(row(page, 'Каска').getByTestId('tag-aliases')).toHaveText('Синонимы: hard hat')
  })

  test('removing an existing alias uses its id', async ({ page }) => {
    const api = await openTags(page)
    await page.getByRole('button', { name: 'Развернуть Транспорт' }).click()

    await page.getByRole('button', { name: 'Изменить Автомобиль' }).click()
    await editorOf(page).getByRole('button', { name: 'Удалить синоним машина' }).click()

    await expect.poll(() => api.mutations('DELETE')).toHaveLength(1)
    expect(api.mutations('DELETE')[0]?.path).toBe('/api/admin/tags/2/aliases/10')
    await expect(row(page, 'Автомобиль').getByTestId('tag-aliases')).toHaveText('Синонимы: car')
  })

  test('an alias owned by another tag surfaces ALIAS_ALREADY_EXISTS', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Изменить Каска' }).click()
    const editor = editorOf(page)
    // "car" already belongs to Автомобиль.
    await editor.getByLabel('Синоним', { exact: true }).fill('Car')
    await editor.getByRole('button', { name: 'Добавить синоним' }).click()

    const alert = editor.getByRole('alert')
    await expect(alert).toContainText('Не удалось добавить синоним')
    await expect(alert).toContainText('Такой синоним уже есть у другого тега.')
    await expect(alert).toContainText('код запроса e2e-trace-id')
    await expect(alert).toContainText('существующий тег: Автомобиль, Объект (#2)')
    await expect(editor.getByText('Синонимов пока нет.')).toBeVisible()
    expect(api.mutations('POST')).toHaveLength(1)

    // Dismissing clears the error; a free alias then works.
    await alert.getByRole('button', { name: 'Закрыть' }).click()
    await expect(alert).toHaveCount(0)
    await editor.getByLabel('Синоним', { exact: true }).fill('safety helmet')
    await editor.getByRole('button', { name: 'Добавить синоним' }).click()
    await expect(editor.getByTestId('alias-list')).toContainText('safety helmet')
  })

  test('validates the alias form before sending anything', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Изменить Каска' }).click()
    const editor = editorOf(page)

    await editor.getByRole('button', { name: 'Добавить синоним' }).click()
    await expect(editor.getByText('Введите синоним.')).toBeVisible()

    await editor.getByLabel('Синоним', { exact: true }).fill('helmet')
    await editor.getByLabel('Язык', { exact: true }).fill('English!')
    await editor.getByRole('button', { name: 'Добавить синоним' }).click()
    await expect(editor.getByText('Укажите код языка, например ru, en или pt-BR.')).toBeVisible()

    expect(api.mutations()).toHaveLength(0)
  })

  test('a tag deleted elsewhere is reported and the catalog reloaded', async ({ page }) => {
    const api = await openTags(page)

    // The tag disappears on the server while the page still shows it.
    api.tags.splice(api.tags.findIndex(tag => tag.id === 4), 1)
    await page.getByRole('button', { name: 'В архив Каска' }).click()

    await expect(page.getByRole('alert').filter({ hasText: 'Не удалось перенести тег в архив' })).toContainText('Тег не найден')
    await expect(row(page, 'Каска')).toHaveCount(0)
  })

  test('shows a load error and recovers with "Try again"', async ({ page }) => {
    await loginAsAdmin(page)
    const api = await mockTagsApi(page, catalog())
    let failNext = true
    await page.route('**/api/admin/tags?*', (route) => {
      if (failNext) {
        failNext = false
        return apiError(route, 500, 'INTERNAL_ERROR', 'Something went wrong')
      }
      return route.fallback()
    })

    await gotoReady(page, '/tags')
    await expect(page.getByRole('alert')).toContainText('Внутренняя ошибка сервера. Попробуйте ещё раз.')
    await page.getByRole('button', { name: 'Повторить' }).click()

    await expect(page.getByTestId('tags-total')).toHaveText('Всего: 5 · в архиве: 1')
    await expect(page.getByRole('alert')).toHaveCount(0)
    expect(api.requests.filter(r => r.method === 'GET')).toHaveLength(1)
  })

  test('shows an empty state when there are no tags yet', async ({ page }) => {
    await openTags(page, [])
    await expect(page.getByText('Тегов пока нет. Создайте первый.')).toBeVisible()
  })
})
