<template>
  <div class="space-y-6">
    <div class="page-heading flex flex-wrap items-end justify-between gap-4">
      <div>
        <span class="subtle-label">IMAGE LIBRARY</span>
        <h1>Gallery</h1>
        <p>Browse and manage your images.</p>
      </div>
      <UButton to="/uploader" icon="i-heroicons-arrow-up-tray" size="lg">Upload Images</UButton>
    </div>

    <!-- AI search + filters -->
    <div class="surface-card space-y-5 p-5">
      <!-- Hands the query over to the search page in semantic mode; the gallery grid is not filtered by it. -->
      <form class="flex flex-wrap gap-3" role="search" data-testid="ai-search-form" @submit.prevent="submitAiSearch">
        <UInput
          v-model="aiQuery"
          icon="i-heroicons-sparkles"
          placeholder="AI search: describe what you are looking for"
          aria-label="AI search"
          maxlength="1000"
          class="min-w-[240px] flex-1"
        />
        <UButton type="submit" :disabled="!aiQuery.trim()">AI search</UButton>
      </form>

      <div class="flex flex-wrap gap-4">
        <UFormField
          label="Tags"
          help="An image must have all selected tags."
          class="min-w-[240px] flex-[2]"
          data-testid="tag-filter"
        >
          <USelectMenu
            v-model="filters.tagIds"
            :items="tagOptions"
            value-key="value"
            multiple
            ignore-filter
            placeholder="Any tag"
            class="w-full"
            @update:search-term="onTagSearch"
          />
        </UFormField>

        <UFormField label="Status" class="min-w-[160px] flex-1">
          <USelect v-model="filters.status" :items="statusOptions" class="w-full" />
        </UFormField>

        <UFormField label="Batch" class="min-w-[160px] flex-1">
          <USelect v-model="filters.batchId" :items="batchOptions" class="w-full" />
        </UFormField>

        <UFormField label="Created from" class="min-w-[160px] flex-1">
          <UInput v-model="filters.dateFrom" type="date" :max="filters.dateTo || undefined" class="w-full" />
        </UFormField>

        <UFormField label="Created to" class="min-w-[160px] flex-1">
          <UInput v-model="filters.dateTo" type="date" :min="filters.dateFrom || undefined" class="w-full" />
        </UFormField>
      </div>

      <div v-if="hasActiveFilters">
        <UButton variant="ghost" color="neutral" icon="i-heroicons-x-mark" size="sm" @click="resetFilters">
          Reset filters
        </UButton>
      </div>
    </div>

    <ErrorAlert v-if="loadError" :message="loadError" @dismiss="loadError = null" />

    <!-- Image Grid -->
    <div v-if="loading" class="text-center text-gray-600">Loading images...</div>

    <div v-else-if="images.length === 0" class="surface-card p-12 text-center">
      <p v-if="hasActiveFilters" class="text-gray-600">No images match these filters.</p>
      <template v-else>
        <p class="text-gray-600">No images found. Start by uploading a batch.</p>
        <UButton to="/uploader" class="mt-4"> Create First Batch </UButton>
      </template>
    </div>

    <div v-else class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4" data-testid="gallery-grid">
      <NuxtLink
        v-for="image in images"
        :key="image.id"
        :to="`/gallery/${image.id}`"
        class="surface-card group block overflow-hidden transition-shadow hover:shadow-lg"
        data-testid="gallery-card"
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
            <UBadge :color="statusColor(image.status)">{{ image.status }}</UBadge>
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
          <p v-else class="text-xs text-gray-400">No tags</p>
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

import type { Batch, ImageStatus, ImageSummary, PaginationMeta, Tag } from '~/types/api'
import { createdFromParam, createdToParam } from '~/utils/dateRange'
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

const filters = reactive<{
  status: ImageStatus | typeof ALL
  batchId: number | typeof ALL
  tagIds: number[]
  // `<input type="date">` values (YYYY-MM-DD, local day); '' means no bound.
  dateFrom: string
  dateTo: string
}>({
  status: ALL,
  batchId: ALL,
  tagIds: [],
  dateFrom: '',
  dateTo: '',
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

// Tags are looked up on the server (name and aliases), so the list only ever holds the latest search
// result. `knownTags` remembers every option seen so far: a selected tag stays in the menu, with its
// label, after a later search replaced the result list.
const tagResults = ref<TagOption[]>([])
const knownTags = new Map<number, TagOption>()

const tagOptions = computed<TagOption[]>(() => {
  const selected = filters.tagIds.map((id) => knownTags.get(id) ?? { value: id, label: `Tag #${id}` })
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

const createdFormat = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' })

function formatCreated(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : createdFormat.format(date)
}

function formatDateTime(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString()
}

const imagesApi = useImages()
const batchesApi = useBatches()
const tagsApi = useTags()

// Filters can change faster than the API answers; only the newest request may update the grid.
let loadSeq = 0

async function loadImages(page = 1) {
  const seq = ++loadSeq
  loading.value = true
  loadError.value = null
  try {
    const data = await imagesApi.listImages({
      page,
      per_page: 24,
      status: filters.status === ALL ? undefined : filters.status,
      batch_id: filters.batchId === ALL ? undefined : filters.batchId,
      tag_ids: filters.tagIds.length > 0 ? filters.tagIds : undefined,
      created_from: createdFromParam(filters.dateFrom),
      created_to: createdToParam(filters.dateTo),
    })
    if (seq !== loadSeq) return
    images.value = data.data
    pagination.value = data.meta
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
  return `${tag.name} (${tag.status === 'archived' ? `${tag.type}, archived` : tag.type})`
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

watch(filters, () => loadImages())

onMounted(() => {
  loadImages()
  loadBatches()
  onTagSearch('')
})
</script>
