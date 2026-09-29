import { describe, it, expect } from 'vitest'
import {
  applyImageStatuses,
  fileState,
  isAwaitingProcessing,
  isFinalBatchStatus,
  isFinalImageStatus,
  type FileState,
  type TrackedFile,
} from '~/utils/uploadTracking'

function file(overrides: Partial<TrackedFile> = {}): TrackedFile {
  return { state: 'uploaded', imageId: 1, imageStatus: null, imageGone: false, ...overrides }
}

describe('fileState', () => {
  const cases: [string, Partial<TrackedFile>, FileState][] = [
    ['a file waiting for a free upload slot', { state: 'queued' }, 'uploading'],
    ['a request in flight', { state: 'uploading' }, 'uploading'],
    ['a 409 IMAGE_ALREADY_EXISTS answer', { state: 'duplicate' }, 'duplicate'],
    ['an upload error (413/415/422/5xx)', { state: 'error' }, 'failed'],
    ['a 201 before any poll', { state: 'uploaded', imageStatus: null }, 'uploaded'],
    ['an image that is still `uploaded` on the server', { state: 'uploaded', imageStatus: 'uploaded' }, 'uploaded'],
    ['an image waiting for the AI worker', { state: 'uploaded', imageStatus: 'queued' }, 'processing'],
    ['an image being analysed', { state: 'uploaded', imageStatus: 'processing' }, 'processing'],
    ['an analysed image', { state: 'uploaded', imageStatus: 'ready' }, 'ready'],
    ['an image whose processing failed', { state: 'uploaded', imageStatus: 'failed' }, 'failed'],
  ]

  it.each(cases)('shows %s as %s', (_label, overrides, expected) => {
    expect(fileState(file(overrides))).toBe(expected)
  })

  it('ignores a polled image status for files that were never stored', () => {
    expect(fileState(file({ state: 'duplicate', imageStatus: 'ready' }))).toBe('duplicate')
    expect(fileState(file({ state: 'error', imageStatus: 'ready' }))).toBe('failed')
  })
})

describe('final statuses', () => {
  it('treats ready and failed images as final', () => {
    expect(isFinalImageStatus('ready')).toBe(true)
    expect(isFinalImageStatus('failed')).toBe(true)
    for (const status of ['uploaded', 'queued', 'processing', null] as const) {
      expect(isFinalImageStatus(status)).toBe(false)
    }
  })

  it('treats completed, completed_with_errors and failed batches as final', () => {
    for (const status of ['completed', 'completed_with_errors', 'failed'] as const) {
      expect(isFinalBatchStatus(status)).toBe(true)
    }
    for (const status of ['created', 'uploading', 'processing'] as const) {
      expect(isFinalBatchStatus(status)).toBe(false)
    }
  })
})

describe('isAwaitingProcessing', () => {
  it('is true for an uploaded file whose image has not reached ready/failed', () => {
    expect(isAwaitingProcessing(file())).toBe(true)
    expect(isAwaitingProcessing(file({ imageStatus: 'queued' }))).toBe(true)
    expect(isAwaitingProcessing(file({ imageStatus: 'processing' }))).toBe(true)
  })

  it('is false once the image is final, gone, or was never stored', () => {
    expect(isAwaitingProcessing(file({ imageStatus: 'ready' }))).toBe(false)
    expect(isAwaitingProcessing(file({ imageStatus: 'failed' }))).toBe(false)
    expect(isAwaitingProcessing(file({ imageGone: true }))).toBe(false)
    expect(isAwaitingProcessing(file({ imageId: null }))).toBe(false)
    expect(isAwaitingProcessing(file({ state: 'duplicate', imageId: null }))).toBe(false)
    expect(isAwaitingProcessing(file({ state: 'error', imageId: null }))).toBe(false)
    expect(isAwaitingProcessing(file({ state: 'uploading', imageId: null }))).toBe(false)
  })
})

describe('applyImageStatuses', () => {
  it('copies the status of each listed image onto the file with the same image id', () => {
    const files = [file({ imageId: 10 }), file({ imageId: 11 }), file({ imageId: 12 })]

    applyImageStatuses(
      files,
      [
        { id: 12, status: 'failed' },
        { id: 10, status: 'processing' },
        { id: 11, status: 'ready' },
        { id: 99, status: 'ready' }, // uploaded by someone else into the same batch
      ],
      false,
    )

    expect(files.map((f) => f.imageStatus)).toEqual(['processing', 'ready', 'failed'])
  })

  it('leaves files that are not uploaded (yet) alone', () => {
    const uploading = file({ state: 'uploading', imageId: null })
    const duplicate = file({ state: 'duplicate', imageId: null })
    const failed = file({ state: 'error', imageId: null })

    applyImageStatuses([uploading, duplicate, failed], [{ id: 1, status: 'ready' }], true)

    expect([uploading, duplicate, failed].map((f) => [f.imageStatus, f.imageGone])).toEqual([
      [null, false],
      [null, false],
      [null, false],
    ])
  })

  it('does not treat a missing image as deleted while the batch can still change', () => {
    const pending = file({ imageId: 10 })

    applyImageStatuses([pending], [], false)

    expect(pending.imageGone).toBe(false)
    expect(isAwaitingProcessing(pending)).toBe(true)
  })

  it('marks an image missing from the listing of a final batch as gone, so polling can stop', () => {
    const removed = file({ imageId: 10, imageStatus: 'processing' })
    const untouched = file({ imageId: 11, imageStatus: 'ready' })

    applyImageStatuses([removed, untouched], [], true)

    expect(removed.imageGone).toBe(true)
    expect(removed.imageStatus).toBe('processing')
    expect(untouched.imageGone).toBe(false)
  })
})
