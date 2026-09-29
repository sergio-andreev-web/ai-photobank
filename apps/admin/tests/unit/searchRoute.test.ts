import { describe, it, expect } from 'vitest'
import { readSearchRouteQuery, semanticSearchLocation } from '~/utils/searchRoute'

describe('readSearchRouteQuery', () => {
  it('reads mode and q from the route query', () => {
    expect(readSearchRouteQuery({ mode: 'semantic', q: 'workers in hard hats' })).toEqual({
      mode: 'semantic',
      q: 'workers in hard hats',
    })
  })

  it('accepts every search mode of the contract', () => {
    for (const mode of ['tags', 'semantic', 'hybrid'] as const) {
      expect(readSearchRouteQuery({ mode }).mode).toBe(mode)
    }
  })

  it('drops an unknown mode instead of passing it on', () => {
    expect(readSearchRouteQuery({ mode: 'fulltext', q: 'x' })).toEqual({ mode: undefined, q: 'x' })
  })

  it('takes the first value of a repeated parameter and ignores non-strings', () => {
    expect(readSearchRouteQuery({ mode: ['semantic', 'tags'], q: ['first', 'second'] })).toEqual({
      mode: 'semantic',
      q: 'first',
    })
    expect(readSearchRouteQuery({ mode: null, q: [null] })).toEqual({ mode: undefined, q: undefined })
  })

  it('returns nothing for a plain /search', () => {
    expect(readSearchRouteQuery({})).toEqual({ mode: undefined, q: undefined })
  })
})

describe('semanticSearchLocation', () => {
  it('targets the search page in semantic mode with the trimmed query', () => {
    expect(semanticSearchLocation('  мужчина в каске  ')).toEqual({
      path: '/search',
      query: { mode: 'semantic', q: 'мужчина в каске' },
    })
  })

  it('round-trips through readSearchRouteQuery', () => {
    const { query } = semanticSearchLocation('a & b = c')
    expect(readSearchRouteQuery(query)).toEqual({ mode: 'semantic', q: 'a & b = c' })
  })
})
