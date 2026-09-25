<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-bold text-gray-900">Gallery</h1>
        <p class="mt-2 text-gray-600">Browse and manage your images.</p>
      </div>
      <UButton to="/uploader" icon="i-heroicons-arrow-up-tray"> Upload Images </UButton>
    </div>

    <!-- Filters -->
    <div class="rounded-lg bg-white p-4 shadow">
      <div class="flex flex-wrap gap-4">
        <UFormField label="Status" class="flex-1 min-w-[200px]">
          <USelect v-model="filters.status" :items="statusOptions" />
        </UFormField>

        <UFormField label="Batch" class="flex-1 min-w-[200px]">
          <USelect v-model="filters.batchId" :items="batchOptions" />
        </UFormField>
      </div>
    </div>

    <ErrorAlert v-if="loadError" :message="loadError" @dismiss="loadError = null" />

    <!-- Image Grid -->
    <div v-if="loading" class="text-center text-gray-600">Loading images...</div>

    <div v-else-if="images.length === 0" class="rounded-lg bg-white p-12 text-center shadow">
      <p class="text-gray-600">No images found. Start by uploading a batch.</p>
      <UButton to="/uploader" class="mt-4"> Create First Batch </UButton>
    </div>

    <div v-else class="grid gap-4 md:grid-cols-4">
      <NuxtLink
        v-for="image in images"
        :key="image.id"
        :to="`/gallery/${image.id}`"
        class="group block overflow-hidden rounded-lg bg-white shadow transition-shadow hover:shadow-lg"
      >
        <div class="aspect-square overflow-hidden bg-gray-200">
          <img
            v-if="image.urls.thumbnail"
            :src="image.urls.thumbnail"
            :alt="image.original_name"
            class="h-full w-full object-cover"
          />
          <div v-else class="flex h-full w-full items-center justify-center bg-gray-100 text-gray-400">📷</div>
        </div>
        <div class="p-4">
          <p class="truncate text-sm font-medium text-gray-900">{{ image.original_name }}</p>
          <UBadge :color="statusColor(image.status)" class="mt-1">{{ image.status }}</UBadge>
        </div>
      </NuxtLink>
    </div>

    <!-- Pagination -->
    <div v-if="pagination && pagination.last_page > 1" class="flex justify-center gap-2">
      <UButton v-if="pagination.page > 1" variant="ghost" @click="loadImages(pagination.page - 1)"> Previous </UButton>
      <span class="flex items-center text-sm text-gray-600"> Page {{ pagination.page }} of {{ pagination.last_page }} </span>
      <UButton v-if="pagination.page < pagination.last_page" variant="ghost" @click="loadImages(pagination.page + 1)">
        Next
      </UButton>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { Batch, ImageStatus, ImageSummary, PaginationMeta } from '~/types/api'

// Nuxt UI v3 reserves an empty string `value` on a SelectItem to mean "clear
// selection" and throws if an item uses it, so "no filter" needs its own
// sentinel instead of ''.
const ALL = 'all' as const

const images = ref<ImageSummary[]>([])
const batches = ref<Batch[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)
const pagination = ref<PaginationMeta | null>(null)

const filters = reactive<{ status: ImageStatus | typeof ALL; batchId: number | typeof ALL }>({
  status: ALL,
  batchId: ALL,
})

const statusOptions: { label: string; value: ImageStatus | typeof ALL }[] = [
  { label: 'All', value: ALL },
  { label: 'Uploaded', value: 'uploaded' },
  { label: 'Queued', value: 'queued' },
  { label: 'Processing', value: 'processing' },
  { label: 'Ready', value: 'ready' },
  { label: 'Failed', value: 'failed' },
]

const batchOptions = computed(() => [
  { label: 'All batches', value: ALL },
  ...batches.value.map((b) => ({ label: b.name || `Batch #${b.id}`, value: b.id })),
])

function statusColor(status: ImageStatus): string {
  const colors: Record<ImageStatus, string> = {
    uploaded: 'gray',
    queued: 'amber',
    processing: 'blue',
    ready: 'green',
    failed: 'red',
  }
  return colors[status] || 'gray'
}

const imagesApi = useImages()
const batchesApi = useBatches()

async function loadImages(page = 1) {
  loading.value = true
  loadError.value = null
  try {
    const data = await imagesApi.listImages({
      page,
      per_page: 24,
      status: filters.status === ALL ? undefined : filters.status,
      batch_id: filters.batchId === ALL ? undefined : filters.batchId,
    })
    images.value = data.data
    pagination.value = data.meta
  } catch (e) {
    loadError.value = useApiError(e).message
  } finally {
    loading.value = false
  }
}

async function loadBatches() {
  try {
    const data = await batchesApi.listBatches({ per_page: 100 })
    batches.value = data.data
  } catch (e) {
    // Batch filter is best-effort; the grid still works without it.
  }
}

watch([() => filters.status, () => filters.batchId], () => loadImages())

onMounted(() => {
  loadImages()
  loadBatches()
})
</script>
