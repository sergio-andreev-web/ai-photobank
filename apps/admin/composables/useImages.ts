import type {
  ImageDetail,
  ImageList,
  ImagePatchRequest,
  ImageStatus,
  ImageSummary,
  ImageTag,
  PaginationParams,
} from '~/types/api'

export function useImages() {
  const api = useApi()

  async function listImages(
    params?: PaginationParams & {
      status?: ImageStatus
      batch_id?: number
      tag_ids?: number[]
      created_from?: string
      created_to?: string
    }
  ) {
    const { data } = await api.get<ImageList>('/admin/images', {
      params,
      paramsSerializer: { indexes: null },
    })
    return data
  }

  async function getImage(id: number) {
    const { data } = await api.get<ImageDetail>(`/admin/images/${id}`)
    return data
  }

  async function updateImage(id: number, patch: ImagePatchRequest) {
    const { data } = await api.patch<ImageDetail>(`/admin/images/${id}`, patch)
    return data
  }

  async function deleteImage(id: number) {
    await api.delete(`/admin/images/${id}`)
  }

  /** One file per request (§26); caller runs several of these concurrently. */
  async function uploadImage(batchId: number, file: File, signal?: AbortSignal) {
    const formData = new FormData()
    formData.append('image', file)

    const { data } = await api.post<ImageSummary>(`/admin/batches/${batchId}/images`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      signal,
    })
    return data
  }

  async function reprocessImage(id: number) {
    const { data } = await api.post<ImageDetail>(`/admin/images/${id}/reprocess`)
    return data
  }

  async function retryImage(id: number) {
    const { data } = await api.post<ImageDetail>(`/admin/images/${id}/retry`)
    return data
  }

  async function addTag(imageId: number, tagId: number) {
    const { data } = await api.post<ImageTag>(`/admin/images/${imageId}/tags`, { tag_id: tagId })
    return data
  }

  async function removeTag(imageId: number, tagId: number) {
    await api.delete(`/admin/images/${imageId}/tags/${tagId}`)
  }

  return {
    listImages,
    getImage,
    updateImage,
    deleteImage,
    uploadImage,
    reprocessImage,
    retryImage,
    addTag,
    removeTag,
  }
}
