<template>
  <UApp>
    <div :data-app-ready="hydrated">
      <NuxtLayout>
        <NuxtPage />
      </NuxtLayout>
    </div>
  </UApp>
</template>

<script setup lang="ts">
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()

// False during SSR and until the client has hydrated and attached event
// listeners; e2e tests wait on this so they never interact with a form
// before v-model bindings are live (see tests/e2e/helpers.ts). It is set on a
// real wrapper <div>: UApp renders no element of its own, so an attribute
// passed to <UApp> never reaches the DOM and the tests would wait forever.
const hydrated = ref(false)

onMounted(async () => {
  hydrated.value = true
  if (!auth.user) {
    await auth.checkSession()
  }
})
</script>
