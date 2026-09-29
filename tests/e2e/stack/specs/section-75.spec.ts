import { expect, test, type Locator, type Page, type Response } from '@playwright/test'
import type {
  Batch,
  CandidateResolution,
  ImageDetail,
  ImageSummary,
  ImageTag,
  Paginated,
  SearchResponse,
  TagCandidate,
  TagRef,
} from '../lib/api'
import { apiGet, escapeRegExp, gotoReady, login, pickOption, waitForApi } from '../lib/app'
import { env } from '../lib/env'
import { makeTestImages, runNonce } from '../lib/png'

/**
 * ТЗ §75 on the real stack: after a clean `git clone` + `make setup`, the
 * seeded admin goes through the whole scenario in the browser. Nothing is
 * mocked between Nuxt, nginx, Laravel, RabbitMQ, PostgreSQL/pgvector and the
 * deterministic mock AI (ai-cpu, services/ai/app/models/mock.py).
 *
 * Order differs from the list in §75 on purpose: a fresh database has no
 * canonical tags (DatabaseSeeder creates only the admin), so every mock
 * concept arrives as a pending candidate and an image gets AI tags only once
 * a candidate is approved or merged (ADR-0008: `source=ai`). Steps 11–12
 * (moderation) therefore run before steps 6–10 (image card).
 *
 * The run only acts on its own data: 20 freshly generated PNGs (uploads are
 * deduplicated by SHA-256) and candidates whose sample images are among them,
 * so it also works on a database that already holds other images.
 */

const QUERY = 'мужчина в каске на промышленном объекте'
/** apps/admin/pages/candidates.vue shows page 1, per_page=20, in the API default order. */
const CANDIDATES_ON_FIRST_PAGE = 20

test.describe.configure({ mode: 'serial' })

const run: { batchId?: number; imageIds: number[] } = { imageIds: [] }

test.afterEach(async ({ page }, testInfo) => {
  if (testInfo.status === testInfo.expectedStatus || run.batchId === undefined) return
  // Processing state of this run's images, to tell a UI failure from a pipeline one.
  try {
    const batch = await apiGet<Batch>(page, `/api/admin/batches/${run.batchId}`)
    const images = await Promise.all(run.imageIds.map((id) => apiGet<ImageDetail>(page, `/api/admin/images/${id}`)))
    const summary = images.map(({ id, original_name, status, error_code, error_message, processing_tasks, image_tags }) => ({
      id,
      original_name,
      status,
      error_code,
      error_message,
      tasks: processing_tasks.map((task) => `${task.type}:${task.status}/${task.attempts}${task.error_code ? `:${task.error_code}` : ''}`),
      tags: image_tags.map((it) => `${it.tag.name} (${it.source})`),
    }))
    await testInfo.attach('stack-state.json', {
      body: JSON.stringify({ batch, images: summary }, null, 2),
      contentType: 'application/json',
    })
  } catch (error) {
    await testInfo.attach('stack-state-error.txt', { body: String(error), contentType: 'text/plain' })
  }
})

test('§75: login, batch of 20, progress, moderation, manual tag edits, tag and semantic search', async ({ page }) => {
  test.setTimeout(env.testTimeoutMs)
  const count = env.imageCount
  const nonce = runNonce()
  const files = makeTestImages(nonce, count)
  const fileNames = files.map((file) => file.name)

  await test.step('1. Войти', async () => {
    await login(page)
    await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible()
  })

  const batch = await test.step('2. Создать batch', async () => {
    await gotoReady(page, '/uploader')
    await page.getByPlaceholder('e.g., Site visit - September 2026').fill(`e2e §75 ${nonce}`)
    const [response] = await Promise.all([
      waitForApi(page, 'POST', '/api/admin/batches'),
      page.getByRole('button', { name: 'Create Batch' }).click(),
    ])
    expect(response.status(), 'POST /api/admin/batches').toBe(201)
    const created = (await response.json()) as Batch
    expect(created).toMatchObject({ status: 'created', progress: { total: 0, processed: 0, failed: 0 } })
    run.batchId = created.id
    await expect(page.getByText(`Batch #${created.id}:`)).toBeVisible()
    return created
  })

  const uploaded = await test.step(`3. Загрузить ${count} фотографий`, async () => {
    const uploadPath = `/api/admin/batches/${batch.id}/images`
    const bodies: ImageSummary[] = []
    const errors: string[] = []
    const reads: Promise<void>[] = []
    const onResponse = (response: Response) => {
      if (response.request().method() !== 'POST' || new URL(response.url()).pathname !== uploadPath) return
      reads.push(
        (async () => {
          if (response.status() === 201) bodies.push((await response.json()) as ImageSummary)
          else errors.push(`HTTP ${response.status()}: ${await response.text()}`)
        })(),
      )
    }

    page.on('response', onResponse)
    try {
      await page
        .locator('input[type="file"]')
        .setInputFiles(files.map((file) => ({ name: file.name, mimeType: 'image/png', buffer: file.buffer })))
      await expect.poll(() => reads.length, { message: 'one upload response per file', timeout: 120_000 }).toBe(count)
      await Promise.all(reads)
    } finally {
      page.off('response', onResponse)
    }

    expect(errors, 'upload errors').toEqual([])
    expect(bodies.map((body) => body.original_name).sort()).toEqual([...fileNames].sort())
    for (const body of bodies) {
      expect(body.batch_id).toBe(batch.id)
      expect(['uploaded', 'queued']).toContain(body.status)
    }
    run.imageIds = bodies.map((body) => body.id)

    const list = page.getByTestId('upload-list')
    await expect(list.getByText('Uploaded, queued for processing')).toHaveCount(count)
    return bodies
  })
  const ourIds = new Set(uploaded.map((image) => image.id))

  await test.step('4. Увидеть progress', async () => {
    const finish = page.getByRole('button', { name: `Mark upload finished (${count} files)` })
    await expect(finish).toBeEnabled()
    const [response] = await Promise.all([
      waitForApi(page, 'POST', `/api/admin/batches/${batch.id}/complete`),
      finish.click(),
    ])
    expect(response.status(), 'POST .../complete').toBe(200)

    const progress = page.getByTestId('batch-progress')
    await expect(progress).toBeVisible()
    await expect(progress).toContainText('Batch progress')
    // Playwright concatenates text from adjacent nodes ("Batch progress0 / 20"),
    // so there is no word boundary before the first digit.
    await expect(progress).toContainText(new RegExp(`\\d+ / ${count}\\b`))
  })

  await test.step('5. Дождаться processing', async () => {
    // Vue keeps the template's leading/trailing spaces in text nodes, and regex getByText sees raw text.
    const finalStatus = page.getByText(/^\s*Status: (completed|completed_with_errors|failed)\s*$/)
    await expect(finalStatus).toBeVisible({ timeout: env.processingTimeoutMs })
    await expect(finalStatus).toHaveText('Status: completed')
    await expect(page.getByTestId('batch-progress')).toContainText(`${count} / ${count}`)

    const state = await apiGet<Batch>(page, `/api/admin/batches/${batch.id}`)
    expect(state).toMatchObject({ status: 'completed', progress: { total: count, processed: count, failed: 0 } })

    const images = await Promise.all(run.imageIds.map((id) => apiGet<ImageDetail>(page, `/api/admin/images/${id}`)))
    for (const image of images) {
      expect(image.status, `${image.original_name} status`).toBe('ready')
      expect(image.caption, `${image.original_name} caption`).toBeTruthy()
      expect(image.latest_analysis?.concepts.length ?? 0, `${image.original_name} concepts`).toBeGreaterThan(0)
    }
  })

  // Steps 11–12 before 6–10: see the header comment.
  const moderation = await test.step('11. Открыть tag candidates', async () => {
    await gotoReady(page, '/candidates')
    await expect(page.getByRole('heading', { name: 'Tag Candidates', exact: true })).toBeVisible()

    const pending = await apiGet<Paginated<TagCandidate>>(page, '/api/admin/tag-candidates?status=pending&per_page=100')
    const ours = pending.data
      .slice(0, CANDIDATES_ON_FIRST_PAGE)
      .filter((candidate) => candidate.sample_images.some((sample) => ourIds.has(sample.id)))
    expect(
      ours.length,
      'pending candidates with this run\'s images on the first page (needs mock concepts that are not canonical tags yet — use a fresh `make setup` stack)',
    ).toBeGreaterThanOrEqual(3)

    for (const candidate of ours.slice(0, 3)) {
      const card = candidateCard(page, candidate.display_name)
      await expect(page.getByText(candidate.display_name, { exact: true })).toHaveCount(1)
      await expect(card).toContainText(`seen in ${candidate.occurrences_count} image(s)`)
      const sample = candidate.sample_images.find((image) => ourIds.has(image.id))!
      await expectImageLoaded(card.getByAltText(`Sample image #${sample.id}`, { exact: true }))
    }
    const [first, second, third] = ours as [TagCandidate, TagCandidate, TagCandidate]
    return { created: first, createdSecond: second, merged: third }
  })

  const tags = await test.step('12. Создать тег из candidate и объединить candidate с тегом', async () => {
    const approve = async (candidate: TagCandidate): Promise<TagRef> => {
      const [response] = await Promise.all([
        waitForApi(page, 'POST', `/api/admin/tag-candidates/${candidate.id}/approve`),
        candidateCard(page, candidate.display_name).getByRole('button', { name: 'Create Tag' }).click(),
      ])
      expect(response.status(), `approve "${candidate.display_name}"`).toBe(200)
      const resolution = (await response.json()) as CandidateResolution
      expect(resolution.candidate.status).toBe('created')
      expect(resolution.tag).toMatchObject({ name: candidate.display_name, type: candidate.type, status: 'active' })
      expect(resolution.attached_images_count).toBeGreaterThan(0)
      await expect(page.getByText(candidate.display_name, { exact: true })).toHaveCount(0)
      return resolution.tag
    }

    const tagA = await approve(moderation.created)
    const tagB = await approve(moderation.createdSecond)

    // Merge the third candidate into the first new tag; "Add as alias" is on by default (ADR-0008).
    const merged = moderation.merged
    await candidateCard(page, merged.display_name).getByRole('button', { name: 'Merge Into...' }).click()
    await pickOption(page, page.getByText('Search tags...', { exact: true }), `${tagA.name} (${tagA.type})`, tagA.name)
    await expect(page.getByRole('checkbox', { name: 'Add as alias' })).toBeChecked()
    const [response] = await Promise.all([
      waitForApi(page, 'POST', `/api/admin/tag-candidates/${merged.id}/merge`),
      page.getByRole('button', { name: 'Confirm Merge' }).click(),
    ])
    expect(response.request().postDataJSON()).toEqual({ tag_id: tagA.id, create_alias: true })
    expect(response.status(), `merge "${merged.display_name}"`).toBe(200)
    const resolution = (await response.json()) as CandidateResolution
    expect(resolution.candidate.status).toBe('merged')
    expect(resolution.tag.id).toBe(tagA.id)
    expect(resolution.alias_created).toBe(true)
    await expect(page.getByText(merged.display_name, { exact: true })).toHaveCount(0)

    return [tagA, tagB] as const
  })

  // An image of this run that carries one of the new tags as an AI tag and lacks the other,
  // so the same card shows removing a wrong AI tag and adding the admin's own tag.
  const target = await test.step('Выбрать изображение для шагов 6–10', async () => {
    const images = await Promise.all(run.imageIds.map((id) => apiGet<ImageDetail>(page, `/api/admin/images/${id}`)))
    for (const image of images) {
      const aiTag = image.image_tags.find((it) => it.source === 'ai' && tags.some((tag) => tag.id === it.tag.id))
      const missing = tags.find((tag) => !image.image_tags.some((it) => it.tag.id === tag.id))
      if (aiTag && missing) return { image, remove: aiTag.tag, add: missing }
    }
    throw new Error(`no image of this run has exactly one of ${tags.map((tag) => tag.name).join(', ')}`)
  })
  const { image, remove, add } = target

  await test.step('6. Открыть image', async () => {
    await gotoReady(page, '/gallery')
    await page.getByRole('link', { name: image.original_name }).click()
    await page.waitForURL(`**/gallery/${image.id}`)
    await expect(page.getByText(image.original_name, { exact: true })).toBeVisible()
    await expectImageLoaded(page.getByRole('img', { name: image.original_name, exact: true }))
  })

  await test.step('7. Увидеть caption', async () => {
    await expect(page.getByText(`Caption: ${image.caption}`, { exact: true })).toBeVisible()
  })

  await test.step('8. Увидеть AI tags', async () => {
    const aiTags = image.image_tags.filter((it) => it.source === 'ai')
    expect(aiTags.length).toBeGreaterThan(0)
    for (const it of aiTags) await expect(tagBadge(page, it.tag.name, 'ai')).toBeVisible()
  })

  await test.step('9. Вручную удалить неправильный tag', async () => {
    const removeButton = page.getByRole('button', { name: `Remove ${remove.name}`, exact: true })
    const [response] = await Promise.all([
      waitForApi(page, 'DELETE', `/api/admin/images/${image.id}/tags/${remove.id}`),
      removeButton.click(),
    ])
    expect(response.status(), 'DELETE image tag').toBe(204)
    await expect(removeButton).toHaveCount(0)
  })

  await test.step('10. Добавить свой tag', async () => {
    await page.getByRole('button', { name: 'Add Tag', exact: true }).click()
    await pickOption(page, page.getByText('Select a tag', { exact: true }), `${add.name} (${add.type})`, add.name)
    const [response] = await Promise.all([
      waitForApi(page, 'POST', `/api/admin/images/${image.id}/tags`),
      page.getByRole('button', { name: 'Add', exact: true }).click(),
    ])
    expect(response.request().postDataJSON()).toEqual({ tag_id: add.id })
    expect(response.status(), 'POST image tag').toBe(201)
    expect((await response.json()) as ImageTag).toMatchObject({ tag: { id: add.id }, source: 'manual', locked: true })
    await expect(tagBadge(page, add.name, 'manual')).toBeVisible()

    // Both edits survive a reload.
    await gotoReady(page, `/gallery/${image.id}`)
    await expect(tagBadge(page, add.name, 'manual')).toBeVisible()
    await expect(page.getByRole('button', { name: `Remove ${remove.name}`, exact: true })).toHaveCount(0)
  })

  await test.step('13. Выполнить поиск по tag', async () => {
    const withTag = await Promise.all(run.imageIds.map((id) => apiGet<ImageDetail>(page, `/api/admin/images/${id}`)))
    const expected = withTag.filter((it) => it.image_tags.some((imageTag) => imageTag.tag.id === add.id))
    expect(expected.map((it) => it.id)).toContain(image.id)

    await gotoReady(page, '/search')
    await pickOption(page, page.getByRole('combobox', { name: 'Search Mode' }), 'Tags only')
    await pickOption(page, page.getByText('Select tags (all must match)', { exact: true }), `${add.name} (${add.type})`, add.name)
    await page.keyboard.press('Escape')
    const [response] = await Promise.all([
      waitForApi(page, 'POST', '/api/search'),
      page.getByRole('button', { name: 'Search', exact: true }).click(),
    ])
    expect(response.request().postDataJSON()).toMatchObject({ mode: 'tags', tag_ids: [add.id] })
    expect(response.status(), 'POST /api/search (tags)').toBe(200)
    const result = (await response.json()) as SearchResponse
    expect(result.meta.mode).toBe('tags')
    // Tag filter is a hard filter (§43): every hit carries the tag.
    for (const hit of result.data) expect(hit.tags.map((tag) => tag.id), hit.original_name).toContain(add.id)
    const hitIds = result.data.map((hit) => hit.id)
    for (const it of expected) expect(hitIds, `${it.original_name} in tag results`).toContain(it.id)

    const results = page.getByTestId('search-results')
    await expect(results).toBeVisible()
    await expect(results.getByRole('link')).toHaveCount(result.data.length)
    for (const it of expected) await expect(results.getByText(it.original_name, { exact: true })).toBeVisible()
  })

  await test.step(`14. Выполнить запрос «${QUERY}»`, async () => {
    await pickOption(page, page.getByRole('combobox', { name: 'Search Mode' }), 'Semantic query')
    await page.getByPlaceholder('e.g., workers in hard hats near industrial equipment').fill(QUERY)
    await page.getByRole('checkbox', { name: 'Debug (show similarity score)' }).check()
  })

  await test.step('15. Получить изображения, отсортированные по semantic similarity', async () => {
    const [response] = await Promise.all([
      waitForApi(page, 'POST', '/api/search'),
      page.getByRole('button', { name: 'Search', exact: true }).click(),
    ])
    expect(response.request().postDataJSON()).toMatchObject({ mode: 'semantic', query: QUERY, debug: true })
    expect(response.status(), 'POST /api/search (semantic)').toBe(200)
    const result = (await response.json()) as SearchResponse
    expect(result.meta.mode).toBe('semantic')
    // Every image of this run has an active embedding of the configured model version.
    expect(result.meta.total).toBeGreaterThanOrEqual(count)
    expect(result.data.length).toBe(Math.min(result.meta.total, result.meta.per_page))

    const scores = result.data.map((hit) => hit.semantic_score)
    for (const score of scores) {
      expect(typeof score).toBe('number')
      expect(score!).toBeGreaterThanOrEqual(-1)
      expect(score!).toBeLessThanOrEqual(1)
    }
    expectNonIncreasing(scores as number[], 'semantic_score in the API response')

    // The page shows the ranking in the returned order, with the debug score on every card.
    const results = page.getByTestId('search-results')
    await expect(page.getByRole('heading', { name: `Results (${result.meta.total})` })).toBeVisible()
    const cards = results.getByRole('link')
    await expect(cards).toHaveCount(result.data.length)
    const cardTexts = (await cards.allTextContents()).map((text) => text.replace(/\s+/g, ' ').trim())
    result.data.forEach((hit, i) => {
      expect(cardTexts[i], `result card ${i + 1}`).toContain(hit.original_name)
      expect(cardTexts[i], `result card ${i + 1}`).toContain(`Score: ${hit.semantic_score!.toFixed(3)}`)
    })
    const shown = (await results.getByText(/^\s*Score: -?\d+\.\d{3}\s*$/).allTextContents()).map((text) =>
      Number(text.replace('Score:', '')),
    )
    expect(shown).toHaveLength(result.data.length)
    expectNonIncreasing(shown, 'Score values on the page')

    if (result.meta.total === count) {
      // Fresh stack: the ranking covers exactly this run's images.
      expect(new Set(result.data.map((hit) => hit.id))).toEqual(ourIds)
    }
  })
})

/**
 * The smallest container holding both the candidate's name and its action
 * buttons: a candidate card without depending on its markup or classes.
 */
function candidateCard(page: Page, displayName: string): Locator {
  return page
    .locator('div')
    .filter({ has: page.getByText(displayName, { exact: true }) })
    .filter({ has: page.getByRole('button', { name: 'Merge Into...' }) })
    .last()
}

/** A tag badge on the image card, e.g. "Каска (manual)". */
function tagBadge(page: Page, name: string, source: ImageTag['source']): Locator {
  return page.getByTestId('image-tags').getByText(new RegExp(`^\\s*${escapeRegExp(name)}\\s*\\(${source}\\)`))
}

/** Thumbnails/previews come through /api/media and nginx X-Accel-Redirect (§57, ADR-0011). */
async function expectImageLoaded(img: Locator): Promise<void> {
  await expect(img).toBeVisible()
  await expect
    .poll(() => img.evaluate((element) => (element as HTMLImageElement).complete && (element as HTMLImageElement).naturalWidth > 0), {
      message: 'image loads through /api/media',
    })
    .toBe(true)
}

function expectNonIncreasing(values: number[], what: string): void {
  expect(values.length, what).toBeGreaterThan(0)
  for (let i = 1; i < values.length; i++) {
    expect(values[i]!, `${what}: position ${i + 1} must not exceed position ${i} (${values.join(', ')})`).toBeLessThanOrEqual(values[i - 1]!)
  }
}
