import { describe, it, expect } from 'vitest'
import { AxiosError } from 'axios'
import { useApiError } from '~/composables/useApi'

describe('useApiError', () => {
  it('returns the contract code and a Russian text for it, not the API message', () => {
    const error = new AxiosError('Request failed')
    error.response = {
      data: {
        error: {
          code: 'INVALID_CREDENTIALS',
          message: 'Invalid email or password',
          details: {},
          trace_id: '123-456',
        },
      },
    } as AxiosError['response']

    expect(useApiError(error)).toEqual({
      code: 'INVALID_CREDENTIALS',
      message: 'Неверный email или пароль.',
    })
  })

  it('reports a missing response as a connection problem', () => {
    expect(useApiError(new Error('Network failed'))).toEqual({
      code: 'UNKNOWN_ERROR',
      message: 'Нет связи с сервером. Проверьте подключение и повторите.',
    })
  })

  it('falls back to the HTTP status for an unknown code (contracts/README.md)', () => {
    const error = new AxiosError('Request failed')
    error.response = {
      status: 503,
      data: { error: { code: 'SOME_FUTURE_CODE', message: 'Later', details: {}, trace_id: 't' } },
    } as AxiosError['response']
    expect(useApiError(error)).toEqual({ code: 'SOME_FUTURE_CODE', message: 'Ошибка сервера. Попробуйте ещё раз позже.' })
  })
})
