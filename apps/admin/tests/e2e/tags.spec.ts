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
  return page.getByRole('region', { name: 'Tag editor' })
}

test.describe('tags management', () => {
  test('shows the hierarchy with images_count, aliases and archived tags', async ({ page }) => {
    await openTags(page)

    await expect(page.getByTestId('tags-total')).toHaveText('5 total · 1 archived')

    // Roots are visible, children stay hidden until the parent is expanded.
    await expect(row(page, 'Транспорт')).toBeVisible()
    await expect(row(page, 'Автомобиль')).toHaveCount(0)
    await page.getByRole('button', { name: 'Expand Транспорт' }).click()

    const car = row(page, 'Автомобиль')
    await expect(car).toBeVisible()
    await expect(car.getByTestId('tag-images-count')).toHaveText('128 images')
    await expect(car.getByTestId('tag-aliases')).toHaveText('Aliases: машина, car')
    await expect(row(page, 'Самолёт').getByTestId('tag-images-count')).toHaveText('1 image')
    await expect(row(page, 'Транспорт').getByTestId('tag-images-count')).toHaveText('0 images')

    // Archived tags are shown distinctly and can be filtered out.
    const archived = row(page, 'Старый тег')
    await expect(archived.getByText('Archived')).toBeVisible()
    await expect(archived.getByTestId('tag-images-count')).toHaveText('5 images')
    await page.getByLabel('Show archived').uncheck()
    await expect(archived).toHaveCount(0)
    await page.getByLabel('Show archived').check()
    await expect(archived).toBeVisible()

    await page.getByRole('button', { name: 'Collapse Транспорт' }).click()
    await expect(row(page, 'Автомобиль')).toHaveCount(0)
    await page.getByRole('button', { name: 'Expand all' }).click()
    await expect(row(page, 'Автомобиль')).toBeVisible()
    await page.getByRole('button', { name: 'Collapse all' }).click()
    await expect(row(page, 'Автомобиль')).toHaveCount(0)
  })

  test('creates a tag under a parent and stays on it so aliases can be added', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'New Tag' }).click()
    const editor = editorOf(page)
    await expect(editor.getByRole('button', { name: 'Create Tag' })).toBeDisabled()
    // An untouched form doesn't nag; a name that was typed and cleared again says what is wrong.
    await expect(editor.getByText('Enter a tag name.')).toHaveCount(0)
    await editor.getByLabel('Name', { exact: true }).fill('x')
    await editor.getByLabel('Name', { exact: true }).fill('')
    await expect(editor.getByText('Enter a tag name.')).toBeVisible()

    await editor.getByLabel('Name', { exact: true }).fill('  Грузовик  ')
    await editor.getByRole('combobox', { name: 'Type' }).click()
    await page.getByRole('option', { name: 'Person' }).click()
    await editor.getByRole('combobox', { name: 'Parent tag' }).click()
    await page.getByRole('option', { name: 'Транспорт (object)' }).click()
    await editor.getByRole('button', { name: 'Create Tag' }).click()

    await expect.poll(() => api.mutations('POST')).toHaveLength(1)
    expect(api.mutations('POST')[0]).toMatchObject({
      path: '/api/admin/tags',
      body: { name: 'Грузовик', type: 'person', parent_id: 1 },
    })

    await expect(page.getByTestId('tags-notice')).toContainText('Грузовик')
    // The parent got expanded so the new tag is visible, and the editor switched to editing it.
    await expect(row(page, 'Грузовик')).toBeVisible()
    await expect(editor.getByRole('heading', { name: 'Edit Грузовик' })).toBeVisible()
    await expect(editor.getByRole('button', { name: 'Save changes' })).toBeDisabled()
    await expect(editor.getByText('No aliases yet.')).toBeVisible()
  })

  test('adds a child from the row with the parent preselected', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Add child to Транспорт' }).click()
    const editor = editorOf(page)
    await expect(editor.getByRole('combobox', { name: 'Parent tag' })).toContainText('Транспорт')

    await editor.getByLabel('Name', { exact: true }).fill('Поезд')
    await editor.getByRole('button', { name: 'Create Tag' }).click()

    await expect.poll(() => api.mutations('POST')).toHaveLength(1)
    expect(api.mutations('POST')[0]?.body).toEqual({ name: 'Поезд', type: 'object', parent_id: 1 })
    await expect(row(page, 'Поезд')).toBeVisible()
  })

  test('renames a tag and moves it, sending only the changed fields', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Edit Каска' }).click()
    const editor = editorOf(page)
    await expect(editor.getByRole('heading', { name: 'Edit Каска' })).toBeVisible()
    await expect(editor.getByRole('button', { name: 'Save changes' })).toBeDisabled()

    await editor.getByLabel('Name', { exact: true }).fill('Защитная каска')
    await editor.getByRole('button', { name: 'Save changes' }).click()
    await expect.poll(() => api.mutations('PATCH')).toHaveLength(1)
    expect(api.mutations('PATCH')[0]).toMatchObject({ path: '/api/admin/tags/4', body: { name: 'Защитная каска' } })
    expect(Object.keys(api.mutations('PATCH')[0]!.body!)).toEqual(['name'])
    await expect(row(page, 'Защитная каска')).toBeVisible()
    await expect(page.getByTestId('tags-notice')).toContainText('Saved changes')

    await editor.getByRole('combobox', { name: 'Parent tag' }).click()
    await page.getByRole('option', { name: 'Транспорт (object)' }).click()
    await editor.getByRole('button', { name: 'Save changes' }).click()
    await expect.poll(() => api.mutations('PATCH')).toHaveLength(2)
    expect(api.mutations('PATCH')[1]?.body).toEqual({ parent_id: 1 })

    // Moved under Транспорт: it is now a child (the parent was expanded to show it).
    await expect(page.getByRole('button', { name: 'Collapse Транспорт' })).toBeVisible()
    await expect(row(page, 'Защитная каска')).toBeVisible()
  })

  test('does not offer the tag itself, its descendants or archived tags as a parent', async ({ page }) => {
    await openTags(page)

    await page.getByRole('button', { name: 'Edit Транспорт' }).click()
    await editorOf(page).getByRole('combobox', { name: 'Parent tag' }).click()
    const options = page.getByRole('option')
    await expect(options.filter({ hasText: 'Каска' })).toHaveCount(1)
    await expect(options.filter({ hasText: 'No parent' })).toHaveCount(1)
    await expect(options.filter({ hasText: 'Автомобиль' })).toHaveCount(0)
    await expect(options.filter({ hasText: 'Самолёт' })).toHaveCount(0)
    await expect(options.filter({ hasText: 'Старый тег' })).toHaveCount(0)
    await expect(options.filter({ hasText: /^Транспорт/ })).toHaveCount(0)
  })

  test('creating a duplicate surfaces TAG_ALREADY_EXISTS and points at the existing tag', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'New Tag' }).click()
    const editor = editorOf(page)
    await editor.getByLabel('Name', { exact: true }).fill('каска')
    await editor.getByRole('button', { name: 'Create Tag' }).click()

    const alert = editor.getByRole('alert')
    await expect(alert).toContainText('Could not create the tag')
    await expect(alert).toContainText('Tag already exists')
    await expect(alert).toContainText('TAG_ALREADY_EXISTS')
    await expect(alert).toContainText('existing tag: Каска, object (#4)')
    expect(api.mutations('POST')).toHaveLength(1)
    // The form keeps what was typed so the admin can adjust it.
    await expect(editor.getByLabel('Name', { exact: true })).toHaveValue('каска')
  })

  test('an archived parent chosen from a stale form surfaces TAG_PARENT_INVALID', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Edit Каска' }).click()
    const editor = editorOf(page)
    await editor.getByRole('combobox', { name: 'Parent tag' }).click()
    await page.getByRole('option', { name: 'Транспорт (object)' }).click()

    // Somebody archives the parent meanwhile.
    api.tags.find(tag => tag.id === 1)!.status = 'archived'
    await editor.getByRole('button', { name: 'Save changes' }).click()

    const alert = editor.getByRole('alert')
    await expect(alert).toContainText('Could not save the tag')
    await expect(alert).toContainText('Parent tag is archived')
    await expect(alert).toContainText('TAG_PARENT_INVALID')
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

    await page.getByRole('button', { name: 'New Tag' }).click()
    const editor = editorOf(page)
    await editor.getByLabel('Name', { exact: true }).fill('Что-то')
    await editor.getByRole('button', { name: 'Create Tag' }).click()

    await expect(editor.getByText('The name field must not be greater than 255 characters.')).toBeVisible()
    await expect(editor.getByText('The parent id field must be at least 1.')).toBeVisible()
    await expect(editor.getByRole('alert')).toContainText('The given data was invalid')
  })

  test('archives and restores a tag from the tree', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Archive Каска' }).click()
    await expect.poll(() => api.mutations('PATCH')).toHaveLength(1)
    expect(api.mutations('PATCH')[0]).toMatchObject({ path: '/api/admin/tags/4', body: { status: 'archived' } })
    await expect(row(page, 'Каска').getByText('Archived')).toBeVisible()
    await expect(page.getByTestId('tags-total')).toHaveText('5 total · 2 archived')
    await expect(page.getByTestId('tags-notice')).toContainText('archived')

    await page.getByRole('button', { name: 'Restore Каска' }).click()
    await expect.poll(() => api.mutations('PATCH')).toHaveLength(2)
    expect(api.mutations('PATCH')[1]?.body).toEqual({ status: 'active' })
    await expect(row(page, 'Каска').getByText('Archived')).toHaveCount(0)

    // Archived tags cannot become parents, so they cannot get children.
    await expect(page.getByRole('button', { name: 'Add child to Старый тег' })).toBeDisabled()
  })

  test('archives and restores a tag from the editor', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Edit Каска' }).click()
    const editor = editorOf(page)
    await expect(editor.getByTestId('tag-status')).toHaveText('Active')
    await editor.getByRole('button', { name: 'Archive tag' }).click()
    await expect(editor.getByTestId('tag-status')).toHaveText('Archived')
    await editor.getByRole('button', { name: 'Restore tag' }).click()
    await expect(editor.getByTestId('tag-status')).toHaveText('Active')
    expect(api.mutations('PATCH').map(r => r.body)).toEqual([{ status: 'archived' }, { status: 'active' }])
  })

  test('deleting an unused tag removes it after confirmation', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Delete Каска' }).click()
    const confirmation = page.getByRole('region', { name: 'Delete confirmation' })
    await expect(confirmation.getByRole('heading', { name: 'Delete Каска?' })).toBeVisible()
    // Nothing is sent until the admin confirms.
    expect(api.mutations('DELETE')).toHaveLength(0)

    await confirmation.getByRole('button', { name: 'Delete tag' }).click()
    await expect.poll(() => api.mutations('DELETE')).toHaveLength(1)
    expect(api.mutations('DELETE')[0]?.path).toBe('/api/admin/tags/4')
    await expect(row(page, 'Каска')).toHaveCount(0)
    await expect(confirmation).toHaveCount(0)
    await expect(page.getByTestId('tags-notice')).toContainText('deleted')
    await expect(page.getByTestId('tags-total')).toHaveText('4 total · 1 archived')
  })

  test('deleting a tag that is in use archives it instead', async ({ page }) => {
    const api = await openTags(page)
    await page.getByRole('button', { name: 'Expand Транспорт' }).click()

    await page.getByRole('button', { name: 'Delete Автомобиль' }).click()
    const confirmation = page.getByRole('region', { name: 'Delete confirmation' })
    await expect(confirmation).toContainText('linked to 128 images')
    await confirmation.getByRole('button', { name: 'Delete tag' }).click()

    await expect.poll(() => api.mutations('DELETE')).toHaveLength(1)
    // 200 with the archived tag: it stays in the tree, marked as archived.
    await expect(row(page, 'Автомобиль').getByText('Archived')).toBeVisible()
    await expect(page.getByTestId('tags-notice')).toContainText('archived instead of deleted')
    await expect(confirmation).toHaveCount(0)
  })

  test('cancelling the delete confirmation sends nothing', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Delete Каска' }).click()
    await page.getByRole('region', { name: 'Delete confirmation' }).getByRole('button', { name: 'Cancel' }).click()

    await expect(page.getByRole('region', { name: 'Delete confirmation' })).toHaveCount(0)
    await expect(row(page, 'Каска')).toBeVisible()
    expect(api.mutations()).toHaveLength(0)
  })

  test('adds and removes an alias by id', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Edit Каска' }).click()
    const editor = editorOf(page)
    await expect(editor.getByText('No aliases yet.')).toBeVisible()

    await editor.getByLabel('Alias', { exact: true }).fill('  helmet ')
    await editor.getByLabel('Locale', { exact: true }).fill('en')
    await editor.getByRole('button', { name: 'Add alias' }).click()

    await expect.poll(() => api.mutations('POST')).toHaveLength(1)
    expect(api.mutations('POST')[0]).toMatchObject({ path: '/api/admin/tags/4/aliases', body: { alias: 'helmet', locale: 'en' } })
    const aliases = editor.getByTestId('alias-list')
    await expect(aliases).toContainText('helmet')
    await expect(aliases).toContainText('en')
    await expect(row(page, 'Каска').getByTestId('tag-aliases')).toHaveText('Aliases: helmet')
    await expect(page.getByTestId('tags-notice')).toContainText('helmet')
    // The form is ready for the next alias.
    await expect(editor.getByLabel('Alias', { exact: true })).toHaveValue('')

    // A second alias without a locale sends no locale at all.
    await editor.getByLabel('Alias', { exact: true }).fill('hard hat')
    await editor.getByRole('button', { name: 'Add alias' }).click()
    await expect.poll(() => api.mutations('POST')).toHaveLength(2)
    expect(api.mutations('POST')[1]?.body).toEqual({ alias: 'hard hat' })

    // Removal addresses the alias by its numeric id, not by its text (ADR-0007).
    await editor.getByRole('button', { name: 'Remove alias helmet' }).click()
    await expect.poll(() => api.mutations('DELETE')).toHaveLength(1)
    expect(api.mutations('DELETE')[0]?.path).toMatch(/^\/api\/admin\/tags\/4\/aliases\/\d+$/)
    await expect(aliases).not.toContainText('helmet')
    await expect(aliases).toContainText('hard hat')
    await expect(row(page, 'Каска').getByTestId('tag-aliases')).toHaveText('Aliases: hard hat')
  })

  test('removing an existing alias uses its id', async ({ page }) => {
    const api = await openTags(page)
    await page.getByRole('button', { name: 'Expand Транспорт' }).click()

    await page.getByRole('button', { name: 'Edit Автомобиль' }).click()
    await editorOf(page).getByRole('button', { name: 'Remove alias машина' }).click()

    await expect.poll(() => api.mutations('DELETE')).toHaveLength(1)
    expect(api.mutations('DELETE')[0]?.path).toBe('/api/admin/tags/2/aliases/10')
    await expect(row(page, 'Автомобиль').getByTestId('tag-aliases')).toHaveText('Aliases: car')
  })

  test('an alias owned by another tag surfaces ALIAS_ALREADY_EXISTS', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Edit Каска' }).click()
    const editor = editorOf(page)
    // "car" already belongs to Автомобиль.
    await editor.getByLabel('Alias', { exact: true }).fill('Car')
    await editor.getByRole('button', { name: 'Add alias' }).click()

    const alert = editor.getByRole('alert')
    await expect(alert).toContainText('Could not add the alias')
    await expect(alert).toContainText('Alias already belongs to another tag')
    await expect(alert).toContainText('ALIAS_ALREADY_EXISTS')
    await expect(alert).toContainText('existing tag: Автомобиль, object (#2)')
    await expect(editor.getByText('No aliases yet.')).toBeVisible()
    expect(api.mutations('POST')).toHaveLength(1)

    // Dismissing clears the error; a free alias then works.
    await alert.getByRole('button', { name: 'Dismiss' }).click()
    await expect(alert).toHaveCount(0)
    await editor.getByLabel('Alias', { exact: true }).fill('safety helmet')
    await editor.getByRole('button', { name: 'Add alias' }).click()
    await expect(editor.getByTestId('alias-list')).toContainText('safety helmet')
  })

  test('validates the alias form before sending anything', async ({ page }) => {
    const api = await openTags(page)

    await page.getByRole('button', { name: 'Edit Каска' }).click()
    const editor = editorOf(page)

    await editor.getByRole('button', { name: 'Add alias' }).click()
    await expect(editor.getByText('Enter an alias.')).toBeVisible()

    await editor.getByLabel('Alias', { exact: true }).fill('helmet')
    await editor.getByLabel('Locale', { exact: true }).fill('English!')
    await editor.getByRole('button', { name: 'Add alias' }).click()
    await expect(editor.getByText('Use a language code such as en, ru or pt-BR.')).toBeVisible()

    expect(api.mutations()).toHaveLength(0)
  })

  test('a tag deleted elsewhere is reported and the catalog reloaded', async ({ page }) => {
    const api = await openTags(page)

    // The tag disappears on the server while the page still shows it.
    api.tags.splice(api.tags.findIndex(tag => tag.id === 4), 1)
    await page.getByRole('button', { name: 'Archive Каска' }).click()

    await expect(page.getByRole('alert').filter({ hasText: 'Could not archive the tag' })).toContainText('Tag not found')
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
    await expect(page.getByRole('alert')).toContainText('Something went wrong')
    await page.getByRole('button', { name: 'Try again' }).click()

    await expect(page.getByTestId('tags-total')).toHaveText('5 total · 1 archived')
    await expect(page.getByRole('alert')).toHaveCount(0)
    expect(api.requests.filter(r => r.method === 'GET')).toHaveLength(1)
  })

  test('shows an empty state when there are no tags yet', async ({ page }) => {
    await openTags(page, [])
    await expect(page.getByText('No tags yet. Create one to get started.')).toBeVisible()
  })
})
