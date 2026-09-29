import { describe, it, expect } from 'vitest'
import { describeProblem, getErrorCode, getErrorMessage, getHttpStatus, isRetryableError, toApiProblem } from '~/utils/errorHandler'

function axiosLikeError(status: number, code: string, message: string, details: Record<string, unknown> = {}) {
  return {
    response: {
      status,
      data: { error: { code, message, details, trace_id: 't-1' } },
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

describe('toApiProblem', () => {
  it('flattens a contract error with details and validation fields', () => {
    const problem = toApiProblem({
      response: {
        status: 422,
        data: {
          error: {
            code: 'VALIDATION_ERROR',
            message: 'The given data was invalid',
            details: { fields: { alias: ['The alias field is required.'], locale: 'Bad locale' } },
            trace_id: 'trace-1',
          },
        },
      },
    })

    expect(problem).toMatchObject({
      code: 'VALIDATION_ERROR',
      status: 422,
      message: 'The given data was invalid',
      traceId: 'trace-1',
      fields: { alias: ['The alias field is required.'], locale: ['Bad locale'] },
    })
  })

  it('keeps conflict details such as existing_tag_id', () => {
    const problem = toApiProblem(axiosLikeError(409, 'ALIAS_ALREADY_EXISTS', 'Alias already belongs to another tag', { existing_tag_id: 87 }))
    expect(problem.code).toBe('ALIAS_ALREADY_EXISTS')
    expect(problem.details.existing_tag_id).toBe(87)
    expect(problem.fields).toEqual({})
  })

  it('treats an unknown code as a plain error keeping its message', () => {
    const problem = toApiProblem(axiosLikeError(500, 'SOME_FUTURE_CODE', 'Unexpected'))
    expect(problem).toMatchObject({ code: 'SOME_FUTURE_CODE', status: 500, message: 'Unexpected' })
  })

  it('falls back to the error message when there is no response (network down)', () => {
    expect(toApiProblem(new Error('Network Error'))).toMatchObject({
      code: 'UNKNOWN_ERROR',
      status: null,
      message: 'Network Error',
      traceId: null,
    })
  })

  it('survives a non-JSON error body and non-error values', () => {
    expect(toApiProblem({ response: { status: 502, data: '<html>Bad gateway</html>' } })).toMatchObject({
      code: 'UNKNOWN_ERROR',
      status: 502,
    })
    expect(toApiProblem(undefined).code).toBe('UNKNOWN_ERROR')
    expect(toApiProblem('boom').message).toBeTruthy()
  })
})

describe('describeProblem', () => {
  const conflict = toApiProblem(axiosLikeError(409, 'TAG_ALREADY_EXISTS', 'Tag already exists', { existing_tag_id: 14 }))

  it('names the conflicting tag when the catalog knows it', () => {
    expect(describeProblem(conflict, { lookupTag: id => (id === 14 ? 'Каска, object' : null) })).toBe(
      'TAG_ALREADY_EXISTS · existing tag: Каска, object (#14) · trace t-1',
    )
  })

  it('falls back to the bare id for a tag that is not loaded', () => {
    expect(describeProblem(conflict)).toBe('TAG_ALREADY_EXISTS · existing tag: #14 · trace t-1')
  })

  it('lists validation fields the form cannot show inline', () => {
    const problem = toApiProblem({
      response: {
        status: 422,
        data: { error: { code: 'VALIDATION_ERROR', message: 'invalid', details: { fields: { name: ['Too long.'], _unexpected: ['Unexpected field(s): foo'] } }, trace_id: 't-2' } },
      },
    })
    expect(describeProblem(problem, { knownFields: ['name'] })).toBe('VALIDATION_ERROR · _unexpected: Unexpected field(s): foo · trace t-2')
  })

  it('does not print a code for errors without one', () => {
    expect(describeProblem(toApiProblem(new Error('offline')))).toBe('')
  })
})
