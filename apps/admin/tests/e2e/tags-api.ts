import type { Page, Route } from '@playwright/test'
import { apiError, json } from './helpers'

/**
 * A small stateful fake of the tag endpoints of contracts/public-api.openapi.yaml
 * (tags, aliases; rules from ADR-0007): unique names per type, globally unique
 * aliases, parent checks, and DELETE that archives a tag in use. Every mutating
 * request is recorded so specs can assert what the admin UI actually sent.
 */

const TAG_TYPES = ['object', 'person', 'activity', 'scene', 'location', 'season', 'weather', 'brand', 'event', 'color', 'concept', 'other']
const STAMP = '2026-09-25T12:00:00Z'

export interface MockAlias {
  id: number
  tag_id: number
  alias: string
  normalized_alias: string
  locale: string | null
  created_at: string
  updated_at: string
}

export interface MockTag {
  id: number
  parent_id: number | null
  name: string
  slug: string
  normalized_name: string
  type: string
  status: 'active' | 'archived'
  images_count: number
  aliases: MockAlias[]
  created_at: string
  updated_at: string
}

export interface RecordedRequest {
  method: string
  path: string
  body: Record<string, unknown> | null
}

export interface TagsApiMock {
  tags: MockTag[]
  requests: RecordedRequest[]
  /** Mutating requests only, e.g. `mutations('POST')`. */
  mutations: (method?: string) => RecordedRequest[]
}

export function normalize(value: string): string {
  return value.normalize('NFKC').toLowerCase().replace(/\s+/g, ' ').trim()
}

export function makeAlias(id: number, tagId: number, alias: string, locale: string | null = null): MockAlias {
  return { id, tag_id: tagId, alias, normalized_alias: normalize(alias), locale, created_at: STAMP, updated_at: STAMP }
}

export function makeTag(
  id: number,
  name: string,
  overrides: Partial<Omit<MockTag, 'id' | 'name' | 'aliases'>> & { aliases?: Array<{ id: number; alias: string; locale?: string | null }> } = {},
): MockTag {
  const { aliases = [], ...rest } = overrides
  return {
    id,
    parent_id: null,
    name,
    slug: `tag-${id}`,
    normalized_name: normalize(name),
    type: 'object',
    status: 'active',
    images_count: 0,
    created_at: STAMP,
    updated_at: STAMP,
    ...rest,
    aliases: aliases.map(a => makeAlias(a.id, id, a.alias, a.locale ?? null)),
  }
}

function validation(route: Route, fields: Record<string, string[]>) {
  return json(route, 422, {
    error: { code: 'VALIDATION_ERROR', message: 'The given data was invalid', details: { fields }, trace_id: 'e2e-trace-id' },
  })
}

export async function mockTagsApi(page: Page, initial: MockTag[]): Promise<TagsApiMock> {
  const state: TagsApiMock = {
    tags: initial.map(tag => structuredClone(tag)),
    requests: [],
    mutations: (method) => state.requests.filter(r => r.method !== 'GET' && (!method || r.method === method)),
  }
  let nextTagId = Math.max(0, ...initial.map(t => t.id)) + 1
  let nextAliasId = Math.max(0, ...initial.flatMap(t => t.aliases.map(a => a.id))) + 1

  const find = (id: number) => state.tags.find(t => t.id === id)
  const withChildren = (id: number) => state.tags.some(t => t.parent_id === id)
  const isDescendantOrSelf = (candidateId: number, ofId: number): boolean => {
    for (let current = find(candidateId); current; current = current.parent_id === null ? undefined : find(current.parent_id)) {
      if (current.id === ofId) return true
    }
    return false
  }
  const nameOwner = (normalized: string, type: string, exceptId: number | null) =>
    state.tags.find(t => t.id !== exceptId && t.normalized_name === normalized && t.type === type)
  const aliasOwner = (normalized: string, exceptTagId: number | null) =>
    state.tags.find(t => t.id !== exceptTagId && t.aliases.some(a => a.normalized_alias === normalized))

  function checkParent(route: Route, tagId: number | null, parentId: unknown): boolean {
    if (parentId === null || parentId === undefined) return true
    const parent = typeof parentId === 'number' ? find(parentId) : undefined
    if (!parent) {
      apiError(route, 422, 'TAG_PARENT_INVALID', 'Parent tag does not exist')
      return false
    }
    if (parent.status !== 'active') {
      apiError(route, 422, 'TAG_PARENT_INVALID', 'Parent tag is archived')
      return false
    }
    if (tagId !== null && isDescendantOrSelf(parent.id, tagId)) {
      apiError(route, 422, 'TAG_PARENT_INVALID', 'A tag cannot be its own parent or a child of its descendant')
      return false
    }
    return true
  }

  await page.route(/\/api\/admin\/tags(\/[^?]*)?(\?.*)?$/, async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const method = request.method()
    const segments = url.pathname.replace(/^.*\/api\/admin\/tags\/?/, '').split('/').filter(Boolean)
    const body = method === 'GET' || method === 'DELETE' ? null : (request.postDataJSON() as Record<string, unknown> | null)
    state.requests.push({ method, path: url.pathname + url.search, body })

    // GET /api/admin/tags
    if (segments.length === 0 && method === 'GET') {
      const q = url.searchParams.get('q')
      const status = url.searchParams.get('status')
      const type = url.searchParams.get('type')
      const perPage = Number(url.searchParams.get('per_page') ?? 50)
      const pageNumber = Number(url.searchParams.get('page') ?? 1)
      let rows = [...state.tags].sort((a, b) => a.name.localeCompare(b.name) || a.id - b.id)
      if (q) rows = rows.filter(t => t.normalized_name.includes(normalize(q)) || t.aliases.some(a => a.normalized_alias.includes(normalize(q))))
      if (status) rows = rows.filter(t => t.status === status)
      if (type) rows = rows.filter(t => t.type === type)
      const lastPage = Math.max(1, Math.ceil(rows.length / perPage))
      return json(route, 200, {
        data: rows.slice((pageNumber - 1) * perPage, pageNumber * perPage),
        meta: { page: pageNumber, per_page: perPage, total: rows.length, last_page: lastPage },
      })
    }

    // POST /api/admin/tags
    if (segments.length === 0 && method === 'POST') {
      const name = typeof body?.name === 'string' ? body.name.trim() : ''
      const type = body?.type
      if (!name) return validation(route, { name: ['The name field is required.'] })
      if (typeof type !== 'string' || !TAG_TYPES.includes(type)) return validation(route, { type: ['The selected type is invalid.'] })
      const normalized = normalize(name)
      const clash = nameOwner(normalized, type, null) ?? aliasOwner(normalized, null)
      if (clash) return apiError(route, 409, 'TAG_ALREADY_EXISTS', 'Tag already exists', { existing_tag_id: clash.id })
      if (!checkParent(route, null, body?.parent_id)) return
      const tag = makeTag(nextTagId++, name, { type, parent_id: (body?.parent_id as number | null | undefined) ?? null, normalized_name: normalized })
      state.tags.push(tag)
      return json(route, 201, tag)
    }

    const tag = find(Number(segments[0]))
    if (!tag) return apiError(route, 404, 'TAG_NOT_FOUND', 'Tag not found')

    // GET|PATCH|DELETE /api/admin/tags/{tag}
    if (segments.length === 1) {
      if (method === 'GET') return json(route, 200, tag)

      if (method === 'PATCH') {
        if (!body || Object.keys(body).length === 0) return validation(route, { _unexpected: ['At least one field is required.'] })
        const name = typeof body.name === 'string' ? body.name.trim() : tag.name
        const type = typeof body.type === 'string' ? body.type : tag.type
        if (!name) return validation(route, { name: ['The name field is required.'] })
        if (!TAG_TYPES.includes(type)) return validation(route, { type: ['The selected type is invalid.'] })
        const normalized = normalize(name)
        const clash = nameOwner(normalized, type, tag.id) ?? aliasOwner(normalized, tag.id)
        if (clash) return apiError(route, 409, 'TAG_ALREADY_EXISTS', 'Tag already exists', { existing_tag_id: clash.id })
        if ('parent_id' in body && body.parent_id !== tag.parent_id && !checkParent(route, tag.id, body.parent_id)) return
        tag.name = name
        tag.normalized_name = normalized
        tag.type = type
        if ('parent_id' in body) tag.parent_id = (body.parent_id as number | null) ?? null
        if (body.status === 'active' || body.status === 'archived') tag.status = body.status
        return json(route, 200, tag)
      }

      if (method === 'DELETE') {
        if (tag.images_count > 0 || withChildren(tag.id)) {
          tag.status = 'archived'
          return json(route, 200, tag)
        }
        state.tags.splice(state.tags.indexOf(tag), 1)
        return route.fulfill({ status: 204 })
      }
    }

    // POST /api/admin/tags/{tag}/aliases
    if (segments[1] === 'aliases' && segments.length === 2 && method === 'POST') {
      const alias = typeof body?.alias === 'string' ? body.alias.trim() : ''
      const locale = (body?.locale as string | null | undefined) ?? null
      if (!alias) return validation(route, { alias: ['The alias field is required.'] })
      if (locale !== null && !/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/.test(locale)) return validation(route, { locale: ['The locale format is invalid.'] })
      const normalized = normalize(alias)
      const clash = aliasOwner(normalized, null) ?? state.tags.find(t => t.id !== tag.id && t.normalized_name === normalized)
      if (clash) return apiError(route, 409, 'ALIAS_ALREADY_EXISTS', 'Alias already belongs to another tag', { existing_tag_id: clash.id })
      const created = makeAlias(nextAliasId++, tag.id, alias, locale)
      tag.aliases.push(created)
      return json(route, 201, created)
    }

    // DELETE /api/admin/tags/{tag}/aliases/{alias}
    if (segments[1] === 'aliases' && segments.length === 3 && method === 'DELETE') {
      const index = tag.aliases.findIndex(a => a.id === Number(segments[2]))
      if (index === -1) return apiError(route, 404, 'ALIAS_NOT_FOUND', 'Alias not found')
      tag.aliases.splice(index, 1)
      return route.fulfill({ status: 204 })
    }

    return apiError(route, 405, 'METHOD_NOT_ALLOWED', 'Method not allowed')
  })

  return state
}
