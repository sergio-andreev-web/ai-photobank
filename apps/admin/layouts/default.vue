<template>
  <div class="min-h-screen bg-gray-50">
    <nav class="bg-white shadow">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 justify-between">
          <div class="flex items-center">
            <NuxtLink to="/dashboard" class="text-2xl font-bold text-blue-600">
              📷 Photobank
            </NuxtLink>
          </div>

          <div class="flex items-center gap-4">
            <span v-if="auth.user" class="text-sm text-gray-600">
              {{ auth.user.email }}
            </span>
            <UButton v-if="auth.user" color="gray" variant="ghost" @click="handleLogout">
              Logout
            </UButton>
          </div>
        </div>
      </div>
    </nav>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <slot />
    </main>
  </div>
</template>

<script setup lang="ts">
const auth = useAuthStore()

async function handleLogout() {
  await auth.logout()
  navigateTo('/login')
}
</script>
