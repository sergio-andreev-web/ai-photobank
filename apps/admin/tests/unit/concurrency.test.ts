import { describe, it, expect } from 'vitest'
import { runWithConcurrency } from '~/utils/concurrency'

describe('runWithConcurrency', () => {
  it('processes every item exactly once', async () => {
    const items = Array.from({ length: 11 }, (_, i) => i)
    const seen: number[] = []

    await runWithConcurrency(items, 4, async (item) => {
      seen.push(item)
    })

    expect(seen.slice().sort((a, b) => a - b)).toEqual(items)
  })

  it('never runs more than `limit` workers at once', async () => {
    const items = Array.from({ length: 10 }, (_, i) => i)
    let active = 0
    let maxActive = 0

    await runWithConcurrency(items, 3, async () => {
      active++
      maxActive = Math.max(maxActive, active)
      await new Promise((resolve) => setTimeout(resolve, 1))
      active--
    })

    expect(maxActive).toBeLessThanOrEqual(3)
  })

  it('propagates a worker error instead of swallowing it', async () => {
    const items = [1, 2, 3, 4]

    await expect(
      runWithConcurrency(items, 2, async (item) => {
        if (item === 2) throw new Error('boom')
      })
    ).rejects.toThrow('boom')
  })

  it('caps concurrency to the number of items when limit is larger', async () => {
    const items = [1, 2]
    let concurrent = 0
    let maxConcurrent = 0

    await runWithConcurrency(items, 10, async () => {
      concurrent++
      maxConcurrent = Math.max(maxConcurrent, concurrent)
      await Promise.resolve()
      concurrent--
    })

    expect(maxConcurrent).toBeLessThanOrEqual(2)
  })
})
