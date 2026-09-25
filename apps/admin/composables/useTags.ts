import type { Tag, TagList, Alias } from '~/types/api'

export function useTags() {
  const api = useApi()

  async function listTags(params?: any) {
    const { data } = await api.get<TagList>('/admin/tags', { params })
    return data
  }

  async function getTag(id: number) {
    const { data } = await api.get<Tag>(`/admin/tags/${id}`)
    return data
  }

  async function createTag(name: string, type: string, parentId?: number) {
    const { data } = await api.post<Tag>('/admin/tags', {
      name,
      type,
      parent_id: parentId || undefined,
    })
    return data
  }

  async function updateTag(id: number, updates: Partial<Tag>) {
    const { data } = await api.patch<Tag>(`/admin/tags/${id}`, updates)
    return data
  }

  async function archiveTag(id: number) {
    const { data } = await api.patch<Tag>(`/admin/tags/${id}`, { archived_at: new Date().toISOString() })
    return data
  }

  async function createAlias(tagId: number, alias: string) {
    const { data } = await api.post<Alias>('/admin/aliases', { tag_id: tagId, alias })
    return data
  }

  async function deleteAlias(id: number) {
    await api.delete(`/admin/aliases/${id}`)
  }

  return {
    listTags,
    getTag,
    createTag,
    updateTag,
    archiveTag,
    createAlias,
    deleteAlias,
  }
}
