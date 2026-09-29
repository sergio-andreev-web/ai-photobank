import type { ImageStatus } from '~/types/api'

const IMAGE_STATUSES: readonly ImageStatus[] = ['uploaded', 'queued', 'processing', 'ready', 'failed']

export interface GalleryRouteQuery {
  batchId?: number
  /** ADR-0015: images where this person is identified. */
  personId?: number
  status?: ImageStatus
}

function firstString(value: unknown): string | undefined {
  const first = Array.isArray(value) ? value[0] : value
  return typeof first === 'string' ? first : undefined
}

/**
 * Deep-link filters of the gallery (`/gallery?batch_id=42&status=failed`), e.g. from the batch history.
 * Anything that is not a positive integer id or a known status is dropped rather than sent to the API.
 */
export function readGalleryRouteQuery(query: Record<string, unknown>): GalleryRouteQuery {
  const rawBatch = firstString(query.batch_id)
  const batchId = rawBatch && /^[1-9]\d*$/.test(rawBatch) ? Number(rawBatch) : undefined
  const rawStatus = firstString(query.status)
  const rawPerson = firstString(query.person_id)
  const personId = rawPerson && /^[1-9]\d*$/.test(rawPerson) ? Number(rawPerson) : undefined
  return {
    batchId: batchId !== undefined && Number.isSafeInteger(batchId) ? batchId : undefined,
    status: IMAGE_STATUSES.find((status) => status === rawStatus),
    personId: personId !== undefined && Number.isSafeInteger(personId) ? personId : undefined,
  }
}

/** The gallery filtered to one batch (and optionally one status). */
export function batchGalleryLocation(batchId: number, status?: ImageStatus) {
  return { path: '/gallery', query: status ? { batch_id: String(batchId), status } : { batch_id: String(batchId) } }
}
