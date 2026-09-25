<template>
  <div class="space-y-8">
    <div>
      <h1 class="text-3xl font-bold text-gray-900">Dashboard</h1>
      <p class="mt-2 text-gray-600">Welcome back! Here's an overview of your photobank.</p>
    </div>

    <!-- Metrics Grid -->
    <div v-if="dashboard" class="grid gap-6 md:grid-cols-5">
      <div class="rounded-lg bg-white p-6 shadow">
        <p class="text-sm text-gray-600">Total Images</p>
        <p class="mt-2 text-3xl font-bold text-gray-900">{{ dashboard.images_count }}</p>
      </div>

      <div class="rounded-lg bg-white p-6 shadow">
        <p class="text-sm text-gray-600">Tags</p>
        <p class="mt-2 text-3xl font-bold text-gray-900">{{ dashboard.tags_count }}</p>
      </div>

      <div class="rounded-lg bg-white p-6 shadow">
        <p class="text-sm text-gray-600">Processing</p>
        <p class="mt-2 text-3xl font-bold text-orange-600">{{ dashboard.processing_images_count }}</p>
      </div>

      <div class="rounded-lg bg-white p-6 shadow">
        <p class="text-sm text-gray-600">Failed</p>
        <p class="mt-2 text-3xl font-bold text-red-600">{{ dashboard.failed_images_count }}</p>
      </div>

      <div class="rounded-lg bg-white p-6 shadow">
        <p class="text-sm text-gray-600">Pending Candidates</p>
        <p class="mt-2 text-3xl font-bold text-blue-600">{{ dashboard.pending_candidates_count }}</p>
      </div>
    </div>

    <div v-else class="rounded-lg bg-white p-6 shadow">
      <p class="text-gray-600">Loading dashboard...</p>
    </div>

    <!-- Quick Actions -->
    <div class="rounded-lg bg-white p-6 shadow">
      <h2 class="text-lg font-semibold text-gray-900">Quick Actions</h2>
      <div class="mt-4 flex flex-wrap gap-3">
        <UButton to="/uploader" icon="i-heroicons-arrow-up-tray">
          New Batch Upload
        </UButton>
        <UButton to="/gallery" variant="soft" icon="i-heroicons-photo">
          View Gallery
        </UButton>
        <UButton to="/tags" variant="soft" icon="i-heroicons-tag">
          Manage Tags
        </UButton>
        <UButton to="/candidates" variant="soft" icon="i-heroicons-inbox-stack">
          Review Candidates
        </UButton>
        <UButton to="/search" variant="soft" icon="i-heroicons-magnifying-glass">
          Search Images
        </UButton>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { Dashboard } from '~/types/api'

const api = useApi()
const dashboard = ref<Dashboard | null>(null)

onMounted(async () => {
  try {
    const { data } = await api.get<Dashboard>('/admin/dashboard')
    dashboard.value = data
  } catch (e) {
    console.error('Failed to load dashboard:', e)
  }
})
</script>
