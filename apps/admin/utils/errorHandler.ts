import type { ApiError } from '~/types/api'

export function getErrorMessage(error: unknown): string {
  if (typeof error === 'string') return error

  if (error && typeof error === 'object') {
    if ('response' in error && error.response) {
      const data = (error.response as any).data as ApiError | undefined
      return data?.error?.message || 'An error occurred'
    }
    if ('message' in error) {
      return String(error.message)
    }
  }

  return 'An unexpected error occurred'
}

export function getErrorCode(error: unknown): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const data = (error.response as any).data as ApiError | undefined
    return data?.error?.code || 'UNKNOWN_ERROR'
  }
  return 'UNKNOWN_ERROR'
}

export function isRetryableError(code: string): boolean {
  const retryableCodes = [
    'AI_SERVICE_UNAVAILABLE',
    'TOO_MANY_REQUESTS',
    'INTERNAL_ERROR',
  ]
  return retryableCodes.includes(code)
}

export function getHttpStatus(error: unknown): number | null {
  if (error && typeof error === 'object' && 'response' in error) {
    return (error.response as any).status || null
  }
  return null
}
