import { defineStore } from 'pinia'
import type { User, LoginRequest } from '~/types/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const api = useApi()

  async function getCsrfToken() {
    try {
      await api.get('/sanctum/csrf-cookie', { baseURL: '/' })
    } catch (e) {
      console.error('Failed to get CSRF token:', e)
    }
  }

  async function login(credentials: LoginRequest) {
    loading.value = true
    error.value = null

    try {
      await getCsrfToken()
      const { data } = await api.post<User>('/auth/login', credentials)
      user.value = data
      return true
    } catch (e) {
      const { message } = useApiError(e)
      error.value = message
      return false
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    loading.value = true
    try {
      await api.post('/auth/logout')
      user.value = null
    } catch (e) {
      console.error('Logout error:', e)
    } finally {
      loading.value = false
    }
  }

  async function checkSession() {
    try {
      const { data } = await api.get<User>('/auth/me')
      user.value = data
    } catch (e) {
      user.value = null
    }
  }

  return {
    user: readonly(user),
    loading: readonly(loading),
    error: readonly(error),
    login,
    logout,
    checkSession,
  }
})
