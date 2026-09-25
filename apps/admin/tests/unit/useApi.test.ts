import { describe, it, expect } from 'vitest'
import { AxiosError } from 'axios'
import { useApiError } from '~/composables/useApi'

describe('useApiError', () => {
  it('returns the contract code and message from an Axios error', () => {
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
      message: 'Invalid email or password',
    })
  })

  it('keeps the message from an unexpected Error', () => {
    expect(useApiError(new Error('Network failed'))).toEqual({
      code: 'UNKNOWN_ERROR',
      message: 'Network failed',
    })
  })
})
