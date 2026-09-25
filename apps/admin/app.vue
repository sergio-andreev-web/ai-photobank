<template>
  <div class="min-h-screen bg-gray-50">
    <NuxtRouterEvents @navigate="clearLoading" />
    <NuxtPage />
  </div>
</template>

<script setup lang="ts">
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()

// Initialize auth state on app load
onMounted(async () => {
  if (!auth.user) {
    await auth.checkSession()
  }
})

const clearLoading = () => {
  // Clear any loading states on navigation
}
</script>
