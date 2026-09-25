<template>
  <div class="min-h-screen bg-gray-50" :data-app-ready="hydrated">
    <NuxtPage />
  </div>
</template>

<script setup lang="ts">
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()

// False during SSR and until the client has hydrated and attached event
// listeners; e2e tests wait on this so they never interact with a form
// before v-model bindings are live (see tests/e2e/helpers.ts).
const hydrated = ref(false)

onMounted(async () => {
  hydrated.value = true
  if (!auth.user) {
    await auth.checkSession()
  }
})
</script>
