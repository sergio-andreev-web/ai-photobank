import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Tag, TagAlias } from '~/types/api'
import { useTags } from '~/composables/useTags'

function tag(id: number, extra: Partial<Tag> = {}): Tag {
  return {
    id,
    parent_id: null,
    name: `Tag ${id}`,
    slug: `tag-${id}`,
    normalized_name: `tag ${id}`,
    type: 'object',
    status: 'active',
    images_count: 0,
    aliases: [],
    created_at: '2026-09-25T12:00:00Z',
    updated_at: '2026-09-25T12:00:00Z',
    ...extra,
  }
}

// useApi() is a Nuxt auto-import; the composable only needs the four verbs of the axios instance.
const http = { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() }

beforeEach(() => {
  vi.stubGlobal('useApi', () => http)
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.resetAllMocks()
})

describe('useTags requests (contracts/public-api.openapi.yaml)', () => {
  it('creates a tag with name, type and parent_id', async () => {
    http.post.mockResolvedValue({ data: tag(9), status: 201 })
    await useTags().createTag({ name: 'Каска', type: 'object', parent_id: null })
    expect(http.post).toHaveBeenCalledWith('/admin/tags', { name: 'Каска', type: 'object', parent_id: null })
  })

  it('patches only what it is given', async () => {
    http.patch.mockResolvedValue({ data: tag(9, { status: 'archived' }), status: 200 })
    const updated = await useTags().updateTag(9, { status: 'archived' })
    expect(http.patch).toHaveBeenCalledWith('/admin/tags/9', { status: 'archived' })
    expect(updated.status).toBe('archived')
  })

  it('returns the archived tag when DELETE answers 200 (tag in use)', async () => {
    const archived = tag(9, { status: 'archived', images_count: 3 })
    http.delete.mockResolvedValue({ data: archived, status: 200 })
    await expect(useTags().deleteTag(9)).resolves.toEqual(archived)
    expect(http.delete).toHaveBeenCalledWith('/admin/tags/9')
  })

  it('returns null when DELETE answers 204 (tag removed)', async () => {
    http.delete.mockResolvedValue({ data: '', status: 204 })
    await expect(useTags().deleteTag(9)).resolves.toBeNull()
  })

  it('adds an alias with its locale, and omits the locale when there is none', async () => {
    const alias: TagAlias = {
      id: 3, tag_id: 9, alias: 'helmet', normalized_alias: 'helmet', locale: 'en',
      created_at: '2026-09-25T12:00:00Z', updated_at: '2026-09-25T12:00:00Z',
    }
    http.post.mockResolvedValue({ data: alias, status: 201 })

    await expect(useTags().createAlias(9, 'helmet', 'en')).resolves.toEqual(alias)
    expect(http.post).toHaveBeenLastCalledWith('/admin/tags/9/aliases', { alias: 'helmet', locale: 'en' })

    await useTags().createAlias(9, 'hard hat')
    const [, body] = http.post.mock.calls.at(-1)!
    expect(JSON.parse(JSON.stringify(body))).toEqual({ alias: 'hard hat' })
  })

  it('deletes an alias by its numeric id, not by its text (ADR-0007)', async () => {
    http.delete.mockResolvedValue({ data: '', status: 204 })
    await useTags().deleteAlias(9, 3)
    expect(http.delete).toHaveBeenCalledWith('/admin/tags/9/aliases/3')
  })

  it('loads the whole catalog page by page with per_page=500', async () => {
    http.get
      .mockResolvedValueOnce({ data: { data: [tag(1), tag(2)], meta: { page: 1, per_page: 500, total: 3, last_page: 2 } } })
      .mockResolvedValueOnce({ data: { data: [tag(3)], meta: { page: 2, per_page: 500, total: 3, last_page: 2 } } })

    const all = await useTags().listAllTags()

    expect(all.map(t => t.id)).toEqual([1, 2, 3])
    expect(http.get.mock.calls.map(([url, config]) => [url, config.params.page, config.params.per_page])).toEqual([
      ['/admin/tags', 1, 500],
      ['/admin/tags', 2, 500],
    ])
  })
})
