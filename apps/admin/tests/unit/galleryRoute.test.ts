import { describe, it, expect } from 'vitest'
import { batchGalleryLocation, readGalleryRouteQuery } from '~/utils/galleryRoute'

describe('readGalleryRouteQuery', () => {
  it('reads a batch id and a known status', () => {
    expect(readGalleryRouteQuery({ batch_id: '42', status: 'failed' })).toEqual({ batchId: 42, status: 'failed', personId: undefined })
    expect(readGalleryRouteQuery({ batch_id: ['7', '8'] })).toEqual({ batchId: 7, status: undefined, personId: undefined })
    expect(readGalleryRouteQuery({ person_id: '5' }).personId).toBe(5)
    expect(readGalleryRouteQuery({ person_id: 'x' }).personId).toBeUndefined()
  })

  it('drops invalid values', () => {
    expect(readGalleryRouteQuery({ batch_id: '0', status: 'deleted' })).toEqual({ batchId: undefined, status: undefined, personId: undefined })
    expect(readGalleryRouteQuery({ batch_id: '4.2' }).batchId).toBeUndefined()
    expect(readGalleryRouteQuery({ batch_id: '-3' }).batchId).toBeUndefined()
    expect(readGalleryRouteQuery({ batch_id: '99999999999999999999' }).batchId).toBeUndefined()
    expect(readGalleryRouteQuery({})).toEqual({ batchId: undefined, status: undefined, personId: undefined })
  })
})

describe('batchGalleryLocation', () => {
  it('builds the deep link', () => {
    expect(batchGalleryLocation(42)).toEqual({ path: '/gallery', query: { batch_id: '42' } })
    expect(batchGalleryLocation(42, 'failed')).toEqual({ path: '/gallery', query: { batch_id: '42', status: 'failed' } })
  })
})
