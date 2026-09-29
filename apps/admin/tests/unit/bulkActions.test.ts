import { describe, it, expect } from 'vitest'
import { isEligible, runBulk, tagsOfSelection } from '~/utils/bulkActions'

const tag = (id: number, name: string) => ({ id, name, type: 'object' as const, status: 'active' as const })

describe('isEligible', () => {
  it('retries failed images only and reprocesses ready or failed ones', () => {
    const statuses = ['uploaded', 'queued', 'processing', 'ready', 'failed'] as const
    expect(statuses.filter(status => isEligible('retry', { status, tags: [] }))).toEqual(['failed'])
    expect(statuses.filter(status => isEligible('reprocess', { status, tags: [] }))).toEqual(['ready', 'failed'])
    expect(statuses.every(status => isEligible('delete', { status, tags: [] }))).toBe(true)
  })

  it('adds a tag only where it is missing and removes it only where it is present', () => {
    const image = { status: 'ready' as const, tags: [tag(1, 'Каска')] }
    expect(isEligible('add-tag', image, 1)).toBe(false)
    expect(isEligible('add-tag', image, 2)).toBe(true)
    expect(isEligible('remove-tag', image, 1)).toBe(true)
    expect(isEligible('remove-tag', image, 2)).toBe(false)
    expect(isEligible('add-tag', image)).toBe(false)
  })
})

describe('tagsOfSelection', () => {
  it('lists each tag once, sorted by name', () => {
    const images = [{ tags: [tag(2, 'Каска'), tag(1, 'Автомобиль')] }, { tags: [tag(2, 'Каска')] }]
    expect(tagsOfSelection(images).map(t => t.id)).toEqual([1, 2])
  })
})

describe('runBulk', () => {
  it('runs every id, collects failures and keeps the input order', async () => {
    const progress: number[] = []
    const result = await runBulk(
      [5, 6, 7, 8],
      async (id) => {
        await new Promise(resolve => setTimeout(resolve, (9 - id) * 2))
        if (id % 2 === 0) throw new Error(`boom ${id}`)
      },
      finished => progress.push(finished),
    )
    expect(result.done).toEqual([5, 7])
    expect(result.failed.map(f => f.id)).toEqual([6, 8])
    expect(progress).toEqual([1, 2, 3, 4])
  })
})
