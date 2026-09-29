import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axios from 'axios'
import { useImages } from '~/composables/useImages'

let requested: URL[]
let pages: Record<number, { ids: number[]; lastPage: number }>

beforeEach(() => {
  requested = []
  pages = {}
  // A real axios instance whose adapter records the URL that would go on the wire.
  const api = axios.create({
    baseURL: '/api',
    adapter: async (config) => {
      const url = new URL(axios.getUri(config), 'http://localhost')
      requested.push(url)
      const page = Number(url.searchParams.get('page') ?? 1)
      const { ids = [], lastPage = 1 } = pages[page] ?? {}
      return {
        data: {
          data: ids.map((id) => ({ id })),
          meta: { page, per_page: 100, total: ids.length, last_page: lastPage },
        },
        status: 200,
        statusText: 'OK',
        headers: {},
        config,
      }
    },
  })
  // Nuxt auto-imports useApi(); outside Nuxt it has to be provided.
  vi.stubGlobal('useApi', () => api)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('listImages', () => {
  it('sends tag_ids in the Laravel array form tag_ids[]=1&tag_ids[]=2 (contract TagIdsFilter)', async () => {
    await useImages().listImages({ tag_ids: [14, 87] })

    const url = requested[0]!
    expect(url.pathname).toBe('/api/admin/images')
    expect(url.searchParams.getAll('tag_ids[]')).toEqual(['14', '87'])
    // The bracket-less form `tag_ids=14&tag_ids=87` is read by Laravel as the scalar 87 and rejected with 422.
    expect(url.searchParams.has('tag_ids')).toBe(false)
  })

  it('passes the other filters through unchanged and drops the ones that are unset', async () => {
    await useImages().listImages({
      page: 2,
      per_page: 24,
      status: 'ready',
      batch_id: 42,
      created_from: '2026-09-23T21:00:00Z',
      created_to: undefined,
    })

    const params = Object.fromEntries(requested[0]!.searchParams)
    expect(params).toEqual({
      page: '2',
      per_page: '24',
      status: 'ready',
      batch_id: '42',
      created_from: '2026-09-23T21:00:00Z',
    })
  })
})

describe('listBatchImages', () => {
  it('reads every page of the batch with the largest page size the contract allows', async () => {
    pages = {
      1: { ids: [1, 2], lastPage: 3 },
      2: { ids: [3, 4], lastPage: 3 },
      3: { ids: [5], lastPage: 3 },
    }

    const images = await useImages().listBatchImages(42)

    expect(images.map((image) => image.id)).toEqual([1, 2, 3, 4, 5])
    expect(requested.map((url) => Object.fromEntries(url.searchParams))).toEqual([
      { batch_id: '42', page: '1', per_page: '100' },
      { batch_id: '42', page: '2', per_page: '100' },
      { batch_id: '42', page: '3', per_page: '100' },
    ])
  })

  it('makes a single request for a batch that fits on one page', async () => {
    pages = { 1: { ids: [7], lastPage: 1 } }

    await useImages().listBatchImages(42)

    expect(requested).toHaveLength(1)
  })
})
