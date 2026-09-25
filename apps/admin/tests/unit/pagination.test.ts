import { describe, it, expect, vi } from 'vitest'
import { fetchAllPages } from '~/utils/pagination'

describe('fetchAllPages', () => {
  it('requests pages until last_page and keeps their order', async () => {
    const fetchPage = vi.fn(async (page: number) => ({
      data: [page * 10, page * 10 + 1],
      meta: { page, per_page: 2, total: 6, last_page: 3 },
    }))

    await expect(fetchAllPages(fetchPage)).resolves.toEqual([10, 11, 20, 21, 30, 31])
    expect(fetchPage.mock.calls.map(([page]) => page)).toEqual([1, 2, 3])
  })

  it('makes a single request when the first page is the last one', async () => {
    const fetchPage = vi.fn(async () => ({
      data: [] as number[],
      meta: { page: 1, per_page: 500, total: 0, last_page: 1 },
    }))

    await expect(fetchAllPages(fetchPage)).resolves.toEqual([])
    expect(fetchPage).toHaveBeenCalledTimes(1)
  })
})
