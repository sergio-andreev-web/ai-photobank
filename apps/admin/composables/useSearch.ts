import type { SearchRequest, SearchResponse } from '~/types/api'

export function useSearch() {
  const api = useApi()

  async function search(request: SearchRequest) {
    const { data } = await api.post<SearchResponse>('/admin/search', request)
    return data
  }

  return {
    search,
  }
}
