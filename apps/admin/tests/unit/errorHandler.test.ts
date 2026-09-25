import { describe, it, expect } from 'vitest'
import { getErrorCode, getErrorMessage, getHttpStatus, isRetryableError } from '~/utils/errorHandler'

function axiosLikeError(status: number, code: string, message: string) {
  return {
    response: {
      status,
      data: { error: { code, message, details: {}, trace_id: 't-1' } },
    },
  }
}

describe('errorHandler', () => {
  it('extracts the contract error code from a duplicate-upload response', () => {
    const error = axiosLikeError(409, 'IMAGE_ALREADY_EXISTS', 'Image already exists')
    expect(getErrorCode(error)).toBe('IMAGE_ALREADY_EXISTS')
    expect(getErrorMessage(error)).toBe('Image already exists')
    expect(getHttpStatus(error)).toBe(409)
  })

  it('treats an unrecognized error code as a generic error (contract §51)', () => {
    const error = axiosLikeError(500, 'SOME_FUTURE_CODE', 'Unexpected')
    expect(getErrorCode(error)).toBe('SOME_FUTURE_CODE')
    // Client logic must fall back to HTTP status / generic handling, not crash.
    expect(isRetryableError(getErrorCode(error))).toBe(false)
  })

  it('flags AI_SERVICE_UNAVAILABLE and TOO_MANY_REQUESTS as retryable', () => {
    expect(isRetryableError('AI_SERVICE_UNAVAILABLE')).toBe(true)
    expect(isRetryableError('TOO_MANY_REQUESTS')).toBe(true)
    expect(isRetryableError('IMAGE_NOT_FOUND')).toBe(false)
  })

  it('falls back to a generic message for a plain Error', () => {
    expect(getErrorMessage(new Error('network down'))).toBe('network down')
    expect(getErrorCode(new Error('network down'))).toBe('UNKNOWN_ERROR')
  })
})
