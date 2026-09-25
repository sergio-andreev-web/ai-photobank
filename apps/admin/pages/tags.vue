<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-gray-900">Tags Management</h1>
        <p class="mt-2 text-gray-600">Manage your canonical tags, aliases, and hierarchy.</p>
      </div>
      <UButton @click="showCreateModal = true" icon="i-heroicons-plus">
        New Tag
      </UButton>
    </div>

    <!-- Tag Tree -->
    <div class="rounded-lg bg-white p-6 shadow">
      <h2 class="font-semibold text-gray-900 mb-4">Tags</h2>
      <div v-if="loading" class="text-gray-600">Loading tags...</div>
      <div v-else-if="rootTags.length === 0" class="text-gray-600">No tags yet. Create one to get started.</div>
      <div v-else class="space-y-2">
        <TagTreeNode
          v-for="tag in rootTags"
          :key="tag.id"
          :tag="tag"
          @refresh="loadTags"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { Tag } from '~/types/api'

const api = useApi()
const tags = ref<Tag[]>([])
const loading = ref(false)
const showCreateModal = ref(false)

const rootTags = computed(() => tags.value.filter(t => !t.parent_id))

async function loadTags() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/tags', { params: { per_page: 1000 } })
    tags.value = data.data
  } catch (e) {
    console.error('Failed to load tags:', e)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadTags()
})
</script>
