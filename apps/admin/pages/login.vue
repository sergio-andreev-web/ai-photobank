<template>
  <div class="login-shell">
    <section class="login-intro" aria-label="О фотобанке">
      <div class="login-brand"><span class="login-brand-mark"><UIcon name="i-heroicons-photo" /></span><strong>Фотобанк</strong></div>
      <div class="login-intro-copy">
        <span class="login-kicker">ВАША МЕДИАТЕКА</span>
        <h1>Всё в фокусе.</h1>
        <p>Упорядочивайте библиотеку изображений, проверяйте подсказки ИИ и находите любой снимок за секунды.</p>
      </div>
      <span class="login-intro-footer">ФОТОБАНК · АДМИН-ПАНЕЛЬ</span>
    </section>

    <div class="login-form-side">
      <div class="login-card">
        <span class="login-form-mark"><UIcon name="i-heroicons-lock-closed" /></span>
        <p class="login-kicker">С ВОЗВРАЩЕНИЕМ</p>
        <h2>Вход администратора</h2>
        <p class="login-subtitle">Войдите, чтобы управлять фотобанком.</p>

      <form class="login-form" @submit.prevent="handleLogin">
        <UFormField label="Электронная почта" name="email" required>
          <UInput
            v-model="form.email"
            type="email"
            placeholder="admin@example.com"
            autocomplete="email"
            size="lg"
            class="w-full"
          />
        </UFormField>

        <UFormField label="Пароль" name="password" required>
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
          Войти
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
