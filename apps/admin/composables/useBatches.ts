import type { Batch, BatchList, PaginationParams } from '~/types/api'

export function useBatches() {
  const api = useApi()

  async function listBatches(params?: PaginationParams & { status?: string }) {
    const { data } = await api.get<BatchList>('/admin/batches', { params })
    return data
  }

  async function getBatch(id: number) {
    const { data } = await api.get<Batch>(`/admin/batches/${id}`)
    return data
  }

  async function createBatch(name?: string) {
    const { data } = await api.post<Batch>('/admin/batches', {
      name: name || undefined,
    })
    return data
  }

  async function watchBatch(id: number, onUpdate: (batch: Batch) => void) {
    let lastStatus: string | null = null

    const interval = setInterval(async () => {
      try {
        const batch = await getBatch(id)
        if (batch.status !== lastStatus) {
          lastStatus = batch.status
          onUpdate(batch)
        }
        if (['completed', 'failed'].includes(batch.status)) {
          clearInterval(interval)
        }
      } catch (e) {
        console.error('Failed to poll batch:', e)
        clearInterval(interval)
      }
    }, 2000)

    return () => clearInterval(interval)
  }

  return {
    listBatches,
    getBatch,
    createBatch,
    watchBatch,
  }
}
