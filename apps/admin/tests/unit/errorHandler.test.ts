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
    expect(getErrorMessage(error)).toBe('Такое изображение уже загружено.')
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

  it('reports a plain Error (no response) as a connection problem', () => {
    expect(getErrorMessage(new Error('network down'))).toBe('Нет связи с сервером. Проверьте подключение и повторите.')
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
            details: { fields: { alias: ['The alias field is required.'], locale: 'Неверный код языка.' } },
            trace_id: 'trace-1',
          },
        },
      },
    })

    expect(problem).toMatchObject({
      code: 'VALIDATION_ERROR',
      status: 422,
      message: 'Проверьте введённые данные.',
      traceId: 'trace-1',
      // English server texts are replaced; Russian ones (Laravel localized) are kept.
      fields: { alias: ['Недопустимое значение.'], locale: ['Неверный код языка.'] },
    })
  })

  it('keeps conflict details such as existing_tag_id', () => {
    const problem = toApiProblem(axiosLikeError(409, 'ALIAS_ALREADY_EXISTS', 'Alias already belongs to another tag', { existing_tag_id: 87 }))
    expect(problem.code).toBe('ALIAS_ALREADY_EXISTS')
    expect(problem.details.existing_tag_id).toBe(87)
    expect(problem.fields).toEqual({})
  })

  it('treats an unknown code as a generic error by HTTP status', () => {
    const problem = toApiProblem(axiosLikeError(500, 'SOME_FUTURE_CODE', 'Unexpected'))
    expect(problem).toMatchObject({ code: 'SOME_FUTURE_CODE', status: 500, message: 'Ошибка сервера. Попробуйте ещё раз позже.' })
  })

  it('falls back to the error message when there is no response (network down)', () => {
    expect(toApiProblem(new Error('Network Error'))).toMatchObject({
      code: 'UNKNOWN_ERROR',
      status: null,
      message: 'Нет связи с сервером. Проверьте подключение и повторите.',
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
      'существующий тег: Каска, object (#14) · код запроса t-1',
    )
  })

  it('falls back to the bare id for a tag that is not loaded', () => {
    expect(describeProblem(conflict)).toBe('существующий тег: #14 · код запроса t-1')
  })

  it('lists validation fields the form cannot show inline', () => {
    const problem = toApiProblem({
      response: {
        status: 422,
        data: { error: { code: 'VALIDATION_ERROR', message: 'invalid', details: { fields: { name: ['Too long.'], _unexpected: ['Unexpected field(s): foo'] } }, trace_id: 't-2' } },
      },
    })
    expect(describeProblem(problem, { knownFields: ['name'] })).toBe('_unexpected: Недопустимое значение. · код запроса t-2')
  })

  it('does not print a code for errors without one', () => {
    expect(describeProblem(toApiProblem(new Error('offline')))).toBe('')
  })
})

describe('labels', () => {
  it('uses Russian plural forms', async () => {
    const { pluralRu, IMAGE_FORMS } = await import('~/utils/labels')
    expect([1, 2, 5, 11, 21, 22, 25, 111].map(n => pluralRu(n, IMAGE_FORMS))).toEqual([
      'изображение', 'изображения', 'изображений', 'изображений',
      'изображение', 'изображения', 'изображений', 'изображений',
    ])
  })

  it('shows an unknown enum value as is instead of hiding it', async () => {
    const { labelOf, IMAGE_STATUS_LABELS } = await import('~/utils/labels')
    expect(labelOf(IMAGE_STATUS_LABELS, 'ready')).toBe('Готово')
    expect(labelOf(IMAGE_STATUS_LABELS, 'archived_later')).toBe('archived_later')
  })

  it('names field errors in Russian', () => {
    const problem = toApiProblem({
      response: { status: 422, data: { error: { code: 'VALIDATION_ERROR', message: 'x', details: { fields: { parent_id: ['Поле указано неверно.'] } }, trace_id: 't-3' } } },
    })
    expect(describeProblem(problem)).toBe('Родительский тег: Поле указано неверно. · код запроса t-3')
  })
})
