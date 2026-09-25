<template>
  <div>
    <div class="page-heading dashboard-heading">
      <div>
        <span class="subtle-label">WORKSPACE OVERVIEW</span>
        <h1>Dashboard</h1>
        <p>A clear view of your library and what needs attention.</p>
      </div>
      <UButton to="/uploader" icon="i-heroicons-arrow-up-tray" size="lg">New Batch Upload</UButton>
    </div>

    <ErrorAlert v-if="loadError" :message="loadError" />

    <div v-if="dashboard" class="dashboard-stats">
      <NuxtLink to="/gallery" class="surface-card dashboard-stat">
        <span class="dashboard-stat-icon blue"><UIcon name="i-heroicons-photo" /></span>
        <span class="dashboard-stat-label">Total Images</span>
        <strong>{{ dashboard.images_count }}</strong>
        <span class="dashboard-stat-caption">Assets in your library</span>
      </NuxtLink>
      <NuxtLink to="/tags" class="surface-card dashboard-stat">
        <span class="dashboard-stat-icon violet"><UIcon name="i-heroicons-tag" /></span>
        <span class="dashboard-stat-label">Tags</span>
        <strong>{{ dashboard.tags_count }}</strong>
        <span class="dashboard-stat-caption">Organized concepts</span>
      </NuxtLink>
      <NuxtLink to="/gallery" class="surface-card dashboard-stat">
        <span class="dashboard-stat-icon amber"><UIcon name="i-heroicons-arrow-path" /></span>
        <span class="dashboard-stat-label">Processing</span>
        <strong>{{ dashboard.processing_images_count }}</strong>
        <span class="dashboard-stat-caption">Images in progress</span>
      </NuxtLink>
      <NuxtLink to="/gallery" class="surface-card dashboard-stat">
        <span class="dashboard-stat-icon rose"><UIcon name="i-heroicons-exclamation-triangle" /></span>
        <span class="dashboard-stat-label">Failed</span>
        <strong>{{ dashboard.failed_images_count }}</strong>
        <span class="dashboard-stat-caption">Require a review</span>
      </NuxtLink>
    </div>
    <div v-else-if="!loadError" class="surface-card p-6 text-sm text-gray-500">Loading dashboard...</div>

    <div class="dashboard-lower">
      <section class="surface-card dashboard-priority">
        <div class="dashboard-section-heading">
          <div><span class="subtle-label">INBOX</span><h2 class="section-title">Needs your attention</h2></div>
          <NuxtLink to="/candidates" class="dashboard-text-link">Open queue <UIcon name="i-heroicons-arrow-right" /></NuxtLink>
        </div>
        <div class="dashboard-priority-body">
          <span class="dashboard-priority-icon"><UIcon name="i-heroicons-sparkles" /></span>
          <div>
            <strong>{{ dashboard?.pending_candidates_count ?? '—' }} tag candidates</strong>
            <p>Review AI suggestions and keep your taxonomy consistent.</p>
          </div>
          <UButton to="/candidates" variant="soft">Review Candidates</UButton>
        </div>
      </section>

      <section class="surface-card dashboard-actions">
        <span class="subtle-label">SHORTCUTS</span>
        <h2 class="section-title">Quick Actions</h2>
        <NuxtLink to="/gallery"><UIcon name="i-heroicons-photo" /> View Gallery <UIcon name="i-heroicons-chevron-right" /></NuxtLink>
        <NuxtLink to="/tags"><UIcon name="i-heroicons-tag" /> Manage Tags <UIcon name="i-heroicons-chevron-right" /></NuxtLink>
        <NuxtLink to="/search"><UIcon name="i-heroicons-magnifying-glass" /> Search Images <UIcon name="i-heroicons-chevron-right" /></NuxtLink>
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
