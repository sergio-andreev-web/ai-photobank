export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()

  if (!auth.user) {
    await auth.checkSession()
  }

  // If still no user and not on login page, redirect to login
  if (!auth.user && to.name !== 'login') {
    return navigateTo('/login')
  }

  // If user exists and on login page, redirect to dashboard
  if (auth.user && to.name === 'login') {
    return navigateTo('/dashboard')
  }
})
