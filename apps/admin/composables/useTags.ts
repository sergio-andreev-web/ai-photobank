import type { Tag, TagAlias, TagCreateRequest, TagList, TagPatchRequest, TagStatus, TagType } from '~/types/api'

export function useTags() {
  const api = useApi()

  async function listTags(params?: {
    page?: number
    per_page?: number
    q?: string
    type?: TagType
    status?: TagStatus
    parent_id?: number
    sort?: string
  }) {
    const { data } = await api.get<TagList>('/admin/tags', { params })
    return data
  }

  async function getTag(id: number) {
    const { data } = await api.get<Tag>(`/admin/tags/${id}`)
    return data
  }

  async function createTag(request: TagCreateRequest) {
    const { data } = await api.post<Tag>('/admin/tags', request)
    return data
  }

  async function updateTag(id: number, patch: TagPatchRequest) {
    const { data } = await api.patch<Tag>(`/admin/tags/${id}`, patch)
    return data
  }

  async function deleteTag(id: number) {
    const { data, status } = await api.delete<Tag | undefined>(`/admin/tags/${id}`)
    // 200 = archived because in use, 204 = deleted outright (§50).
    return status === 200 ? data ?? null : null
  }

  async function createAlias(tagId: number, alias: string, locale?: string | null) {
    const { data } = await api.post<TagAlias>(`/admin/tags/${tagId}/aliases`, { alias, locale })
    return data
  }

  async function deleteAlias(tagId: number, aliasId: number) {
    await api.delete(`/admin/tags/${tagId}/aliases/${aliasId}`)
  }

  return {
    listTags,
    getTag,
    createTag,
    updateTag,
    deleteTag,
    createAlias,
    deleteAlias,
  }
}
