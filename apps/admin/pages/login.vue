<template>
  <div class="flex min-h-screen items-center justify-center bg-gray-50">
    <div class="w-full max-w-md space-y-8">
      <div class="text-center">
        <h1 class="text-4xl font-bold text-blue-600">📷 Photobank</h1>
        <h2 class="mt-6 text-2xl font-bold text-gray-900">Admin Login</h2>
      </div>

      <form class="space-y-6" @submit.prevent="handleLogin">
        <UFormGroup label="Email" name="email" required>
          <UInput
            v-model="form.email"
            type="email"
            placeholder="admin@example.com"
            autocomplete="email"
          />
        </UFormGroup>

        <UFormGroup label="Password" name="password" required>
          <UInput
            v-model="form.password"
            type="password"
            placeholder="••••••••"
            autocomplete="current-password"
          />
        </UFormGroup>

        <div v-if="auth.error" class="rounded-md bg-red-50 p-4 text-sm text-red-700">
          {{ auth.error }}
        </div>

        <UButton
          type="submit"
          block
          size="lg"
          :loading="auth.loading"
          :disabled="!form.email || !form.password"
        >
          Sign in
        </UButton>
      </form>

    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  layout: 'blank',
})

const auth = useAuthStore()
const router = useRouter()

const form = reactive({
  email: '',
  password: '',
})

async function handleLogin() {
  const success = await auth.login(form)
  if (success) {
    await router.push('/dashboard')
  }
}
</script>
