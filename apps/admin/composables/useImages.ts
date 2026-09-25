import type { Image, ImageDetail, ImageList, PaginationParams } from '~/types/api'

export function useImages() {
  const api = useApi()

  async function listImages(params?: PaginationParams & { status?: string; batch_id?: number }) {
    const { data } = await api.get<ImageList>('/admin/images', { params })
    return data
  }

  async function getImage(id: number) {
    const { data } = await api.get<ImageDetail>(`/admin/images/${id}`)
    return data
  }

  async function uploadImage(batchId: number, file: File) {
    const formData = new FormData()
    formData.append('file', file)

    const { data } = await api.post<Image>(
      `/admin/batches/${batchId}/images`,
      formData,
      {
        headers: { 'Content-Type': 'multipart/form-data' },
      }
    )
    return data
  }

  async function addTag(imageId: number, tagId: number) {
    const { data } = await api.post(`/admin/images/${imageId}/tags`, { tag_id: tagId })
    return data
  }

  async function removeTag(imageId: number, imageTagId: number) {
    await api.delete(`/admin/images/${imageId}/tags/${imageTagId}`)
  }

  async function retryImage(imageId: number) {
    const { data } = await api.post<ImageDetail>(`/admin/images/${imageId}/retry`)
    return data
  }

  return {
    listImages,
    getImage,
    uploadImage,
    addTag,
    removeTag,
    retryImage,
  }
}
