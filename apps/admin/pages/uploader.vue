<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-3xl font-bold text-gray-900">Upload Images</h1>
      <p class="mt-2 text-gray-600">Create a batch and upload multiple images at once.</p>
    </div>

    <!-- Create Batch Form -->
    <div v-if="!currentBatch" class="rounded-lg bg-white p-6 shadow">
      <h2 class="text-lg font-semibold text-gray-900">New Batch</h2>
      <form class="mt-4 space-y-4" @submit.prevent="createBatch">
        <UFormGroup label="Batch Name (optional)" name="name">
          <UInput
            v-model="batchName"
            placeholder="e.g., Site visit - September 2026"
          />
        </UFormGroup>

        <UButton type="submit" :loading="creating">
          Create Batch
        </UButton>
      </form>
    </div>

    <!-- Upload Area -->
    <div v-else class="rounded-lg bg-white p-6 shadow">
      <div class="mb-4 flex items-center justify-between">
        <div>
          <h2 class="text-lg font-semibold text-gray-900">
            Batch #{{ currentBatch.id }}: {{ currentBatch.name || 'Untitled' }}
          </h2>
          <p class="mt-1 text-sm text-gray-600">Status: {{ currentBatch.status }}</p>
        </div>
        <UButton variant="ghost" @click="resetBatch">
          New Batch
        </UButton>
      </div>

      <!-- Progress -->
      <div v-if="currentBatch.status !== 'created'" class="mb-6">
        <div class="flex justify-between text-sm text-gray-600 mb-2">
          <span>Progress</span>
          <span>
            {{ currentBatch.progress.processed }} / {{ currentBatch.progress.total }}
          </span>
        </div>
        <div class="h-4 w-full rounded-full bg-gray-200">
          <div
            class="h-4 rounded-full bg-blue-600 transition-all"
            :style="{ width: progressPercent + '%' }"
          />
        </div>
        <div v-if="currentBatch.progress.failed > 0" class="mt-2 text-sm text-red-600">
          {{ currentBatch.progress.failed }} failed
        </div>
      </div>

      <!-- File Drop Zone -->
      <div
        v-if="currentBatch.status === 'created'"
        class="rounded-lg border-2 border-dashed border-gray-300 p-8 text-center transition-colors hover:border-blue-500"
        @dragover="dragover = true"
        @dragleave="dragover = false"
        @drop.prevent="handleDrop"
      >
        <p class="text-gray-600">Drag and drop images here or click to select</p>
        <input
          ref="fileInput"
          type="file"
          multiple
          accept="image/*"
          class="hidden"
          @change="handleFileSelect"
        />
        <UButton
          variant="soft"
          class="mt-4"
          @click="$refs.fileInput?.click()"
        >
          Select Files
        </UButton>
      </div>

      <!-- Uploaded Files List -->
      <div v-if="uploadedFiles.length > 0" class="mt-6">
        <h3 class="font-semibold text-gray-900">Uploaded Files</h3>
        <div class="mt-4 space-y-2">
          <div v-for="file in uploadedFiles" :key="file.id" class="flex items-center justify-between rounded border border-gray-200 p-3">
            <div class="flex-1">
              <p class="text-sm font-medium text-gray-900">{{ file.filename }}</p>
              <p class="text-xs text-gray-600">{{ getStatusLabel(file.status) }}</p>
            </div>
            <div class="text-right text-xs text-gray-600">
              <div v-if="file.status === 'failed'" class="text-red-600">
                {{ file.error_code }}
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

import type { Batch, Image } from '~/types/api'

const api = useApi()
const router = useRouter()

const batchName = ref('')
const currentBatch = ref<Batch | null>(null)
const uploadedFiles = ref<Image[]>([])
const creating = ref(false)
const dragover = ref(false)
const fileInput = ref<HTMLInputElement>()

const progressPercent = computed(() => {
  if (!currentBatch.value || currentBatch.value.progress.total === 0) return 0
  return Math.round(
    (currentBatch.value.progress.processed / currentBatch.value.progress.total) * 100
  )
})

async function createBatch() {
  creating.value = true
  try {
    const { data } = await api.post<Batch>('/admin/batches', {
      name: batchName.value || undefined,
    })
    currentBatch.value = data
    batchName.value = ''
    // Start polling for batch status
    startPolling()
  } catch (e) {
    console.error('Failed to create batch:', e)
  } finally {
    creating.value = false
  }
}

function resetBatch() {
  currentBatch.value = null
  uploadedFiles.value = []
}

function getStatusLabel(status: string): string {
  const labels: Record<string, string> = {
    pending: 'Pending',
    processing: 'Processing',
    completed: 'Completed',
    failed: 'Failed',
  }
  return labels[status] || status
}

async function handleFileSelect(event: Event) {
  const files = (event.target as HTMLInputElement).files
  if (files) {
    await uploadFiles(Array.from(files))
  }
}

async function handleDrop(event: DragEvent) {
  dragover.value = false
  const files = event.dataTransfer?.files
  if (files) {
    await uploadFiles(Array.from(files))
  }
}

async function uploadFiles(files: File[]) {
  if (!currentBatch.value) return

  for (const file of files) {
    try {
      const formData = new FormData()
      formData.append('file', file)

      const { data } = await api.post<Image>(
        `/admin/batches/${currentBatch.value.id}/images`,
        formData,
        {
          headers: { 'Content-Type': 'multipart/form-data' },
        }
      )
      uploadedFiles.value.push(data)
    } catch (e) {
      console.error(`Failed to upload ${file.name}:`, e)
    }
  }

  startPolling()
}

let pollingInterval: ReturnType<typeof setInterval> | null = null

function startPolling() {
  if (pollingInterval) return
  if (!currentBatch.value) return

  pollingInterval = setInterval(async () => {
    if (!currentBatch.value) {
      stopPolling()
      return
    }

    try {
      const { data } = await api.get<Batch>(`/admin/batches/${currentBatch.value.id}`)
      currentBatch.value = data

      // Fetch batch images to update the list
      const { data: batchImages } = await api.get<any>(
        `/admin/batches/${currentBatch.value.id}/images`
      )
      uploadedFiles.value = batchImages.data || []

      // Stop polling when batch is completed
      if (['completed', 'failed'].includes(data.status)) {
        stopPolling()
      }
    } catch (e) {
      console.error('Failed to poll batch status:', e)
    }
  }, 2000)
}

function stopPolling() {
  if (pollingInterval) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }
}

onBeforeUnmount(() => {
  stopPolling()
})
</script>
