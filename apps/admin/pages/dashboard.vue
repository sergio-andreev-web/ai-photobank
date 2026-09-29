<template>
  <div>
    <div class="page-heading dashboard-heading">
      <div>
        <span class="subtle-label">ОБЗОР</span>
        <h1>Обзор</h1>
        <p>Состояние библиотеки и то, что требует внимания.</p>
      </div>
      <UButton to="/uploader" icon="i-heroicons-arrow-up-tray" size="lg">Новая загрузка</UButton>
    </div>

    <ErrorAlert v-if="loadError" :message="loadError" />

    <div v-if="dashboard" class="dashboard-stats">
      <NuxtLink to="/gallery" class="surface-card dashboard-stat">
        <span class="dashboard-stat-icon blue"><UIcon name="i-heroicons-photo" /></span>
        <span class="dashboard-stat-label">Всего изображений</span>
        <strong>{{ dashboard.images_count }}</strong>
        <span class="dashboard-stat-caption">В библиотеке</span>
      </NuxtLink>
      <NuxtLink to="/tags" class="surface-card dashboard-stat">
        <span class="dashboard-stat-icon violet"><UIcon name="i-heroicons-tag" /></span>
        <span class="dashboard-stat-label">Теги</span>
        <strong>{{ dashboard.tags_count }}</strong>
        <span class="dashboard-stat-caption">Утверждённые понятия</span>
      </NuxtLink>
      <NuxtLink to="/gallery" class="surface-card dashboard-stat">
        <span class="dashboard-stat-icon amber"><UIcon name="i-heroicons-arrow-path" /></span>
        <span class="dashboard-stat-label">В обработке</span>
        <strong>{{ dashboard.processing_images_count }}</strong>
        <span class="dashboard-stat-caption">Изображения в работе</span>
      </NuxtLink>
      <NuxtLink to="/gallery" class="surface-card dashboard-stat">
        <span class="dashboard-stat-icon rose"><UIcon name="i-heroicons-exclamation-triangle" /></span>
        <span class="dashboard-stat-label">С ошибками</span>
        <strong>{{ dashboard.failed_images_count }}</strong>
        <span class="dashboard-stat-caption">Требуют проверки</span>
      </NuxtLink>
    </div>
    <div v-else-if="!loadError" class="surface-card p-6 text-sm text-gray-500">Загрузка…</div>

    <div class="dashboard-lower">
      <section class="surface-card dashboard-priority">
        <div class="dashboard-section-heading">
          <div><span class="subtle-label">ВХОДЯЩИЕ</span><h2 class="section-title">Требует внимания</h2></div>
          <NuxtLink to="/candidates" class="dashboard-text-link">Открыть очередь <UIcon name="i-heroicons-arrow-right" /></NuxtLink>
        </div>
        <div class="dashboard-priority-body">
          <span class="dashboard-priority-icon"><UIcon name="i-heroicons-sparkles" /></span>
          <div>
            <strong>Кандидатов в теги: {{ dashboard?.pending_candidates_count ?? '—' }}</strong>
            <p>Проверьте подсказки ИИ, чтобы словарь тегов оставался согласованным.</p>
          </div>
          <UButton to="/candidates" variant="soft" class="dashboard-priority-action">Проверить кандидатов</UButton>
        </div>
      </section>

      <section class="surface-card dashboard-actions">
        <span class="subtle-label">БЫСТРЫЙ ДОСТУП</span>
        <h2 class="section-title">Быстрые действия</h2>
        <NuxtLink to="/gallery"><UIcon name="i-heroicons-photo" /> Открыть галерею <UIcon name="i-heroicons-chevron-right" /></NuxtLink>
        <NuxtLink to="/tags"><UIcon name="i-heroicons-tag" /> Управление тегами <UIcon name="i-heroicons-chevron-right" /></NuxtLink>
        <NuxtLink to="/search"><UIcon name="i-heroicons-magnifying-glass" /> Поиск изображений <UIcon name="i-heroicons-chevron-right" /></NuxtLink>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

import type { Dashboard } from '~/types/api'

const api = useApi()
const dashboard = ref<Dashboard | null>(null)
const loadError = ref<string | null>(null)

onMounted(async () => {
  try {
    const { data } = await api.get<Dashboard>('/admin/dashboard')
    dashboard.value = data
  } catch (e) {
    loadError.value = useApiError(e).message
  }
})
</script>
