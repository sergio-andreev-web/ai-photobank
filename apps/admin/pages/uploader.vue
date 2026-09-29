<template>
  <div class="space-y-6">
    <div class="page-heading">
      <span class="subtle-label">ПОПОЛНЕНИЕ БИБЛИОТЕКИ</span>
      <h1>Загрузка изображений</h1>
      <p>Создайте партию и загрузите сразу несколько изображений.</p>
    </div>

    <ErrorAlert v-if="createError" :message="createError" @dismiss="createError = null" />

    <!-- Create Batch Form -->
    <div v-if="!currentBatch" class="surface-card p-7">
      <h2 class="section-title">Новая партия</h2>
      <form class="mt-4 space-y-4" @submit.prevent="createBatch">
        <UFormField label="Название партии (необязательно)" name="name">
          <UInput v-model="batchName" placeholder="Например: Выезд на объект, сентябрь 2026" class="w-full max-w-md" />
        </UFormField>

        <UButton type="submit" :loading="creating"> Создать партию </UButton>
      </form>
    </div>

    <!-- Upload Area -->
    <div v-else class="surface-card p-7">
      <div class="mb-4 flex items-center justify-between">
        <div>
          <h2 class="text-lg font-semibold text-gray-900">
            Партия #{{ currentBatch.id }}: {{ currentBatch.name || 'Без названия' }}
          </h2>
          <p class="mt-1 text-sm text-gray-600">Статус: {{ labelOf(BATCH_STATUS_LABELS, currentBatch.status) }}</p>
        </div>
        <UButton variant="ghost" @click="resetBatch"> Новая партия </UButton>
      </div>

      <!-- Batch progress (server-side, polled) -->
      <div v-if="currentBatch.status !== 'created'" class="mb-6" data-testid="batch-progress">
        <div class="mb-2 flex justify-between text-sm text-gray-600">
          <span>Обработка партии</span>
          <span>{{ currentBatch.progress.processed }} / {{ currentBatch.progress.total }}</span>
        </div>
        <div class="h-4 w-full rounded-full bg-gray-200">
          <div
            class="h-4 rounded-full bg-blue-600 transition-all"
            :style="{ width: batchProgressPercent + '%' }"
          />
        </div>
        <div v-if="currentBatch.progress.failed > 0" class="mt-2 text-sm text-red-600">
          С ошибками: {{ currentBatch.progress.failed }}
        </div>
      </div>

      <!-- File Drop Zone -->
      <div
        v-if="acceptingUploads"
        class="rounded-xl border-2 border-dashed p-10 text-center transition-colors"
        :class="dragover ? 'border-blue-500 bg-blue-50' : 'border-gray-300'"
        @dragover.prevent="dragover = true"
        @dragleave="dragover = false"
        @drop.prevent="handleDrop"
      >
        <UIcon name="i-heroicons-cloud-arrow-up" class="mb-3 text-4xl text-blue-500" />
        <p class="font-medium text-slate-700">Перетащите изображения сюда или выберите файлы</p>
        <p class="mt-1 text-xs text-slate-500">JPEG, PNG или WebP</p>
        <input
          ref="fileInput"
          type="file"
          multiple
          accept="image/jpeg,image/png,image/webp"
          class="hidden"
          @change="handleFileSelect"
        />
        <UButton variant="soft" class="mt-4" @click="fileInput?.click()"> Выбрать файлы </UButton>
      </div>
      <p v-else class="text-sm text-gray-600">
        Партия больше не принимает файлы (статус: {{ labelOf(BATCH_STATUS_LABELS, currentBatch.status) }}).
      </p>

      <!-- Uploading files -->
      <div v-if="uploads.length > 0" class="mt-6">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-x-4">
          <h3 class="font-semibold text-gray-900">Файлы ({{ uploads.length }})</h3>
          <span class="text-sm text-gray-600">
            загружается: {{ uploadingCount }} &middot; загружено: {{ uploadedCount }} &middot;
            дубликатов: {{ duplicateCount }} &middot; ошибок: {{ errorCount }}
          </span>
        </div>
        <p v-if="uploadedCount > 0" class="mb-3 text-sm text-gray-600" data-testid="processing-summary">
          ИИ-обработка: в работе {{ processingCount }} &middot; готово {{ readyCount }} &middot;
          с ошибками {{ processingFailedCount }}
        </p>
        <div class="space-y-2" data-testid="upload-list">
          <div
            v-for="upload in uploads"
            :key="upload.clientId"
            class="flex items-center justify-between gap-3 rounded border border-gray-200 p-3"
            :data-testid="`upload-row-${upload.clientId}`"
            :data-state="fileState(upload)"
          >
            <div class="flex-1 overflow-hidden">
              <p class="truncate text-sm font-medium text-gray-900">{{ upload.file.name }}</p>
              <p class="text-xs text-gray-600">{{ statusLabel(upload) }}</p>
              <!-- AI processing of an uploaded file (polled, §52); the upload line above stays as it was. -->
              <p
                v-if="processingLabel(upload)"
                class="text-xs"
                :class="upload.imageStatus === 'failed' ? 'text-red-600' : 'text-gray-600'"
                :data-testid="`upload-processing-${upload.clientId}`"
              >
                {{ processingLabel(upload) }}
                <NuxtLink
                  v-if="upload.imageId !== null && processingLinkLabel(upload)"
                  :to="`/gallery/${upload.imageId}`"
                  class="ml-1 font-medium text-blue-600 hover:text-blue-700"
                >
                  {{ processingLinkLabel(upload) }}
                </NuxtLink>
              </p>
            </div>
            <div class="flex items-center gap-2">
              <UBadge :color="statusColor(upload)" :data-testid="`upload-state-${upload.clientId}`">
                {{ FILE_STATE_LABELS[fileState(upload)] }}
              </UBadge>
              <UButton
                v-if="upload.state === 'duplicate' && upload.existingImageId && upload.existingImageDeleted && !upload.restored"
                size="xs"
                variant="soft"
                color="warning"
                :loading="upload.restoring"
                @click="restoreDuplicate(upload)"
              >
                Восстановить
              </UButton>
              <UButton
                v-else-if="upload.state === 'duplicate' && upload.existingImageId"
                size="xs"
                variant="ghost"
                :to="`/gallery/${upload.existingImageId}`"
              >
                Открыть
              </UButton>
              <UButton
                v-if="upload.state === 'error' && acceptingUploads"
                size="xs"
                variant="soft"
                @click="retryUpload(upload)"
              >
                Повторить
              </UButton>
            </div>
          </div>
        </div>
      </div>

      <!-- Complete batch -->
      <div v-if="currentBatch.status === 'created' || currentBatch.status === 'uploading'" class="mt-6">
        <UButton
          :disabled="uploadingCount > 0 || uploadedTotal === 0"
          :loading="completing"
          @click="handleComplete"
        >
          Завершить загрузку ({{ uploadedTotal }} {{ pluralRu(uploadedTotal, FILE_FORMS) }})
        </UButton>
        <p class="mt-2 text-xs text-gray-600">
          Партия перестанет принимать новые файлы, ИИ-обработка запустится или завершится.
        </p>
      </div>
    </div>

    <BatchHistory ref="history" :current-batch-id="currentBatch?.id ?? null" @resume="resumeBatch" />
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { Batch } from '~/types/api'
import { BATCH_STATUS_LABELS, FILE_FORMS, FILE_STATE_LABELS, labelOf, pluralRu } from '~/utils/labels'
import { runWithConcurrency } from '~/utils/concurrency'
import {
  applyImageStatuses,
  fileState,
  isAwaitingProcessing,
  isFinalBatchStatus,
  type TrackedFile,
} from '~/utils/uploadTracking'

const MAX_CONCURRENT_UPLOADS = 4

// `state` is the upload request's outcome; `imageStatus` (from polling) then carries the file through
// AI processing. fileState() combines both into the states shown per file (§52).
interface UploadItem extends TrackedFile {
  clientId: string
  file: File
  errorCode: string | null
  errorMessage: string | null
  existingImageId: number | null
  /** The duplicate's original is in the trash (ADR-0013): offer to restore it. */
  existingImageDeleted: boolean
  restoring: boolean
  restored: boolean
}

const batches = useBatches()
const images = useImages()

const batchName = ref('')
const currentBatch = ref<Batch | null>(null)
const creating = ref(false)
const createError = ref<string | null>(null)
const dragover = ref(false)
const fileInput = ref<HTMLInputElement>()
const completing = ref(false)
const uploads = ref<UploadItem[]>([])

let stopWatching: (() => void) | null = null
const history = ref<{ reload: () => Promise<void> } | null>(null)

const acceptingUploads = computed(
  () => currentBatch.value?.status === 'created' || currentBatch.value?.status === 'uploading'
)

const batchProgressPercent = computed(() => {
  const progress = currentBatch.value?.progress
  if (!progress || progress.total === 0) return 0
  return Math.round((progress.processed / progress.total) * 100)
})

const uploadingCount = computed(() => uploads.value.filter((u) => u.state === 'queued' || u.state === 'uploading').length)
const uploadedCount = computed(() => uploads.value.filter((u) => u.state === 'uploaded').length)
const duplicateCount = computed(() => uploads.value.filter((u) => u.state === 'duplicate').length)
const errorCount = computed(() => uploads.value.filter((u) => u.state === 'error').length)
const uploadedTotal = computed(() => uploadedCount.value + duplicateCount.value)
const processingCount = computed(() => uploads.value.filter((u) => fileState(u) === 'processing').length)
const readyCount = computed(() => uploads.value.filter((u) => fileState(u) === 'ready').length)
// Uploaded files whose AI processing failed (upload errors are counted in errorCount).
const processingFailedCount = computed(
  () => uploads.value.filter((u) => u.state === 'uploaded' && fileState(u) === 'failed').length
)

function statusLabel(upload: UploadItem): string {
  switch (upload.state) {
    case 'queued':
      return 'Ожидает загрузки'
    case 'uploading':
      return 'Загружается…'
    case 'uploaded':
      return 'Загружен, ждёт обработки'
    case 'duplicate':
      if (upload.restored) return `Дубликат: изображение #${upload.existingImageId} восстановлено из корзины`
      if (upload.existingImageId && upload.existingImageDeleted) {
        return `Пропущен: такое изображение #${upload.existingImageId} лежит в корзине`
      }
      return upload.existingImageId
        ? `Пропущен: дубликат изображения #${upload.existingImageId}`
        : 'Пропущен: дубликат'
    case 'error':
      return upload.errorMessage || 'Не удалось загрузить'
    default:
      return ''
  }
}

function processingLabel(upload: UploadItem): string {
  if (upload.state !== 'uploaded') return ''
  switch (upload.imageStatus) {
    case 'queued':
      return 'Ждёт ИИ-анализа'
    case 'processing':
      return 'Идёт ИИ-анализ'
    case 'ready':
      return 'Анализ завершён'
    case 'failed':
      // The list item carries no error fields (ImageSummary); code and message are on the image page.
      return 'Ошибка обработки'
    default:
      return ''
  }
}

function processingLinkLabel(upload: UploadItem): string {
  if (upload.imageStatus === 'ready') return 'Открыть изображение'
  if (upload.imageStatus === 'failed') return 'Подробнее об ошибке'
  return ''
}

function statusColor(upload: UploadItem): 'success' | 'warning' | 'error' | 'info' | 'neutral' {
  switch (fileState(upload)) {
    case 'ready':
      return 'success'
    case 'duplicate':
      return 'warning'
    case 'failed':
      return 'error'
    case 'uploading':
    case 'processing':
      return 'info'
    default:
      return 'neutral'
  }
}

async function createBatch() {
  creating.value = true
  createError.value = null
  try {
    currentBatch.value = await batches.createBatch(batchName.value || undefined)
    batchName.value = ''
    void history.value?.reload()
  } catch (e) {
    createError.value = useApiError(e).message
  } finally {
    creating.value = false
  }
}

function resetBatch() {
  stopWatching?.()
  stopWatching = null
  currentBatch.value = null
  uploads.value = []
  void history.value?.reload()
}

/** Reopens an unfinished batch from the history to add files or finish it. */
function resumeBatch(batch: Batch) {
  stopWatching?.()
  stopWatching = null
  uploads.value = []
  createError.value = null
  currentBatch.value = batch
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function handleFileSelect(event: Event) {
  const files = (event.target as HTMLInputElement).files
  if (files) enqueueFiles(Array.from(files))
  if (fileInput.value) fileInput.value.value = ''
}

function handleDrop(event: DragEvent) {
  dragover.value = false
  const files = event.dataTransfer?.files
  if (files) enqueueFiles(Array.from(files))
}

function enqueueFiles(files: File[]) {
  if (!currentBatch.value) return
  // reactive() here (not a plain object later pushed into uploads.value) so the
  // exact object mutated inside uploadOne/retryUpload IS the tracked proxy —
  // otherwise state writes land on the raw object and derived computeds like
  // uploadingCount silently go stale (they never see the mutation).
  const items: UploadItem[] = files.map((file) => reactive({
    clientId: `${file.name}-${file.size}-${file.lastModified}-${Math.random().toString(36).slice(2)}`,
    file,
    state: 'queued',
    errorCode: null,
    errorMessage: null,
    existingImageId: null,
    existingImageDeleted: false,
    restoring: false,
    restored: false,
    imageId: null,
    imageStatus: null,
    imageGone: false,
  }))
  uploads.value.push(...items)
  void runUploads(items)
}

async function runUploads(items: UploadItem[]) {
  await runWithConcurrency(items, MAX_CONCURRENT_UPLOADS, async (item) => {
    await uploadOne(item)
  })
  startWatchingIfNeeded()
}

async function uploadOne(item: UploadItem) {
  if (!currentBatch.value) return
  item.state = 'uploading'
  item.errorCode = null
  item.errorMessage = null
  item.imageId = null
  item.imageStatus = null
  item.imageGone = false
  try {
    const image = await images.uploadImage(currentBatch.value.id, item.file)
    item.imageId = image.id
    // The 201 only says "uploaded"; the next status (processing/ready/failed) comes from polling.
    item.state = 'uploaded'
  } catch (e) {
    const { code, message } = useApiError(e)
    if (code === 'IMAGE_ALREADY_EXISTS') {
      item.state = 'duplicate'
      const details = (e as any)?.response?.data?.error?.details
      item.existingImageId = details?.existing_image_id ?? null
      item.existingImageDeleted = details?.existing_image_deleted === true
    } else {
      item.state = 'error'
      item.errorCode = code
      item.errorMessage = message
    }
  }
  // Follow processing from the first stored file on, not only after the whole selection was sent.
  if (item.state === 'uploaded') startWatchingIfNeeded()
}

/** The duplicate's original was in the trash: bring it back instead of uploading again (ADR-0013). */
async function restoreDuplicate(item: UploadItem) {
  if (!item.existingImageId) return
  item.restoring = true
  try {
    await images.restoreImage(item.existingImageId)
    item.restored = true
  } catch (e) {
    createError.value = useApiError(e).message
  } finally {
    item.restoring = false
  }
}

async function retryUpload(item: UploadItem) {
  await uploadOne(item)
  startWatchingIfNeeded()
}

// Each poll (§49) also reloads the batch's images while an uploaded file has not reached ready/failed,
// so every row can move on to processing/ready/failed (§52).
async function refreshFileStates(batch: Batch) {
  const rows = await images.listBatchImages(batch.id)
  applyImageStatuses(uploads.value, rows, isFinalBatchStatus(batch.status))
}

function startWatchingIfNeeded() {
  if (!currentBatch.value || stopWatching) return
  stopWatching = batches.watchBatch(
    currentBatch.value.id,
    (batch) => {
      currentBatch.value = batch
    },
    {
      tracker: {
        hasPending: () => uploads.value.some(isAwaitingProcessing),
        refresh: refreshFileStates,
      },
    }
  )
}

async function handleComplete() {
  if (!currentBatch.value) return
  completing.value = true
  try {
    currentBatch.value = await batches.completeBatch(currentBatch.value.id)
    startWatchingIfNeeded()
    void history.value?.reload()
  } catch (e) {
    createError.value = useApiError(e).message
  } finally {
    completing.value = false
  }
}

onBeforeUnmount(() => {
  stopWatching?.()
})
</script>
