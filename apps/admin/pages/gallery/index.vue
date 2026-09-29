<template>
  <div class="space-y-6">
    <div class="page-heading flex flex-wrap items-end justify-between gap-4">
      <div>
        <span class="subtle-label">БИБЛИОТЕКА ИЗОБРАЖЕНИЙ</span>
        <h1>Галерея</h1>
        <p>Просмотр и управление изображениями.</p>
      </div>
      <UButton to="/uploader" icon="i-heroicons-arrow-up-tray" size="lg">Загрузить изображения</UButton>
    </div>

    <!-- AI search + filters -->
    <!-- flex/gap, not space-y: a collapsed (display: none) panel must not leave its margin behind. -->
    <div class="surface-card flex flex-col gap-5 p-5">
      <!-- Hands the query over to the search page in semantic mode; the gallery grid is not filtered by it. -->
      <form class="flex flex-wrap gap-3" role="search" data-testid="ai-search-form" @submit.prevent="submitAiSearch">
        <UInput
          v-model="aiQuery"
          icon="i-heroicons-sparkles"
          placeholder="ИИ-поиск: опишите, что ищете"
          aria-label="ИИ-поиск"
          maxlength="1000"
          class="min-w-[240px] flex-1"
        />
        <UButton type="submit" :disabled="!aiQuery.trim()">ИИ-поиск</UButton>
        <UButton
          color="neutral"
          variant="soft"
          icon="i-heroicons-adjustments-horizontal"
          :aria-expanded="filtersOpen"
          aria-controls="gallery-filters"
          @click="filtersOpen = !filtersOpen"
        >
          Фильтры<template v-if="panelFilterCount"> ({{ panelFilterCount }})</template>
        </UButton>
      </form>

      <div v-show="filtersOpen" id="gallery-filters" class="flex flex-wrap gap-4">
        <UFormField
          label="Теги"
          help="У изображения должны быть все выбранные теги."
          class="min-w-[240px] flex-[2]"
          data-testid="tag-filter"
        >
          <USelectMenu
            v-model="filters.tagIds"
            :items="tagOptions"
            value-key="value"
            multiple
            ignore-filter
            placeholder="Любой тег"
            class="w-full"
            @update:search-term="onTagSearch"
          />
        </UFormField>

        <UFormField label="Партия" class="min-w-[160px] flex-1">
          <USelect v-model="filters.batchId" :items="batchOptions" class="w-full" />
        </UFormField>

        <UFormField label="Создано с" class="min-w-[160px] flex-1">
          <UInput v-model="filters.dateFrom" type="date" :max="filters.dateTo || undefined" class="w-full" />
        </UFormField>

        <UFormField label="Создано по" class="min-w-[160px] flex-1">
          <UInput v-model="filters.dateTo" type="date" :min="filters.dateFrom || undefined" class="w-full" />
        </UFormField>
      </div>

      <div v-if="hasActiveFilters">
        <UButton variant="ghost" color="neutral" icon="i-heroicons-x-mark" size="sm" @click="resetFilters">
          Сбросить фильтры
        </UButton>
      </div>
    </div>

    <!-- Status tabs, select-all and sort -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="gallery-tabs" role="group" aria-label="Статус">
        <button
          v-for="tab in statusTabs"
          :key="tab.value"
          type="button"
          class="gallery-tab"
          :class="{ 'is-active': filters.status === tab.value }"
          :aria-pressed="filters.status === tab.value"
          @click="filters.status = tab.value"
        >
          {{ tab.label }}
        </button>
      </div>
      <div class="flex items-center gap-4">
        <UCheckbox
          v-if="images.length > 0"
          :model-value="pageSelection"
          label="Выбрать все на странице"
          @update:model-value="toggleAll"
        />
        <USelect v-model="sort" :items="sortOptions" aria-label="Сортировка" class="w-44" />
      </div>
    </div>

    <BulkActionBar
      v-if="selectedImages.length > 0"
      class="sticky top-3 z-20"
      :images="selectedImages"
      @finished="onBulkFinished"
      @clear="clearSelection"
    />

    <div v-if="notice" role="status" aria-live="polite" data-testid="gallery-notice">
      <UAlert :color="notice.tone" variant="soft" :title="notice.message" close @update:open="notice = null" />
    </div>

    <ErrorAlert v-if="loadError" :message="loadError" @dismiss="loadError = null" />

    <!-- Image Grid -->
    <div v-if="loading" class="text-center text-gray-600">Загрузка изображений…</div>

    <div v-else-if="images.length === 0" class="surface-card p-12 text-center">
      <p v-if="hasActiveFilters" class="text-gray-600">Нет изображений, подходящих под фильтры.</p>
      <template v-else>
        <p class="text-gray-600">Изображений пока нет. Начните с загрузки партии.</p>
        <UButton to="/uploader" class="mt-4"> Создать первую партию </UButton>
      </template>
    </div>

    <div v-else class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4" data-testid="gallery-grid">
      <div
        v-for="image in images"
        :key="image.id"
        class="relative"
        data-testid="gallery-card"
        :data-selected="selected.has(image.id)"
      >
      <!-- The native toggle is not cancelled (a cancelled click reverts the box after Vue has set it);
           the click only reads Shift for range selection. -->
      <label class="gallery-card-check" :class="{ 'is-visible': selected.size > 0 }">
        <input
          type="checkbox"
          class="h-4 w-4 accent-blue-600"
          :checked="selected.has(image.id)"
          :aria-label="`Выбрать ${image.original_name}`"
          @click="onToggle(image.id, $event)"
        />
      </label>
      <button
        type="button"
        class="gallery-card-zoom"
        :aria-label="`Просмотр ${image.original_name}`"
        title="Просмотр на весь экран"
        @click="viewerIndex = images.indexOf(image)"
      >
        <UIcon name="i-heroicons-arrows-pointing-out" />
      </button>
      <NuxtLink
        :to="`/gallery/${image.id}`"
        class="surface-card group block overflow-hidden transition-shadow hover:shadow-lg"
        :class="{ 'is-selected': selected.has(image.id) }"
        @click="onCardClick(image.id, $event)"
      >
        <div class="aspect-[4/3] overflow-hidden bg-slate-100">
          <img
            v-if="image.urls.thumbnail"
            :src="image.urls.thumbnail"
            :alt="image.original_name"
            class="h-full w-full object-cover"
          />
          <div v-else class="flex h-full w-full items-center justify-center bg-slate-100 text-4xl text-slate-300"><UIcon name="i-heroicons-photo" /></div>
        </div>
        <div class="space-y-2 p-4">
          <p class="truncate text-sm font-semibold text-gray-900">{{ image.original_name }}</p>
          <div class="flex items-center justify-between gap-2">
            <UBadge :color="statusColor(image.status)">{{ labelOf(IMAGE_STATUS_LABELS, image.status) }}</UBadge>
            <time
              class="text-xs text-gray-500"
              :datetime="image.created_at"
              :title="formatDateTime(image.created_at)"
              data-testid="gallery-card-created"
            >
              {{ formatCreated(image.created_at) }}
            </time>
          </div>
          <div v-if="image.tags.length" class="flex flex-wrap items-center gap-1" data-testid="gallery-card-tags">
            <UBadge
              v-for="tag in visibleTags(image)"
              :key="tag.id"
              size="sm"
              variant="subtle"
              color="neutral"
              class="max-w-full"
            >
              <span class="truncate">{{ tag.name }}</span>
            </UBadge>
            <span v-if="hiddenTagCount(image) > 0" class="text-xs text-gray-500">+{{ hiddenTagCount(image) }}</span>
          </div>
          <p v-else class="text-xs text-gray-400">Нет тегов</p>
        </div>
      </NuxtLink>
      </div>
    </div>

    <ImageLightbox
      v-if="viewerIndex !== null && images[viewerIndex]"
      v-model:index="viewerIndex"
      :images="images"
      @close="viewerIndex = null"
    />

    <!-- Pagination -->
    <div v-if="pagination && pagination.last_page > 1" class="flex justify-center gap-2">
      <UButton v-if="pagination.page > 1" variant="ghost" @click="loadImages(pagination.page - 1)"> Назад </UButton>
      <span class="flex items-center text-sm text-gray-600"> Страница {{ pagination.page }} из {{ pagination.last_page }} </span>
      <UButton v-if="pagination.page < pagination.last_page" variant="ghost" @click="loadImages(pagination.page + 1)">
        Вперёд
      </UButton>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { Batch, ImageStatus, ImageSummary, PaginationMeta, Tag } from '~/types/api'
import { createdFromParam, createdToParam } from '~/utils/dateRange'
import { readGalleryRouteQuery } from '~/utils/galleryRoute'
import { keepVisible, pageSelectionState, toggleSelection } from '~/utils/selection'
import { IMAGE_STATUS_LABELS, labelOf, TAG_TYPE_LABELS } from '~/utils/labels'
import { semanticSearchLocation } from '~/utils/searchRoute'

// Nuxt UI v3 reserves an empty string `value` on a SelectItem to mean "clear
// selection" and throws if an item uses it, so "no filter" needs its own
// sentinel instead of ''.
const ALL = 'all' as const

const MAX_CARD_TAGS = 4

interface TagOption {
  label: string
  value: number
}

const images = ref<ImageSummary[]>([])
const batches = ref<Batch[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)
const pagination = ref<PaginationMeta | null>(null)
const aiQuery = ref('')

// Deep link from the batch history: /gallery?batch_id=42[&status=failed].
const routeFilters = readGalleryRouteQuery(useRoute().query)

const filters = reactive<{
  status: ImageStatus | typeof ALL
  batchId: number | typeof ALL
  tagIds: number[]
  // `<input type="date">` values (YYYY-MM-DD, local day); '' means no bound.
  dateFrom: string
  dateTo: string
}>({
  status: routeFilters.status ?? ALL,
  batchId: routeFilters.batchId ?? ALL,
  tagIds: [],
  dateFrom: '',
  dateTo: '',
})

const statusTabs: { label: string; value: ImageStatus | typeof ALL }[] = [
  { label: 'Все', value: ALL },
  { label: 'Готовые', value: 'ready' },
  { label: 'В обработке', value: 'processing' },
  { label: 'В очереди', value: 'queued' },
  { label: 'Загруженные', value: 'uploaded' },
  { label: 'С ошибками', value: 'failed' },
]

// GET /admin/images `sort` (contract enum).
type ImageSort = '-created_at' | 'created_at'
const sort = ref<ImageSort>('-created_at')
const sortOptions: { label: string; value: ImageSort }[] = [
  { label: 'Сначала новые', value: '-created_at' },
  { label: 'Сначала старые', value: 'created_at' },
]

// Tags, batch and dates live in a collapsible panel (status has its own tabs); it starts open when a
// deep link already set one of them.
const panelFilterCount = computed(
  () => (filters.tagIds.length ? 1 : 0) + (filters.batchId !== ALL ? 1 : 0) + (filters.dateFrom || filters.dateTo ? 1 : 0),
)
const filtersOpen = ref(panelFilterCount.value > 0)

// Selection lives on the current page only: it is pruned on every reload and cleared on page/filter changes.
const selected = ref<Set<number>>(new Set())
let selectionAnchor: number | null = null
const notice = ref<{ message: string; tone: 'success' | 'warning' } | null>(null)
/** Index of the image open in the full-screen viewer (current page), or null. */
const viewerIndex = ref<number | null>(null)

const pageIds = computed(() => images.value.map((image) => image.id))
const selectedImages = computed(() => images.value.filter((image) => selected.value.has(image.id)))
const pageSelection = computed(() => pageSelectionState(selected.value, pageIds.value))

function onToggle(id: number, event: MouseEvent) {
  selected.value = toggleSelection(selected.value, pageIds.value, id, { shift: event.shiftKey, anchor: selectionAnchor })
  selectionAnchor = id
}

/** While something is selected, a click on a card selects it instead of opening it. */
function onCardClick(id: number, event: MouseEvent) {
  if (selected.value.size === 0 || event.metaKey || event.ctrlKey) return
  event.preventDefault()
  onToggle(id, event)
}

function toggleAll(value: boolean | 'indeterminate') {
  selected.value = value === true ? new Set(pageIds.value) : new Set()
  selectionAnchor = null
}

function clearSelection() {
  selected.value = new Set()
  selectionAnchor = null
}

async function onBulkFinished(message: string, failedIds: number[], tone: 'success' | 'warning') {
  notice.value = { message, tone }
  // Failed images stay selected so the action can be repeated on them.
  selected.value = new Set(failedIds)
  await loadImages(pagination.value?.page ?? 1, { keepSelection: true })
}

const batchOptions = computed(() => [
  { label: 'Все партии', value: ALL },
  ...batches.value.map((b) => ({ label: b.name || `Партия #${b.id}`, value: b.id })),
])

// Tags are looked up on the server (name and aliases), so the list only ever holds the latest search
// result. `knownTags` remembers every option seen so far: a selected tag stays in the menu, with its
// label, after a later search replaced the result list.
const tagResults = ref<TagOption[]>([])
const knownTags = new Map<number, TagOption>()

const tagOptions = computed<TagOption[]>(() => {
  const selected = filters.tagIds.map((id) => knownTags.get(id) ?? { value: id, label: `Тег #${id}` })
  return [...selected, ...tagResults.value.filter((option) => !filters.tagIds.includes(option.value))]
})

const hasActiveFilters = computed(
  () =>
    filters.status !== ALL ||
    filters.batchId !== ALL ||
    filters.tagIds.length > 0 ||
    filters.dateFrom !== '' ||
    filters.dateTo !== ''
)

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

function visibleTags(image: ImageSummary) {
  return image.tags.slice(0, MAX_CARD_TAGS)
}

function hiddenTagCount(image: ImageSummary) {
  return Math.max(0, image.tags.length - MAX_CARD_TAGS)
}

const createdFormat = new Intl.DateTimeFormat('ru-RU', { year: 'numeric', month: 'short', day: 'numeric' })

function formatCreated(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : createdFormat.format(date)
}

function formatDateTime(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('ru-RU')
}

const imagesApi = useImages()
const batchesApi = useBatches()
const tagsApi = useTags()

// Filters can change faster than the API answers; only the newest request may update the grid.
let loadSeq = 0

async function loadImages(page = 1, options: { keepSelection?: boolean } = {}) {
  const seq = ++loadSeq
  if (!options.keepSelection) clearSelection()
  viewerIndex.value = null
  // A reload after a bulk action keeps the grid on screen instead of flashing the loading state.
  if (!options.keepSelection) loading.value = true
  loadError.value = null
  try {
    const data = await imagesApi.listImages({
      page,
      per_page: 24,
      sort: sort.value,
      status: filters.status === ALL ? undefined : filters.status,
      batch_id: filters.batchId === ALL ? undefined : filters.batchId,
      tag_ids: filters.tagIds.length > 0 ? filters.tagIds : undefined,
      created_from: createdFromParam(filters.dateFrom),
      created_to: createdToParam(filters.dateTo),
    })
    if (seq !== loadSeq) return
    images.value = data.data
    pagination.value = data.meta
    selected.value = keepVisible(selected.value, pageIds.value)
  } catch (e) {
    if (seq !== loadSeq) return
    loadError.value = useApiError(e).message
  } finally {
    if (seq === loadSeq) loading.value = false
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

function tagLabel(tag: Tag): string {
  // Archived tags stay filterable: their links to images are preserved (state-machines.md).
  const type = labelOf(TAG_TYPE_LABELS, tag.type)
  return `${tag.name} (${tag.status === 'archived' ? `${type}, в архиве` : type})`
}

let tagSearchSeq = 0

async function onTagSearch(term: string) {
  const seq = ++tagSearchSeq
  try {
    const data = await tagsApi.listTags({ q: term.trim() || undefined, per_page: 20 })
    if (seq !== tagSearchSeq) return
    const options = data.data.map((tag: Tag) => ({ value: tag.id, label: tagLabel(tag) }))
    options.forEach((option) => knownTags.set(option.value, option))
    tagResults.value = options
  } catch (e) {
    // The tag filter degrades to an empty menu; the rest of the page keeps working.
  }
}

function resetFilters() {
  filters.status = ALL
  filters.batchId = ALL
  filters.tagIds = []
  filters.dateFrom = ''
  filters.dateTo = ''
}

async function submitAiSearch() {
  const query = aiQuery.value.trim()
  if (!query) return
  await navigateTo(semanticSearchLocation(query))
}

watch([filters, sort], () => loadImages())

onMounted(() => {
  loadImages()
  loadBatches()
  onTagSearch('')
})
</script>
