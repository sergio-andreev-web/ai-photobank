<template>
  <div class="login-shell">
    <section class="login-intro" aria-label="About Photobank">
      <div class="login-brand"><span class="login-brand-mark"><UIcon name="i-heroicons-photo" /></span><strong>Photobank</strong></div>
      <div class="login-intro-copy">
        <span class="login-kicker">YOUR MEDIA WORKSPACE</span>
        <h1>Everything in focus.</h1>
        <p>Organize your image library, review AI suggestions, and keep every asset ready to find.</p>
      </div>
      <span class="login-intro-footer">PHOTOBANK ADMIN STUDIO</span>
    </section>

    <div class="login-form-side">
      <div class="login-card">
        <span class="login-form-mark"><UIcon name="i-heroicons-lock-closed" /></span>
        <p class="login-kicker">WELCOME BACK</p>
        <h2>Admin Login</h2>
        <p class="login-subtitle">Sign in to manage your photobank.</p>

      <form class="login-form" @submit.prevent="handleLogin">
        <UFormField label="Email" name="email" required>
          <UInput
            v-model="form.email"
            type="email"
            placeholder="admin@example.com"
            autocomplete="email"
            size="lg"
            class="w-full"
          />
        </UFormField>

        <UFormField label="Password" name="password" required>
          <UInput
            v-model="form.password"
            type="password"
            placeholder="••••••••"
            autocomplete="current-password"
            size="lg"
            class="w-full"
          />
        </UFormField>

        <div v-if="auth.error" role="alert" class="rounded-md bg-red-50 p-4 text-sm text-red-700">
          {{ auth.error }}
        </div>

        <UButton
          type="submit"
          block
          size="lg"
          :loading="auth.loading"
          :disabled="!form.email || !form.password"
        >
          Sign in to Photobank
        </UButton>
      </form>
      </div>
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
