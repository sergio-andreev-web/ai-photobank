<template>
  <section class="surface-card p-7" aria-label="История партий" data-testid="batch-history">
    <div class="mb-4 flex items-center justify-between gap-3">
      <div>
        <span class="subtle-label">ИСТОРИЯ</span>
        <h2 class="section-title mt-1">Последние партии</h2>
      </div>
      <UButton size="sm" variant="ghost" color="neutral" icon="i-heroicons-arrow-path" :loading="loading" @click="reload">
        Обновить
      </UButton>
    </div>

    <ErrorAlert v-if="loadError" :message="loadError" @dismiss="loadError = null" />

    <p v-if="!loading && !loadError && batches.length === 0" class="text-sm text-gray-600">Партий пока нет.</p>

    <ul v-else class="divide-y divide-gray-100">
      <li
        v-for="batch in batches"
        :key="batch.id"
        class="flex flex-wrap items-center gap-x-6 gap-y-3 py-3"
        data-testid="batch-row"
        :data-batch-id="batch.id"
      >
        <div class="min-w-[12rem] flex-1">
          <p class="font-medium text-gray-900">
            {{ batch.name || 'Без названия' }} <span class="text-sm font-normal text-gray-500">#{{ batch.id }}</span>
          </p>
          <p class="text-xs text-gray-500">{{ formatDate(batch.created_at) }}</p>
        </div>

        <div class="w-44">
          <UBadge :color="statusColor(batch.status)" variant="soft">{{ labelOf(BATCH_STATUS_LABELS, batch.status) }}</UBadge>
        </div>

        <div class="w-40 text-xs text-gray-600">
          <div class="mb-1 flex justify-between">
            <span>Обработано</span>
            <span>{{ batch.progress.processed }} / {{ batch.progress.total }}</span>
          </div>
          <div class="h-1.5 rounded-full bg-gray-200">
            <div class="h-1.5 rounded-full bg-blue-600" :style="{ width: `${percent(batch)}%` }" />
          </div>
        </div>

        <div class="flex min-w-[17rem] flex-wrap items-center gap-2">
          <UButton size="xs" variant="soft" :to="batchGalleryLocation(batch.id)">В галерее</UButton>
          <UButton
            v-if="batch.progress.failed > 0"
            size="xs"
            variant="soft"
            color="error"
            :to="batchGalleryLocation(batch.id, 'failed')"
          >
            С ошибками: {{ batch.progress.failed }}
          </UButton>
          <UButton
            v-if="canResume(batch)"
            size="xs"
            variant="soft"
            color="neutral"
            icon="i-heroicons-arrow-up-tray"
            @click="$emit('resume', batch)"
          >
            Продолжить загрузку
          </UButton>
        </div>
      </li>
    </ul>

    <div v-if="hasMore" class="mt-4 text-center">
      <UButton size="sm" variant="ghost" :loading="loading" @click="loadPage(page + 1)">Показать ещё</UButton>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { Batch, BatchStatus } from '~/types/api'
import { batchGalleryLocation } from '~/utils/galleryRoute'
import { BATCH_STATUS_LABELS, labelOf } from '~/utils/labels'

const props = defineProps<{
  /** The batch open above the history; it cannot be resumed a second time. */
  currentBatchId: number | null
}>()

defineEmits<{
  resume: [batch: Batch]
}>()

const PER_PAGE = 10

const batchesApi = useBatches()
const batches = ref<Batch[]>([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const loadError = ref<string | null>(null)

const hasMore = computed(() => page.value < lastPage.value)

function canResume(batch: Batch): boolean {
  // Only these statuses accept uploads (BATCH_NOT_ACCEPTING_UPLOADS otherwise, ADR-0005).
  return (batch.status === 'created' || batch.status === 'uploading') && batch.id !== props.currentBatchId
}

function percent(batch: Batch): number {
  return batch.progress.total === 0 ? 0 : Math.round((batch.progress.processed / batch.progress.total) * 100)
}

function statusColor(status: BatchStatus) {
  const colors = {
    created: 'neutral',
    uploading: 'info',
    processing: 'info',
    completed: 'success',
    completed_with_errors: 'warning',
    failed: 'error',
  } as const
  return colors[status]
}

function formatDate(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('ru-RU')
}

async function loadPage(next: number) {
  loading.value = true
  loadError.value = null
  try {
    const data = await batchesApi.listBatches({ page: next, per_page: PER_PAGE, sort: '-created_at' })
    batches.value = next === 1 ? data.data : [...batches.value, ...data.data.filter(b => !batches.value.some(known => known.id === b.id))]
    page.value = data.meta.page
    lastPage.value = data.meta.last_page
  } catch (e) {
    loadError.value = useApiError(e).message
  } finally {
    loading.value = false
  }
}

function reload() {
  return loadPage(1)
}

defineExpose({ reload })

onMounted(reload)
</script>
