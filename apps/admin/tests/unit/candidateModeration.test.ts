import { describe, it, expect } from 'vitest'
import { approveRequest, draftError, draftFor, isStrongMatch, moderationKey } from '~/utils/candidateModeration'

const candidate = { display_name: 'drilling platform', type: 'object' as const }

describe('approveRequest', () => {
  it('sends no body when nothing changed', () => {
    expect(approveRequest(candidate, draftFor(candidate))).toBeUndefined()
    expect(approveRequest(candidate, { ...draftFor(candidate), name: '  drilling platform ' })).toBeUndefined()
  })

  it('sends only the changed fields', () => {
    expect(approveRequest(candidate, { name: ' Буровая платформа ', type: 'object', parent_id: null })).toEqual({ name: 'Буровая платформа' })
    expect(approveRequest(candidate, { name: 'drilling platform', type: 'scene', parent_id: 3 })).toEqual({ type: 'scene', parent_id: 3 })
  })
})

describe('draftError', () => {
  it('requires a name within the tag limit', () => {
    expect(draftError({ name: '  ', type: 'object', parent_id: null })).toBe('Введите название тега.')
    expect(draftError({ name: 'x'.repeat(256), type: 'object', parent_id: null })).toBe('Не больше 255 символов.')
    expect(draftError({ name: 'Каска', type: 'object', parent_id: null })).toBeNull()
  })
})

describe('isStrongMatch', () => {
  const tag = { id: 1, name: 'white', type: 'color' as const, status: 'active' as const }
  it('needs a closest tag with high similarity', () => {
    expect(isStrongMatch({ closest_tag: tag, similarity: 0.82 })).toBe(true)
    expect(isStrongMatch({ closest_tag: tag, similarity: 0.6 })).toBe(false)
    expect(isStrongMatch({ closest_tag: null, similarity: null })).toBe(false)
  })
})

describe('moderationKey', () => {
  const key = (k: string, extra: Record<string, unknown> = {}) =>
    moderationKey({ key: k, ctrlKey: false, metaKey: false, altKey: false, target: { tagName: 'BODY', closest: () => null } as never, ...extra })

  it('maps shortcuts on both keyboard layouts', () => {
    expect(['ArrowDown', 'j', 'о', 'ArrowUp', 'k', 'л', 'Enter', 'r', 'к', 'm', 'ь', 'x', 'ч'].map(k => key(k))).toEqual([
      'next', 'next', 'next', 'prev', 'prev', 'prev', 'approve', 'reject', 'reject', 'merge', 'merge', 'select', 'select',
    ])
    expect(key('q')).toBeNull()
  })

  it('ignores typing in fields, open menus and modifier combos', () => {
    expect(key('r', { target: { tagName: 'INPUT' } })).toBeNull()
    expect(key('Enter', { target: { tagName: 'DIV', closest: () => ({}) } })).toBeNull()
    expect(key('r', { metaKey: true })).toBeNull()
    expect(key('Enter', { target: { tagName: 'BUTTON', closest: () => null } })).toBeNull()
    expect(key('r', { target: { tagName: 'BUTTON', closest: () => null } })).toBe('reject')
  })
})
