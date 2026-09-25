<template>
  <div class="flex min-h-screen items-center justify-center bg-gray-50">
    <div class="text-center">
      <h1 class="text-4xl font-bold text-blue-600">📷 AI Photobank</h1>
      <p class="mt-4 text-xl text-gray-600">Admin Dashboard</p>
      <NuxtLink to="/dashboard" class="mt-8 inline-block">
        <UButton size="lg" icon="i-heroicons-arrow-right">
          Go to Dashboard
        </UButton>
      </NuxtLink>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  layout: 'blank',
})

const auth = useAuthStore()
const router = useRouter()

onMounted(async () => {
  // If already logged in, go to dashboard
  if (!auth.user) {
    await auth.checkSession()
  }

  if (auth.user) {
    await router.push('/dashboard')
  } else {
    await router.push('/login')
  }
})
</script>
