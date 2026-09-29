<template>
  <div class="space-y-6">
    <div class="page-heading">
      <span class="subtle-label">УДАЛЁННЫЕ ИЗОБРАЖЕНИЯ</span>
      <h1>Корзина</h1>
      <p>Удалённые фото хранятся вместе с файлами и историей анализа — их можно вернуть в библиотеку.</p>
    </div>

    <div v-if="notice" role="status" aria-live="polite" data-testid="trash-notice">
      <UAlert :color="notice.tone" variant="soft" :title="notice.message" close @update:open="notice = null" />
    </div>
    <ErrorAlert v-if="loadError" :message="loadError" @dismiss="loadError = null" />

    <div v-if="images.length" class="flex flex-wrap items-center justify-between gap-3">
      <UCheckbox :model-value="pageSelection" label="Выбрать все на странице" @update:model-value="toggleAll" />
      <span v-if="pagination" class="text-sm text-gray-500">В корзине: {{ pagination.total }}</span>
    </div>

    <section
      v-if="selected.size"
      class="surface-card bulk-bar sticky top-3 z-20 flex flex-wrap items-center gap-2"
      aria-label="Действия с выбранными"
      data-testid="trash-bulk-bar"
    >
      <strong class="mr-2 text-sm">Выбрано: {{ selected.size }}</strong>
      <UButton v-if="!progress" size="sm" color="success" variant="soft" icon="i-heroicons-arrow-uturn-left" @click="restore([...selected])">
        Восстановить ({{ selected.size }})
      </UButton>
      <span v-else class="text-sm text-gray-600" role="status">Восстановление… {{ progress.finished }} / {{ progress.total }}</span>
      <UButton v-if="!progress" size="sm" variant="ghost" color="neutral" class="ml-auto" @click="clearSelection">Снять выделение</UButton>
    </section>

    <div v-if="loading && !images.length" class="text-center text-gray-600">Загрузка…</div>

    <div v-else-if="!images.length && !loadError" class="surface-card p-12 text-center">
      <UIcon name="i-heroicons-trash" class="text-3xl text-gray-400" />
      <p class="mt-2 text-gray-600">Корзина пуста.</p>
    </div>

    <div v-else class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4" data-testid="trash-grid">
      <div
        v-for="image in images"
        :key="image.id"
        class="surface-card relative overflow-hidden"
        :class="{ 'is-selected': selected.has(image.id) }"
        data-testid="trash-card"
      >
        <label class="gallery-card-check is-visible">
          <input
            type="checkbox"
            class="h-4 w-4 accent-blue-600"
            :checked="selected.has(image.id)"
            :aria-label="`Выбрать ${image.original_name}`"
            @click="onToggle(image.id, $event)"
          />
        </label>
        <div class="aspect-[4/3] overflow-hidden bg-slate-100">
          <img v-if="image.urls.thumbnail" :src="image.urls.thumbnail" :alt="image.original_name" class="h-full w-full object-cover opacity-80" />
          <div v-else class="flex h-full items-center justify-center text-4xl text-slate-300"><UIcon name="i-heroicons-photo" /></div>
        </div>
        <div class="space-y-2 p-4">
          <p class="truncate text-sm font-semibold text-gray-900">{{ image.original_name }}</p>
          <p class="text-xs text-gray-500" data-testid="trash-deleted-at">Удалено {{ formatDate(image.deleted_at) }}</p>
          <UButton
            size="xs"
            color="success"
            variant="soft"
            icon="i-heroicons-arrow-uturn-left"
            :loading="restoringId === image.id"
            :disabled="!!progress"
            @click="restore([image.id])"
          >
            Восстановить
          </UButton>
        </div>
      </div>
    </div>

    <div v-if="pagination && pagination.last_page > 1" class="flex justify-center gap-2">
      <UButton v-if="pagination.page > 1" variant="ghost" @click="load(pagination.page - 1)"> Назад </UButton>
      <span class="flex items-center text-sm text-gray-600"> Страница {{ pagination.page }} из {{ pagination.last_page }} </span>
      <UButton v-if="pagination.page < pagination.last_page" variant="ghost" @click="load(pagination.page + 1)">
        Вперёд
      </UButton>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

import type { PaginationMeta, TrashedImageSummary } from '~/types/api'
import { runBulk } from '~/utils/bulkActions'
import { toApiProblem } from '~/utils/errorHandler'
import { IMAGE_FORMS, pluralRu } from '~/utils/labels'
import { keepVisible, pageSelectionState, toggleSelection } from '~/utils/selection'

const imagesApi = useImages()

const images = ref<TrashedImageSummary[]>([])
const pagination = ref<PaginationMeta | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const notice = ref<{ message: string; tone: 'success' | 'warning' } | null>(null)
const restoringId = ref<number | null>(null)
const progress = ref<{ finished: number; total: number } | null>(null)

const selected = ref<Set<number>>(new Set())
let anchor: number | null = null
const pageIds = computed(() => images.value.map((image) => image.id))
const pageSelection = computed(() => pageSelectionState(selected.value, pageIds.value))

function onToggle(id: number, event: MouseEvent) {
  selected.value = toggleSelection(selected.value, pageIds.value, id, { shift: event.shiftKey, anchor })
  anchor = id
}

function toggleAll(value: boolean | 'indeterminate') {
  selected.value = value === true ? new Set(pageIds.value) : new Set()
  anchor = null
}

function clearSelection() {
  selected.value = new Set()
  anchor = null
}

function formatDate(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('ru-RU')
}

async function load(page = 1) {
  loading.value = true
  loadError.value = null
  try {
    const data = await imagesApi.listTrash({ page, per_page: 24 })
    images.value = data.data
    pagination.value = data.meta
    selected.value = keepVisible(selected.value, pageIds.value)
  } catch (e) {
    loadError.value = useApiError(e).message
  } finally {
    loading.value = false
  }
}

async function restore(ids: number[]) {
  if (!ids.length) return
  if (ids.length === 1) restoringId.value = ids[0]!
  else progress.value = { finished: 0, total: ids.length }
  try {
    const result = await runBulk(ids, (id) => imagesApi.restoreImage(id), (finished) => {
      if (progress.value) progress.value.finished = finished
    })
    const parts = [`Восстановлено: ${result.done.length} ${pluralRu(result.done.length, IMAGE_FORMS)}.`]
    if (result.failed.length) {
      const reasons = [...new Set(result.failed.map((failure) => toApiProblem(failure.error).message))]
      parts.push(`Не удалось: ${result.failed.length} — ${reasons.join(' ')}`)
    }
    notice.value = { message: parts.join(' '), tone: result.failed.length ? 'warning' : 'success' }
    // Failed ones stay selected for another try.
    selected.value = new Set(result.failed.map((failure) => failure.id))
    await load(pagination.value?.page ?? 1)
    if (!images.value.length && (pagination.value?.page ?? 1) > 1) await load(pagination.value!.page - 1)
  } finally {
    restoringId.value = null
    progress.value = null
  }
}

onMounted(() => load())
</script>
