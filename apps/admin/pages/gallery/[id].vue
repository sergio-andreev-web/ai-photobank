<template>
  <div class="space-y-6">
    <NuxtLink to="/gallery" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700">
      ← Back to Gallery
    </NuxtLink>

    <div v-if="image" class="rounded-lg bg-white p-6 shadow">
      <div class="grid gap-6 md:grid-cols-2">
        <!-- Image Preview -->
        <div class="space-y-4">
          <div class="aspect-square rounded-lg bg-gray-200 flex items-center justify-center">
            <div class="text-6xl">📷</div>
          </div>
          <div>
            <p class="text-sm text-gray-600">Filename</p>
            <p class="text-lg font-medium text-gray-900">{{ image.filename }}</p>
          </div>
          <div>
            <p class="text-sm text-gray-600">Status</p>
            <UBadge :color="getStatusColor(image.status)" class="mt-1">
              {{ image.status }}
            </UBadge>
          </div>
        </div>

        <!-- Image Details -->
        <div class="space-y-6">
          <!-- Metadata -->
          <div>
            <h3 class="font-semibold text-gray-900">Metadata</h3>
            <div class="mt-2 space-y-2 text-sm text-gray-600">
              <p>Created: {{ formatDate(image.created_at) }}</p>
              <p v-if="image.caption">Caption: {{ image.caption }}</p>
              <p v-if="image.extracted_text">Text: {{ image.extracted_text }}</p>
            </div>
          </div>

          <!-- Image Tags -->
          <div>
            <div class="flex items-center justify-between">
              <h3 class="font-semibold text-gray-900">Tags</h3>
              <UButton
                v-if="image.status === 'completed'"
                size="sm"
                variant="soft"
                @click="showAddTagModal = true"
              >
                Add Tag
              </UButton>
            </div>
            <div v-if="image.image_tags?.length" class="mt-2 flex flex-wrap gap-2">
              <UBadge
                v-for="tag in image.image_tags"
                :key="tag.id"
                variant="soft"
                :color="tag.reason === 'auto' ? 'blue' : 'green'"
              >
                {{ tag.tag?.name }}
                <span class="text-xs ml-1">({{ tag.reason }})</span>
              </UBadge>
            </div>
            <p v-else class="mt-2 text-sm text-gray-600">No tags assigned yet.</p>
          </div>

          <!-- Tag Candidates -->
          <div v-if="image.tag_candidates?.length">
            <h3 class="font-semibold text-gray-900">Suggested Tags</h3>
            <div class="mt-2 space-y-2">
              <div
                v-for="candidate in image.tag_candidates"
                :key="candidate.id"
                class="flex items-center justify-between rounded border border-gray-200 p-2"
              >
                <div>
                  <p class="text-sm font-medium text-gray-900">{{ candidate.concept }}</p>
                  <p class="text-xs text-gray-600">Confidence: {{ (candidate.confidence * 100).toFixed(0) }}%</p>
                </div>
                <UBadge :color="getCandidateColor(candidate.status)">
                  {{ candidate.status }}
                </UBadge>
              </div>
            </div>
          </div>

          <!-- Processing Tasks -->
          <div v-if="image.processing_tasks?.length">
            <h3 class="font-semibold text-gray-900">Processing Tasks</h3>
            <div class="mt-2 space-y-2">
              <div v-for="task in image.processing_tasks" :key="task.id" class="text-sm">
                <p class="font-medium text-gray-900">{{ task.type }}</p>
                <p class="text-xs text-gray-600">
                  Status: {{ task.status }}
                  <span v-if="task.error_code" class="text-red-600">({{ task.error_code }})</span>
                </p>
              </div>
            </div>
          </div>

          <!-- Retry Button -->
          <div v-if="image.status === 'failed' && image.error_code">
            <UButton color="orange" @click="handleRetry">
              Retry Processing
            </UButton>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="text-center text-gray-600">
      Loading image details...
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { ImageDetail } from '~/types/api'

const api = useApi()
const route = useRoute()
const image = ref<ImageDetail | null>(null)
const showAddTagModal = ref(false)

function getStatusColor(status: string): string {
  const colors: Record<string, string> = {
    pending: 'amber',
    processing: 'blue',
    completed: 'green',
    failed: 'red',
  }
  return colors[status] || 'gray'
}

function getCandidateColor(status: string): string {
  const colors: Record<string, string> = {
    pending: 'amber',
    approved: 'green',
    merged: 'blue',
    rejected: 'gray',
  }
  return colors[status] || 'gray'
}

function formatDate(date: string): string {
  return new Date(date).toLocaleString()
}

async function loadImage() {
  try {
    const { data } = await api.get<ImageDetail>(`/admin/images/${route.params.id}`)
    image.value = data
  } catch (e) {
    console.error('Failed to load image:', e)
  }
}

async function handleRetry() {
  if (!image.value) return
  try {
    await api.post(`/admin/images/${image.value.id}/retry`)
    await loadImage()
  } catch (e) {
    console.error('Failed to retry image:', e)
  }
}

onMounted(() => {
  loadImage()
})
</script>
