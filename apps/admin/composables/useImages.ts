import type {
  ImageDetail,
  ImageList,
  ImagePatchRequest,
  ImageStatus,
  ImageSummary,
  ImageTag,
  PaginationParams,
  TrashedImageList,
} from '~/types/api'
import { fetchAllPages } from '~/utils/pagination'

/** Largest page the contract allows for `GET /admin/images` (`PerPage` parameter). */
const IMAGES_MAX_PER_PAGE = 100

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
      // The contract (`TagIdsFilter`) wants Laravel's array form `tag_ids[]=1&tag_ids[]=2`. With
      // `indexes: null` axios drops the brackets (`tag_ids=1&tag_ids=2`), which Laravel reads as the
      // scalar `2` and rejects the request with 422.
      paramsSerializer: { indexes: false },
    })
    return data
  }

  /** Every image of a batch, across all pages. */
  async function listBatchImages(batchId: number) {
    return fetchAllPages<ImageSummary>((page) =>
      listImages({ batch_id: batchId, page, per_page: IMAGES_MAX_PER_PAGE })
    )
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

  /** GET /admin/images/trash (ADR-0013): soft-deleted images, most recently deleted first. */
  async function listTrash(params?: PaginationParams) {
    const { data } = await api.get<TrashedImageList>('/admin/images/trash', { params })
    return data
  }

  /** POST /admin/images/{image}/restore (ADR-0013); idempotent. */
  async function restoreImage(id: number) {
    const { data } = await api.post<ImageDetail>(`/admin/images/${id}/restore`)
    return data
  }

  /** GET /admin/images/{image}/similar (ADR-0012): neighbours by image embedding, best first. */
  async function listSimilar(id: number, params?: PaginationParams) {
    const { data } = await api.get<ImageList>(`/admin/images/${id}/similar`, { params })
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
    listBatchImages,
    getImage,
    updateImage,
    deleteImage,
    listSimilar,
    listTrash,
    restoreImage,
    uploadImage,
    reprocessImage,
    retryImage,
    addTag,
    removeTag,
  }
}
