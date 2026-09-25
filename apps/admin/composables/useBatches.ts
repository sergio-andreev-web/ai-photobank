import type { Batch, BatchList, BatchStatus, PaginationParams } from '~/types/api'

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
   * Polls GET /admin/batches/{id} every ~2s (§49) until a final status is reached.
   * Returns a stop function; onUpdate fires on every poll, not only on change,
   * so callers can drive progress bars directly off it.
   */
  function watchBatch(id: number, onUpdate: (batch: Batch) => void, intervalMs = 2000) {
    const finalStatuses: BatchStatus[] = ['completed', 'completed_with_errors', 'failed']
    let stopped = false

    const poll = async () => {
      if (stopped) return
      try {
        const batch = await getBatch(id)
        if (stopped) return
        onUpdate(batch)
        if (finalStatuses.includes(batch.status)) {
          stopped = true
          return
        }
      } catch (e) {
        // Transient polling failures are surfaced to the caller via onUpdate's
        // absence; the loop keeps retrying until stop() is called.
      }
      if (!stopped) {
        timer = setTimeout(poll, intervalMs)
      }
    }

    let timer = setTimeout(poll, intervalMs)

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
