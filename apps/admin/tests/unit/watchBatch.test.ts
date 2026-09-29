import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Batch, BatchStatus } from '~/types/api'
import { useBatches, type BatchTracker } from '~/composables/useBatches'

function batch(status: BatchStatus): Batch {
  return {
    id: 42,
    name: null,
    status,
    progress: { total: 2, processed: 0, failed: 0 },
    created_at: '2026-09-25T13:43:55Z',
    updated_at: '2026-09-25T13:43:55Z',
    completed_at: null,
  }
}

const get = vi.fn()

function respondWith(...statuses: BatchStatus[]) {
  statuses.forEach((status) => get.mockResolvedValueOnce({ data: batch(status) }))
  // Anything beyond the scripted answers repeats the last one.
  get.mockResolvedValue({ data: batch(statuses[statuses.length - 1]!) })
}

function watch(tracker?: BatchTracker) {
  const onUpdate = vi.fn()
  const stop = useBatches().watchBatch(42, onUpdate, { tracker })
  return { onUpdate, stop }
}

beforeEach(() => {
  vi.useFakeTimers()
  get.mockReset()
  // Nuxt auto-imports useApi(); outside Nuxt it has to be provided.
  vi.stubGlobal('useApi', () => ({ get }))
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('watchBatch without a tracker', () => {
  it('polls the batch every 2 seconds and stops at a final status', async () => {
    respondWith('uploading', 'processing', 'completed')
    const { onUpdate } = watch()

    expect(get).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1999)
    expect(get).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)
    expect(get).toHaveBeenCalledWith('/admin/batches/42')
    await vi.advanceTimersByTimeAsync(2000)
    await vi.advanceTimersByTimeAsync(2000)
    expect(onUpdate.mock.calls.map(([b]) => b.status)).toEqual(['uploading', 'processing', 'completed'])

    await vi.advanceTimersByTimeAsync(20000)
    expect(get).toHaveBeenCalledTimes(3)
  })

  it('keeps polling after a failed request', async () => {
    get.mockRejectedValueOnce(new Error('network down'))
    get.mockResolvedValue({ data: batch('completed') })
    const { onUpdate } = watch()

    await vi.advanceTimersByTimeAsync(2000)
    expect(onUpdate).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(2000)
    expect(onUpdate).toHaveBeenCalledTimes(1)
    expect(get).toHaveBeenCalledTimes(2)
  })

  it('stops polling when the returned function is called', async () => {
    respondWith('uploading')
    const { stop } = watch()

    await vi.advanceTimersByTimeAsync(2000)
    stop()
    await vi.advanceTimersByTimeAsync(10000)

    expect(get).toHaveBeenCalledTimes(1)
  })
})

describe('watchBatch with a tracker', () => {
  function tracker(pendingAfterRefresh: (refreshes: number) => boolean, initiallyPending = true) {
    let refreshes = 0
    let pending = initiallyPending
    return {
      hasPending: vi.fn(() => pending),
      refresh: vi.fn(async () => {
        refreshes += 1
        pending = pendingAfterRefresh(refreshes)
      }),
    } satisfies BatchTracker
  }

  it('reloads the tracked files on every poll while any is pending', async () => {
    respondWith('uploading')
    const t = tracker(() => true)
    watch(t)

    await vi.advanceTimersByTimeAsync(2000)
    await vi.advanceTimersByTimeAsync(2000)
    await vi.advanceTimersByTimeAsync(2000)

    expect(t.refresh).toHaveBeenCalledTimes(3)
  })

  it('hands the freshly fetched batch to refresh, after onUpdate', async () => {
    respondWith('processing')
    const order: string[] = []
    const t = tracker(() => false)
    t.refresh.mockImplementation(async (b: Batch) => {
      order.push(`refresh:${b.status}`)
    })
    const onUpdate = vi.fn(() => order.push('update'))
    useBatches().watchBatch(42, onUpdate, { tracker: t })

    await vi.advanceTimersByTimeAsync(2000)

    expect(order).toEqual(['update', 'refresh:processing'])
  })

  it('does not load files when nothing is pending', async () => {
    respondWith('uploading')
    const t = tracker(() => false, false)
    watch(t)

    await vi.advanceTimersByTimeAsync(2000)
    await vi.advanceTimersByTimeAsync(2000)

    expect(get).toHaveBeenCalledTimes(2)
    expect(t.refresh).not.toHaveBeenCalled()
  })

  it('keeps polling a final batch until the last tracked file is settled', async () => {
    respondWith('completed')
    // Still pending after the first refresh, settled after the second.
    const t = tracker((refreshes) => refreshes < 2)
    watch(t)

    await vi.advanceTimersByTimeAsync(2000)
    expect(t.refresh).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(2000)
    expect(t.refresh).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(20000)
    expect(get).toHaveBeenCalledTimes(2)
    expect(t.refresh).toHaveBeenCalledTimes(2)
  })

  it('does not stop for settled files while the batch is not final', async () => {
    respondWith('uploading')
    const t = tracker(() => false)
    const { onUpdate } = watch(t)

    for (let i = 0; i < 5; i++) await vi.advanceTimersByTimeAsync(2000)

    expect(onUpdate).toHaveBeenCalledTimes(5)
  })

  it('survives a failing refresh and tries again on the next poll', async () => {
    respondWith('completed')
    let calls = 0
    const t = {
      hasPending: vi.fn(() => calls < 2),
      refresh: vi.fn(async () => {
        calls += 1
        if (calls === 1) throw new Error('images endpoint down')
      }),
    } satisfies BatchTracker
    watch(t)

    await vi.advanceTimersByTimeAsync(2000)
    await vi.advanceTimersByTimeAsync(2000)

    expect(t.refresh).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(20000)
    expect(get).toHaveBeenCalledTimes(2)
  })

  it('never overlaps ticks: the next poll is scheduled after a slow refresh finished', async () => {
    respondWith('uploading')
    const t = {
      hasPending: vi.fn(() => true),
      refresh: vi.fn(() => new Promise<void>((resolve) => setTimeout(resolve, 5000))),
    } satisfies BatchTracker
    watch(t)

    await vi.advanceTimersByTimeAsync(2000) // tick 1 starts, refresh runs until 7000
    await vi.advanceTimersByTimeAsync(4000) // 6000: nothing else may have started
    expect(get).toHaveBeenCalledTimes(1)
    expect(t.refresh).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(1000) // 7000: refresh done, next tick due at 9000
    await vi.advanceTimersByTimeAsync(1999)
    expect(get).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(1)
    expect(get).toHaveBeenCalledTimes(2)
  })

  it('ignores results that arrive after stop()', async () => {
    respondWith('completed')
    let finishRefresh: () => void = () => {}
    const t = {
      hasPending: vi.fn(() => true),
      refresh: vi.fn(() => new Promise<void>((resolve) => (finishRefresh = resolve))),
    } satisfies BatchTracker
    const { stop } = watch(t)

    await vi.advanceTimersByTimeAsync(2000)
    stop()
    finishRefresh()
    await vi.advanceTimersByTimeAsync(20000)

    expect(get).toHaveBeenCalledTimes(1)
  })
})
