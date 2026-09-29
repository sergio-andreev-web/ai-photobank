import type { BatchStatus, ImageStatus, ImageSummary } from '~/types/api'

/** What happened to the file's upload request (`POST /admin/batches/{id}/images`). */
export type UploadState = 'queued' | 'uploading' | 'uploaded' | 'duplicate' | 'error'

/**
 * The states the Upload screen shows for every file (§52). The mapping from API
 * answers is fixed in contracts/state-machines.md ("Состояния файла на экране Upload").
 */
export type FileState = 'uploading' | 'uploaded' | 'duplicate' | 'processing' | 'ready' | 'failed'

export interface TrackedFile {
  state: UploadState
  /** ID from the 201 upload response; the key used to find the file in the batch's image list. */
  imageId: number | null
  /** Last status seen by polling `GET /admin/images?batch_id=…`; null until a poll has covered the file. */
  imageStatus: ImageStatus | null
  /**
   * The image was missing from a listing fetched after the batch reached a final status, i.e. it was
   * deleted elsewhere. Only used to stop polling for it; the row keeps its last known state.
   */
  imageGone: boolean
}

const FINAL_BATCH_STATUSES: readonly BatchStatus[] = ['completed', 'completed_with_errors', 'failed']

export function isFinalBatchStatus(status: BatchStatus): boolean {
  return FINAL_BATCH_STATUSES.includes(status)
}

/** `ready` and `failed` end processing (contracts/state-machines.md); reprocess/retry starts a new run. */
export function isFinalImageStatus(status: ImageStatus | null): boolean {
  return status === 'ready' || status === 'failed'
}

export function fileState(file: Pick<TrackedFile, 'state' | 'imageStatus'>): FileState {
  switch (file.state) {
    case 'queued':
    case 'uploading':
      return 'uploading'
    case 'duplicate':
      return 'duplicate'
    case 'error':
      // Upload errors (413/415/422/5xx) and a failed processing run share one UI state (§52).
      return 'failed'
    case 'uploaded':
      switch (file.imageStatus) {
        case 'queued':
        case 'processing':
          return 'processing'
        case 'ready':
          return 'ready'
        case 'failed':
          return 'failed'
        default:
          // 201 answered `uploaded`/`queued`; the UI stays on "uploaded" until polling shows the next status.
          return 'uploaded'
      }
  }
}

/** True while the uploaded file's image has not reached `ready`/`failed` yet. */
export function isAwaitingProcessing(file: TrackedFile): boolean {
  return (
    file.state === 'uploaded' &&
    file.imageId !== null &&
    !file.imageGone &&
    !isFinalImageStatus(file.imageStatus)
  )
}

/**
 * Copies the statuses of a batch's image list onto the uploaded files.
 *
 * A file absent from the list is not touched: a listing that is paged while uploads are still coming in
 * may legitimately miss a fresh image. Only when the batch is already final (uploads are closed and
 * nothing is inserted any more) does a missing image mean it was deleted, and polling gives up on it.
 */
export function applyImageStatuses(
  files: TrackedFile[],
  images: Pick<ImageSummary, 'id' | 'status'>[],
  batchFinal: boolean,
): void {
  const statusById = new Map(images.map((image) => [image.id, image.status]))
  for (const file of files) {
    if (file.state !== 'uploaded' || file.imageId === null) continue
    const status = statusById.get(file.imageId)
    if (status !== undefined) {
      file.imageStatus = status
    } else if (batchFinal && !isFinalImageStatus(file.imageStatus)) {
      file.imageGone = true
    }
  }
}
