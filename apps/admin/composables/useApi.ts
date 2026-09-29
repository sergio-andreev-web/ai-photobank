import axios from 'axios'
import type { AxiosInstance, AxiosError } from 'axios'
import type { ApiError } from '~/types/api'
import { errorText } from '~/utils/labels'

let instance: AxiosInstance | null = null

export function useApi() {
  const config = useRuntimeConfig()

  if (!instance) {
    instance = axios.create({
      baseURL: config.public.apiBaseUrl,
      withCredentials: true, // For cookie-based sessions
      headers: {
        'Content-Type': 'application/json',
      },
    })

    // Add CSRF token header for mutating requests
    instance.interceptors.request.use((cfg) => {
      if (['post', 'patch', 'delete'].includes(cfg.method?.toLowerCase() || '')) {
        const token = getCookie('XSRF-TOKEN')
        if (token) {
          cfg.headers['X-XSRF-TOKEN'] = token
        }
      }
      return cfg
    })

    // Handle errors uniformly
    instance.interceptors.response.use(
      (response) => response,
      (error: AxiosError<ApiError>) => {
        // Errors are handled by composables that call this
        return Promise.reject(error)
      }
    )
  }

  return instance
}

function getCookie(name: string): string | undefined {
  if (typeof document === 'undefined') return undefined
  const value = `; ${document.cookie}`
  const parts = value.split(`; ${name}=`)
  if (parts.length === 2) {
    const raw = parts.pop()?.split(';').shift()
    return raw ? decodeURIComponent(raw) : undefined
  }
}

/** `message` is the Russian UI text for the error code (utils/labels.ts), never the API's own text. */
export function useApiError(error: unknown): { code: string; message: string } {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as ApiError | undefined
    const code = data?.error?.code || 'UNKNOWN_ERROR'
    return { code, message: errorText(code, error.response?.status ?? null) }
  }
  return { code: 'UNKNOWN_ERROR', message: errorText(null, null) }
}
