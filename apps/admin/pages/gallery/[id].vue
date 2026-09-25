<template>
  <div class="space-y-6">
    <NuxtLink to="/gallery" class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-700">
      <UIcon name="i-heroicons-arrow-left" /> Back to Gallery
    </NuxtLink>

    <ErrorAlert v-if="loadError" title="Failed to load image" :message="loadError" @dismiss="loadError = null" />
    <ErrorAlert v-if="actionError" :message="actionError" @dismiss="actionError = null" />

    <div v-if="loading && !image" class="text-center text-gray-600">Loading image details...</div>

    <div v-else-if="image" class="surface-card p-6 md:p-8">
      <div class="grid gap-8 lg:grid-cols-2">
        <!-- Image Preview -->
        <div class="space-y-4">
          <div class="flex aspect-[4/3] items-center justify-center overflow-hidden rounded-xl bg-slate-100">
            <img
              v-if="image.urls.preview"
              :src="image.urls.preview"
              :alt="image.original_name"
              class="h-full w-full object-contain"
            />
            <div v-else class="text-6xl text-slate-300"><UIcon name="i-heroicons-photo" /></div>
          </div>
          <div>
            <p class="subtle-label">FILENAME</p>
            <p class="mt-1 text-xl font-semibold text-gray-900 break-all">{{ image.original_name }}</p>
          </div>
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <p class="text-gray-600">Status</p>
              <UBadge :color="statusColor(image.status)" class="mt-1">{{ image.status }}</UBadge>
            </div>
            <div>
              <p class="text-gray-600">Dimensions</p>
              <p class="text-gray-900">{{ image.width && image.height ? `${image.width} × ${image.height}` : '—' }}</p>
            </div>
            <div>
              <p class="text-gray-600">Size</p>
              <p class="text-gray-900">{{ formatSize(image.file_size) }}</p>
            </div>
            <div>
              <p class="text-gray-600">Created</p>
              <p class="text-gray-900">{{ formatDate(image.created_at) }}</p>
            </div>
          </div>

          <div v-if="image.status === 'failed'" class="rounded-md bg-red-50 p-3 text-sm text-red-700">
            <p class="font-medium">{{ image.error_code }}</p>
            <p v-if="image.error_message">{{ image.error_message }}</p>
          </div>

          <div class="flex gap-2">
            <UButton
              v-if="image.status === 'failed'"
              color="warning"
              :loading="actionLoading === 'retry'"
              @click="handleRetry"
            >
              Retry Processing
            </UButton>
            <UButton
              v-if="image.status === 'ready' || image.status === 'failed'"
              variant="soft"
              :loading="actionLoading === 'reprocess'"
              @click="handleReprocess"
            >
              Reprocess
            </UButton>
          </div>
        </div>

        <!-- Image Details -->
        <div class="space-y-6">
          <div v-if="image.caption || image.ocr_text" class="rounded-xl bg-slate-50 p-5">
            <h3 class="section-title">Metadata</h3>
            <div class="mt-2 space-y-2 text-sm text-gray-600">
              <p v-if="image.caption">Caption: {{ image.caption }}</p>
              <p v-if="image.ocr_text">OCR text: {{ image.ocr_text }}</p>
            </div>
          </div>

          <!-- Image Tags -->
          <div class="rounded-xl border border-slate-200 p-5">
            <div class="flex items-center justify-between">
              <h3 class="section-title">Tags</h3>
              <UButton size="sm" variant="soft" @click="() => { showAddTag = !showAddTag }"> Add Tag </UButton>
            </div>

            <div v-if="showAddTag" class="mt-2 flex gap-2">
              <USelectMenu
                v-model="tagToAdd"
                :items="tagOptions"
                value-key="value"
                placeholder="Select a tag"
                class="flex-1"
                @update:search-term="onTagSearch"
              />
              <UButton :disabled="tagToAdd == null" :loading="actionLoading === 'add-tag'" @click="handleAddTag">
                Add
              </UButton>
            </div>

            <div v-if="image.image_tags.length" class="mt-2 flex flex-wrap gap-2" data-testid="image-tags">
              <UBadge
                v-for="imageTag in image.image_tags"
                :key="imageTag.tag.id"
                variant="soft"
                :color="imageTag.source === 'manual' ? 'success' : 'info'"
                class="flex items-center gap-1"
              >
                {{ imageTag.tag.name }}
                <span class="text-xs">({{ imageTag.source }})</span>
                <button
                  type="button"
                  class="ml-1 text-xs opacity-70 hover:opacity-100"
                  :aria-label="`Remove ${imageTag.tag.name}`"
                  @click="handleRemoveTag(imageTag.tag.id)"
                >
                  ✕
                </button>
              </UBadge>
            </div>
            <p v-else class="mt-2 text-sm text-gray-600">No tags assigned yet.</p>
          </div>

          <!-- AI concepts -->
          <div v-if="image.latest_analysis?.concepts?.length">
            <h3 class="section-title">AI Concepts</h3>
            <div class="mt-2 flex flex-wrap gap-2">
              <UBadge v-for="concept in image.latest_analysis.concepts" :key="concept.label" variant="subtle">
                {{ concept.label }} ({{ Math.round(concept.confidence * 100) }}%)
              </UBadge>
            </div>
          </div>

          <!-- Processing Tasks -->
          <div v-if="image.processing_tasks.length">
            <h3 class="section-title">Processing Tasks</h3>
            <div class="mt-2 space-y-2">
              <div v-for="task in image.processing_tasks" :key="task.type" class="text-sm">
                <p class="font-medium text-gray-900">{{ task.type }}</p>
                <p class="text-xs text-gray-600">
                  Status: {{ task.status }} ({{ task.attempts }}/{{ task.max_attempts }} attempts)
                  <span v-if="task.error_code" class="text-red-600">({{ task.error_code }})</span>
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { ImageDetail, ImageStatus, Tag } from '~/types/api'

const route = useRoute()
const imagesApi = useImages()
const tagsApi = useTags()

const image = ref<ImageDetail | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const actionError = ref<string | null>(null)
const actionLoading = ref<'retry' | 'reprocess' | 'add-tag' | 'remove-tag' | null>(null)

const showAddTag = ref(false)
const tagToAdd = ref<number | undefined>()
const tagOptions = ref<{ label: string; value: number }[]>([])

function statusColor(status: ImageStatus) {
  const colors = {
    uploaded: 'neutral',
    queued: 'warning',
    processing: 'info',
    ready: 'success',
    failed: 'error',
  } as const
  return colors[status]
}

function formatDate(date: string): string {
  return new Date(date).toLocaleString()
}

function formatSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

async function loadImage() {
  loading.value = true
  loadError.value = null
  try {
    image.value = await imagesApi.getImage(Number(route.params.id))
  } catch (e) {
    loadError.value = useApiError(e).message
  } finally {
    loading.value = false
  }
}

async function onTagSearch(query: string) {
  try {
    const data = await tagsApi.listTags({ q: query || undefined, per_page: 20, status: 'active' })
    tagOptions.value = data.data
      .filter((tag: Tag) => !image.value?.image_tags.some((it) => it.tag.id === tag.id))
      .map((tag: Tag) => ({ value: tag.id, label: `${tag.name} (${tag.type})` }))
  } catch (e) {
    // Search box degrades gracefully to an empty list on failure.
  }
}

async function handleAddTag() {
  if (!image.value || !tagToAdd.value) return
  actionLoading.value = 'add-tag'
  actionError.value = null
  try {
    await imagesApi.addTag(image.value.id, tagToAdd.value)
    tagToAdd.value = undefined
    showAddTag.value = false
    await loadImage()
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionLoading.value = null
  }
}

async function handleRemoveTag(tagId: number) {
  if (!image.value) return
  actionLoading.value = 'remove-tag'
  actionError.value = null
  try {
    await imagesApi.removeTag(image.value.id, tagId)
    await loadImage()
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionLoading.value = null
  }
}

async function handleRetry() {
  if (!image.value) return
  actionLoading.value = 'retry'
  actionError.value = null
  try {
    image.value = await imagesApi.retryImage(image.value.id)
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionLoading.value = null
  }
}

async function handleReprocess() {
  if (!image.value) return
  actionLoading.value = 'reprocess'
  actionError.value = null
  try {
    image.value = await imagesApi.reprocessImage(image.value.id)
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionLoading.value = null
  }
}

onMounted(() => {
  loadImage()
  onTagSearch('')
})
</script>
