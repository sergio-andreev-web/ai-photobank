import { describe, it, expect } from 'vitest'
import { faceCropStyle } from '~/utils/faceCrop'

describe('faceCropStyle', () => {
  it('scales the padded face box to the element and positions it', () => {
    const style = faceCropStyle('/p.webp', { x: 0.4, y: 0.2, width: 0.1, height: 0.2 }, 0)
    expect(style.backgroundImage).toBe('url("/p.webp")')
    expect(style.backgroundSize).toBe('1000% 500%')
    // x 0.4 over the 0.9 free width → 44.4%; y 0.2 over 0.8 → 25%.
    expect(parseFloat(style.backgroundPosition!.split(' ')[0]!)).toBeCloseTo(44.444, 2)
    expect(style.backgroundPosition!.split(' ')[1]).toBe('25%')
  })

  it('keeps the padded box inside the picture', () => {
    const style = faceCropStyle(null, { x: 0, y: 0.9, width: 0.2, height: 0.1 })
    expect(style.backgroundImage).toBe('none')
    expect(style.backgroundPosition!.split(' ')[1]).toBe('100%')
    expect(style.backgroundPosition!.split(' ')[0]).toBe('0%')
  })
})
