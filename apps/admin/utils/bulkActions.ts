import type { ImageStatus, ImageSummary, TagRef } from '~/types/api'
import { runWithConcurrency } from '~/utils/concurrency'

/**
 * Bulk actions over the gallery selection. The public API has per-image operations only, so a
 * bulk action is one request per image, a few at a time; each image succeeds or fails on its own.
 */
export const BULK_CONCURRENCY = 4

export type BulkActionKind = 'delete' | 'retry' | 'reprocess' | 'add-tag' | 'remove-tag'

/** Which selected images an action applies to (contracts/public-api.openapi.yaml, ADR-0003). */
export function isEligible(kind: BulkActionKind, image: Pick<ImageSummary, 'status' | 'tags'>, tagId?: number): boolean {
  switch (kind) {
    case 'retry':
      // retryImage: only `failed`, otherwise 409 IMAGE_NOT_RETRYABLE.
      return image.status === 'failed'
    case 'reprocess':
      // reprocessImage on queued/processing is a no-op (202 with the current state).
      return (['ready', 'failed'] as ImageStatus[]).includes(image.status)
    case 'add-tag':
      return tagId !== undefined && !image.tags.some(tag => tag.id === tagId)
    case 'remove-tag':
      // removeImageTag on an image without the tag is 404 IMAGE_TAG_NOT_FOUND.
      return tagId !== undefined && image.tags.some(tag => tag.id === tagId)
    case 'delete':
      return true
  }
}

/** Tags present on at least one selected image, by name: the choices for "remove tag". */
export function tagsOfSelection(images: readonly Pick<ImageSummary, 'tags'>[]): TagRef[] {
  const byId = new Map<number, TagRef>()
  for (const image of images) for (const tag of image.tags) byId.set(tag.id, tag)
  return [...byId.values()].sort((a, b) => a.name.localeCompare(b.name, 'ru'))
}

export interface BulkFailure {
  id: number
  error: unknown
}

export interface BulkResult {
  done: number[]
  failed: BulkFailure[]
}

export async function runBulk(
  ids: readonly number[],
  worker: (id: number) => Promise<unknown>,
  onProgress?: (finished: number, total: number) => void,
): Promise<BulkResult> {
  const result: BulkResult = { done: [], failed: [] }
  let finished = 0
  await runWithConcurrency([...ids], BULK_CONCURRENCY, async (id) => {
    try {
      await worker(id)
      result.done.push(id)
    } catch (error) {
      result.failed.push({ id, error })
    }
    onProgress?.(++finished, ids.length)
  })
  // Stable order for messages and for keeping failed images selected.
  result.done.sort((a, b) => ids.indexOf(a) - ids.indexOf(b))
  result.failed.sort((a, b) => ids.indexOf(a.id) - ids.indexOf(b.id))
  return result
}
