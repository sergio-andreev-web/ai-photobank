import { describe, it, expect } from 'vitest'
import { keepVisible, pageSelectionState, toggleSelection } from '~/utils/selection'

const PAGE = [10, 11, 12, 13, 14]

describe('toggleSelection', () => {
  it('toggles a single image', () => {
    const on = toggleSelection(new Set(), PAGE, 12)
    expect([...on]).toEqual([12])
    expect([...toggleSelection(on, PAGE, 12)]).toEqual([])
  })

  it('selects the range from the anchor with Shift, in either direction', () => {
    expect([...toggleSelection(new Set([11]), PAGE, 14, { shift: true, anchor: 11 })].sort()).toEqual([11, 12, 13, 14])
    expect([...toggleSelection(new Set([14]), PAGE, 11, { shift: true, anchor: 14 })].sort()).toEqual([11, 12, 13, 14])
  })

  it('Shift on a selected image clears the range', () => {
    const all = new Set(PAGE)
    expect([...toggleSelection(all, PAGE, 13, { shift: true, anchor: 11 })]).toEqual([10, 14])
  })

  it('falls back to a single toggle without a usable anchor', () => {
    expect([...toggleSelection(new Set(), PAGE, 12, { shift: true, anchor: null })]).toEqual([12])
    expect([...toggleSelection(new Set(), PAGE, 12, { shift: true, anchor: 99 })]).toEqual([12])
  })
})

describe('keepVisible / pageSelectionState', () => {
  it('drops ids that left the page', () => {
    expect([...keepVisible(new Set([10, 99]), PAGE)]).toEqual([10])
  })

  it('reports none, some and all', () => {
    expect(pageSelectionState(new Set(), PAGE)).toBe(false)
    expect(pageSelectionState(new Set([10]), PAGE)).toBe('indeterminate')
    expect(pageSelectionState(new Set(PAGE), PAGE)).toBe(true)
    expect(pageSelectionState(new Set(), [])).toBe(false)
  })
})
