import { test, expect, type Page } from '@playwright/test'
import type { Face, Person } from '../../types/api'
import { apiError, gotoReady, imageSummary, json, loginAsAdmin, pageOf } from './helpers'

const NAME = 'Иван Петров'
const STAMP = '2026-09-30T09:00:00Z'
const urls = { thumbnail: '/face-test.svg', preview: '/face-test.svg', original: '/face-test.svg' }

function face(id: number, status: Face['status'] = 'unassigned', imageId = id): Face {
  return {
    id, image: { id: imageId, original_name: `portrait-${imageId}.jpg`, urls },
    box: { x: 0.2, y: 0.1, width: 0.25, height: 0.3 }, score: 0.99, status,
    person: status === 'unassigned' ? null : { id: 7, name: NAME },
    similarity: status === 'auto' ? 0.8 : status === 'suggested' ? 0.46 : null,
  }
}

/** Contract 1.10.0 fixtures; matching belongs to the backend, so tests only model API results. */
async function mockPeople(page: Page, initial: Face[] = [], dimensions = { width: 800, height: 600 }) {
  const state = {
    faces: structuredClone(initial), name: NAME, deleted: false,
    writes: [] as { method: string; path: string; body: unknown }[],
    galleryQueries: [] as URLSearchParams[],
  }
  const person = (): Person => ({
    id: 7, name: state.name, note: null,
    faces_count: state.faces.filter(f => f.status === 'confirmed').length,
    images_count: new Set(state.faces.filter(f => f.status === 'confirmed' || f.status === 'auto').map(f => f.image.id)).size,
    suggested_count: state.faces.filter(f => f.status === 'suggested').length,
    cover: state.faces.find(f => f.status === 'confirmed') ?? null,
    created_at: STAMP, updated_at: STAMP,
  })
  await page.route('**/face-test.svg', route => route.fulfill({
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><rect width="800" height="600" fill="#cbd5e1"/></svg>',
  }))
  await page.route('**/api/admin/**', route => {
    const url = new URL(route.request().url())
    const path = url.pathname.replace('/api/admin', '')
    const method = route.request().method()
    const body = method === 'POST' || method === 'PATCH' ? route.request().postDataJSON() : null
    if (method !== 'GET') state.writes.push({ method, path, body })
    if (path === '/faces/clusters') {
      const unknown = state.faces.filter(f => f.status === 'unassigned')
      return json(route, 200, { data: unknown.length >= 2 ? [{ faces: unknown, images_count: new Set(unknown.map(f => f.image.id)).size }] : [] })
    }
    if (path === '/people') {
      const q = url.searchParams.get('q')?.toLowerCase() ?? ''
      return json(route, 200, pageOf(state.deleted || !state.name.toLowerCase().includes(q) ? [] : [person()]))
    }
    if (path === '/people/7') {
      if (method === 'DELETE') { state.deleted = true; state.faces = []; return route.fulfill({ status: 204 }) }
      if (method === 'PATCH') {
        state.name = body.name
        state.faces.forEach(f => { if (f.person) f.person.name = state.name })
      }
      return state.deleted ? apiError(route, 404, 'PERSON_NOT_FOUND', 'Person not found') : json(route, 200, person())
    }
    if (path === '/people/7/faces') {
      return json(route, 200, pageOf(state.faces.filter(f => f.person && f.status === url.searchParams.get('status'))))
    }
    if (path === '/faces/assign') {
      expect(body.face_ids.every((id: unknown) => Number.isInteger(id))).toBe(true)
      expect(Boolean(body.name) !== Boolean(body.person_id)).toBe(true)
      if (body.name) state.name = body.name
      state.faces.forEach(f => {
        if (body.face_ids.includes(f.id)) {
          f.status = 'confirmed'; f.person = { id: 7, name: state.name }; f.similarity = null
        }
      })
      return json(route, 200, { person: person(), assigned_count: body.face_ids.length, auto_count: 0, suggested_count: person().suggested_count })
    }
    const reject = path.match(/^\/faces\/(\d+)\/reject$/)
    if (reject) {
      const f = state.faces.find(f => f.id === Number(reject[1]))!
      f.status = 'unassigned'; f.person = null; f.similarity = null
      return json(route, 200, f)
    }
    if (path === '/images/152/faces') return json(route, 200, { data: state.faces.filter(f => f.image.id === 152) })
    if (path === '/images/152') return json(route, 200, {
      ...imageSummary({ id: 152, urls }), updated_at: STAMP,
      mime_type: 'image/jpeg', extension: 'jpg', ...dimensions, file_size: 1024,
      sha256: 'a'.repeat(64), caption: null, ocr_text: null, error_code: null, error_message: null,
      image_tags: [], latest_analysis: null, processing_tasks: [],
    })
    if (path === '/images') {
      state.galleryQueries.push(url.searchParams)
      return json(route, 200, pageOf([imageSummary({ id: 152, urls })]))
    }
    if (path === '/tags' || path === '/batches') return json(route, 200, pageOf([]))
    return route.fallback()
  })
  return state
}

test.describe('people and faces (ADR-0015)', () => {
  test('remembers only selected cluster faces and refreshes the directory', async ({ page }) => {
    await loginAsAdmin(page)
    const state = await mockPeople(page, [face(1), face(2), face(3)])
    await gotoReady(page, '/people')
    const card = page.getByTestId('cluster-card')
    await card.getByRole('checkbox', { name: 'Лицо с фото portrait-3.jpg' }).uncheck()
    await card.getByLabel('Как зовут?').fill('  Анна Смирнова  ')
    await card.getByRole('button', { name: 'Запомнить (2)' }).click()
    await expect(page.getByTestId('people-notice')).toContainText('Анна Смирнова')
    await expect(page.getByTestId('cluster-card')).toHaveCount(0)
    await expect(page.getByTestId('person-card')).toContainText('Анна Смирнова')
    expect(state.writes).toEqual([{ method: 'POST', path: '/faces/assign', body: { face_ids: [1, 2], name: 'Анна Смирнова' } }])
  })

  test('requires a name and at least one selected face', async ({ page }) => {
    await loginAsAdmin(page)
    const state = await mockPeople(page, [face(1), face(2)])
    await gotoReady(page, '/people')
    const card = page.getByTestId('cluster-card')
    await expect(card.getByRole('button', { name: /Запомнить/ })).toBeDisabled()
    await card.getByLabel('Как зовут?').fill('   ')
    await expect(card.getByRole('button', { name: /Запомнить/ })).toBeDisabled()
    await card.getByLabel('Как зовут?').fill(NAME)
    for (const checkbox of await card.getByRole('checkbox').all()) await checkbox.uncheck()
    await expect(card.getByRole('button', { name: 'Запомнить (0)' })).toBeDisabled()
    await card.getByLabel('Как зовут?').press('Enter')
    expect(state.writes).toEqual([])
  })

  test('assignment error keeps the selected faces and name for retry', async ({ page }) => {
    await loginAsAdmin(page)
    await mockPeople(page, [face(1), face(2)])
    await page.route('**/api/admin/faces/assign', route => apiError(route, 422, 'VALIDATION_ERROR', 'Invalid name'))
    await gotoReady(page, '/people')
    const card = page.getByTestId('cluster-card')
    await card.getByLabel('Как зовут?').fill(NAME)
    await card.getByRole('button', { name: 'Запомнить (2)' }).click()
    await expect(page.getByTestId('people-notice')).toContainText('Проверьте введённые данные.')
    await expect(card.getByLabel('Как зовут?')).toHaveValue(NAME)
    await expect(card.getByRole('button', { name: 'Запомнить (2)' })).toBeEnabled()
  })

  test('searches the directory by name', async ({ page }) => {
    await loginAsAdmin(page)
    await mockPeople(page, [face(1, 'confirmed')])
    await gotoReady(page, '/people')
    await expect(page.getByTestId('person-card')).toContainText(NAME)
    await page.getByRole('textbox', { name: 'Поиск по имени' }).fill('нет такого')
    await expect(page.getByTestId('person-card')).toHaveCount(0)
    await expect(page.getByText('Никого не нашли.')).toBeVisible()
    await page.getByRole('textbox', { name: 'Поиск по имени' }).fill('ИВАН')
    await expect(page.getByTestId('person-card')).toContainText(NAME)
  })

  test('confirms suggestions, rejects an automatic match and renames the person', async ({ page }) => {
    await loginAsAdmin(page)
    const state = await mockPeople(page, [face(1, 'suggested'), face(2, 'suggested'), face(3, 'auto'), face(4, 'confirmed')])
    await gotoReady(page, '/people/7')
    await expect(page.getByTestId('faces-suggested').getByTestId('person-face')).toHaveCount(2)
    await page.getByRole('button', { name: 'Подтвердить все' }).click()
    await expect(page.getByTestId('faces-confirmed').getByTestId('person-face')).toHaveCount(3)
    await page.getByTestId('faces-auto').getByRole('button', { name: `Нет, это не ${NAME}` }).click()
    await expect(page.getByTestId('faces-auto').getByTestId('person-face')).toHaveCount(0)
    await page.getByRole('button', { name: 'Переименовать' }).click()
    await page.getByLabel('Имя', { exact: true }).fill('  Иван Сергеев  ')
    await page.getByRole('button', { name: 'Сохранить' }).click()
    await expect(page.getByTestId('person-name')).toHaveText('Иван Сергеев')
    expect(state.writes).toEqual([
      { method: 'POST', path: '/faces/assign', body: { face_ids: [1, 2], person_id: 7 } },
      { method: 'POST', path: '/faces/3/reject', body: null },
      { method: 'PATCH', path: '/people/7', body: { name: 'Иван Сергеев' } },
    ])
  })

  test('deletes biometrics only after confirmation and returns to the directory', async ({ page }) => {
    await loginAsAdmin(page)
    const state = await mockPeople(page, [face(1, 'confirmed')])
    await gotoReady(page, '/people/7')
    await page.getByRole('button', { name: 'Удалить', exact: true }).click()
    const confirmation = page.getByRole('region', { name: 'Подтверждение удаления' })
    await expect(confirmation).toContainText('Фото останутся в библиотеке.')
    expect(state.writes).toEqual([])
    await confirmation.getByRole('button', { name: 'Отмена' }).click()
    await expect(confirmation).toHaveCount(0)
    expect(state.writes).toEqual([])
    await page.getByRole('button', { name: 'Удалить', exact: true }).click()
    await confirmation.getByRole('button', { name: 'Удалить навсегда' }).click()
    await expect(page).toHaveURL(/\/people$/)
    await expect(page.getByTestId('person-card')).toHaveCount(0)
    expect(state.writes).toEqual([{ method: 'DELETE', path: '/people/7', body: null }])
  })

  test('shows PERSON_NOT_FOUND for a missing person', async ({ page }) => {
    await loginAsAdmin(page)
    await mockPeople(page)
    await page.route('**/api/admin/people/7', route => apiError(route, 404, 'PERSON_NOT_FOUND', 'Person not found'))
    await gotoReady(page, '/people/7')
    await expect(page.getByRole('alert')).toContainText('Человек не найден — возможно, его уже удалили.')
    await expect(page.getByTestId('person-name')).toHaveCount(0)
  })

  test('confirms and rejects a face on the image, updating overlay labels', async ({ page }) => {
    await loginAsAdmin(page)
    const state = await mockPeople(page, [face(1, 'suggested', 152)])
    await gotoReady(page, '/gallery/152')
    await expect(page.getByTestId('face-overlay')).toHaveText(`${NAME}?`)
    await page.getByTestId('image-faces').getByRole('button', { name: 'Да', exact: true }).click()
    await expect(page.getByTestId('face-overlay')).toHaveText(NAME)
    await expect(page.getByTestId('image-faces').getByRole('link', { name: NAME })).toHaveAttribute('href', '/people/7')
    await page.getByRole('button', { name: 'Это не он(а)' }).click()
    await expect(page.getByTestId('face-overlay')).toHaveText('')
    await expect(page.getByRole('button', { name: 'Кто это?' })).toBeVisible()
    expect(state.writes).toEqual([
      { method: 'POST', path: '/faces/assign', body: { face_ids: [1], person_id: 7 } },
      { method: 'POST', path: '/faces/1/reject', body: null },
    ])
  })

  for (const portrait of [false, true]) {
    test(`face overlay follows the ${portrait ? 'portrait' : 'landscape'} preview at desktop and mobile widths`, async ({ page }) => {
      await loginAsAdmin(page)
      await mockPeople(page, [face(1, 'unassigned', 152)], portrait ? { width: 600, height: 900 } : undefined)
      if (portrait) await page.route('**/face-test.svg', route => route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="900"><rect width="600" height="900" fill="#cbd5e1"/></svg>',
      }))
      for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 })
        await gotoReady(page, '/gallery/152')
        const image = page.getByRole('img', { name: 'IMG_152.jpg', exact: true })
        await expect(image).toBeVisible()
        await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true)
        const preview = (await image.boundingBox())!
        const box = (await page.getByTestId('face-overlay').boundingBox())!
        expect(box.x - preview.x).toBeCloseTo(preview.width * 0.2, 0)
        expect(box.y - preview.y).toBeCloseTo(preview.height * 0.1, 0)
        expect(box.width).toBeCloseTo(preview.width * 0.25, 0)
        expect(box.height).toBeCloseTo(preview.height * 0.3, 0)
        expect(preview.width / preview.height).toBeCloseTo(portrait ? 2 / 3 : 4 / 3, 2)
      }
    })
  }

  for (const target of ['new', 'existing']) {
    test(`names an unknown image face using a ${target} person`, async ({ page }) => {
      await loginAsAdmin(page)
      const state = await mockPeople(page, [face(1, 'unassigned', 152)])
      await gotoReady(page, '/gallery/152')
      await page.getByRole('button', { name: 'Кто это?' }).click()
      if (target === 'new') await page.getByLabel('Новый человек').fill('  Анна Смирнова  ')
      else {
        await page.getByText('Выберите', { exact: true }).click()
        await page.getByRole('option', { name: NAME }).click()
      }
      await page.getByRole('button', { name: 'Запомнить', exact: true }).click()
      await expect(page.getByTestId('image-notice')).toContainText(target === 'new' ? 'Анна Смирнова' : NAME)
      expect(state.writes[0]?.body).toEqual(target === 'new' ? { face_ids: [1], name: 'Анна Смирнова' } : { face_ids: [1], person_id: 7 })
    })
  }

  test('opens all photos from a person and clears the gallery filter', async ({ page }) => {
    await loginAsAdmin(page)
    const state = await mockPeople(page, [face(1, 'confirmed')])
    await gotoReady(page, '/people/7')
    await page.getByRole('link', { name: 'Все фото', exact: true }).click()
    await expect(page).toHaveURL(/\/gallery\?person_id=7$/)
    await expect.poll(() => state.galleryQueries.at(-1)?.get('person_id')).toBe('7')
    await expect(page.getByTestId('person-filter')).toContainText(NAME)
    await page.getByRole('button', { name: /Сбросить/ }).click()
    await expect.poll(() => state.galleryQueries.at(-1)?.has('person_id')).toBe(false)
  })

  test('gallery picker sends person_id and invalid deep links are ignored', async ({ page }) => {
    await loginAsAdmin(page)
    const state = await mockPeople(page, [face(1, 'confirmed')])
    await gotoReady(page, '/gallery?person_id=invalid')
    await expect.poll(() => state.galleryQueries.length).toBeGreaterThan(0)
    expect(state.galleryQueries.at(-1)?.has('person_id')).toBe(false)
    await page.getByRole('button', { name: 'Фильтры', exact: true }).click()
    await page.getByTestId('person-filter').getByRole('button').click()
    await page.getByRole('option', { name: NAME }).click()
    await expect.poll(() => state.galleryQueries.at(-1)?.get('person_id')).toBe('7')
  })
})
