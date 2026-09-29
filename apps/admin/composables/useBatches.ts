import type { Batch, BatchList, BatchStatus, PaginationParams } from '~/types/api'
import { isFinalBatchStatus } from '~/utils/uploadTracking'

/** Lets `watchBatch` follow the individual files of a batch as well as its counters (§52). */
export interface BatchTracker {
  /** True while some tracked file has not reached a final status. */
  hasPending(): boolean
  /** Reloads the tracked files; only called while `hasPending()` is true, after the batch itself was fetched. */
  refresh(batch: Batch): Promise<void>
}

export interface WatchBatchOptions {
  intervalMs?: number
  tracker?: BatchTracker
}

export function useBatches() {
  const api = useApi()

  async function listBatches(params?: PaginationParams & { status?: BatchStatus }) {
    const { data } = await api.get<BatchList>('/admin/batches', { params })
    return data
  }

  async function getBatch(id: number) {
    const { data } = await api.get<Batch>(`/admin/batches/${id}`)
    return data
  }

  async function createBatch(name?: string | null) {
    const { data } = await api.post<Batch>('/admin/batches', {
      name: name || undefined,
    })
    return data
  }

  async function completeBatch(id: number) {
    const { data } = await api.post<Batch>(`/admin/batches/${id}/complete`)
    return data
  }

  /**
   * Polls GET /admin/batches/{id} every ~2s (§49). Returns a stop function; onUpdate fires on every poll,
   * not only on change, so callers can drive progress bars directly off it.
   *
   * With a `tracker`, every poll also refreshes the tracked files (per-file processing state, §52) while
   * any of them is still pending. Ticks run one after another (the next one is scheduled when the
   * previous finished), and polling stops only once the batch is final AND nothing is pending: a batch
   * counter can turn final in the same tick in which the last file changes state.
   * Without a tracker it stops as soon as the batch is final.
   */
  function watchBatch(id: number, onUpdate: (batch: Batch) => void, options: WatchBatchOptions = {}) {
    const { intervalMs = 2000, tracker } = options
    let stopped = false
    let timer: ReturnType<typeof setTimeout> | undefined

    const poll = async () => {
      if (stopped) return
      try {
        const batch = await getBatch(id)
        if (stopped) return
        onUpdate(batch)
        if (tracker?.hasPending()) {
          await tracker.refresh(batch)
          if (stopped) return
        }
        if (isFinalBatchStatus(batch.status) && !tracker?.hasPending()) {
          stopped = true
          return
        }
      } catch (e) {
        // Transient polling failures (network, 5xx) are not surfaced; the loop keeps retrying
        // until stop() is called.
      }
      if (!stopped) {
        timer = setTimeout(poll, intervalMs)
      }
    }

    timer = setTimeout(poll, intervalMs)

    return () => {
      stopped = true
      clearTimeout(timer)
    }
  }

  return {
    listBatches,
    getBatch,
    createBatch,
    completeBatch,
    watchBatch,
  }
}
