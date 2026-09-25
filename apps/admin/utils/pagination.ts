import type { PaginationMeta } from '~/types/api'

/**
 * Loads every page of a paginated list endpoint. The contract caps per_page
 * (500 for tags), so a complete catalog can take several requests.
 */
export async function fetchAllPages<T>(
  fetchPage: (page: number) => Promise<{ data: T[], meta: PaginationMeta }>,
): Promise<T[]> {
  const items: T[] = []
  let page = 1
  let lastPage = 1
  do {
    const { data, meta } = await fetchPage(page)
    items.push(...data)
    lastPage = meta.last_page
    page += 1
  } while (page <= lastPage)
  return items
}
