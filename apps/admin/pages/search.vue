<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-3xl font-bold text-gray-900">Search Images</h1>
      <p class="mt-2 text-gray-600">Search by tags, semantic meaning, or both combined.</p>
    </div>

    <!-- Search Form -->
    <div class="rounded-lg bg-white p-6 shadow">
      <form class="space-y-4" @submit.prevent="handleSearch()">
        <UFormField label="Search Mode" name="mode">
          <USelect v-model="mode" :items="modeOptions" />
        </UFormField>

        <UFormField v-if="mode !== 'tags'" label="Query" name="query">
          <UInput v-model="query" placeholder="e.g., workers in hard hats near industrial equipment" />
        </UFormField>

        <UFormField v-if="mode !== 'semantic'" :label="tagsLabel" name="tags">
          <USelectMenu
            v-model="selectedTags"
            :items="tagOptions"
            value-key="value"
            multiple
            placeholder="Select tags (all must match)"
            @update:search-term="onTagSearch"
          />
        </UFormField>

        <div class="flex items-center gap-4">
          <UButton type="submit" :loading="searching" :disabled="!canSearch"> Search </UButton>
          <UCheckbox v-model="debug" label="Debug (show similarity score)" />
        </div>
        <p v-if="!canSearch" class="text-xs text-gray-500">{{ validationHint }}</p>
      </form>
    </div>

    <ErrorAlert
      v-if="searchError"
      :title="searchErrorCode === 'AI_SERVICE_UNAVAILABLE' ? 'AI service unavailable' : 'Search failed'"
      :message="searchError"
      @dismiss="searchError = null"
    >
    </ErrorAlert>
    <div v-if="searchErrorCode === 'AI_SERVICE_UNAVAILABLE'" class="-mt-4">
      <UButton size="sm" variant="soft" :loading="searching" @click="handleSearch()"> Retry search </UButton>
    </div>

    <!-- Results -->
    <div v-if="searched && searching" class="text-center text-gray-600">Searching...</div>

    <div v-else-if="searched && !searchError && results.length === 0" class="rounded-lg bg-white p-12 text-center shadow">
      <p class="text-gray-600">No results found. Try a different query or search mode.</p>
    </div>

    <div v-else-if="results.length > 0" class="rounded-lg bg-white p-6 shadow">
      <h2 class="mb-4 text-lg font-semibold text-gray-900">Results ({{ pagination?.total || 0 }})</h2>

      <div class="grid gap-4 md:grid-cols-4" data-testid="search-results">
        <NuxtLink
          v-for="result in results"
          :key="result.id"
          :to="`/gallery/${result.id}`"
          class="group block overflow-hidden rounded-lg border border-gray-200 transition-shadow hover:shadow-lg"
        >
          <div class="flex aspect-square items-center justify-center overflow-hidden bg-gray-200">
            <img
              v-if="result.urls.thumbnail"
              :src="result.urls.thumbnail"
              :alt="result.original_name"
              class="h-full w-full object-cover"
            />
            <div v-else class="text-4xl">📷</div>
          </div>
          <div class="p-3">
            <p class="truncate text-sm font-medium text-gray-900">{{ result.original_name }}</p>
            <p v-if="debug && result.semantic_score != null" class="mt-1 text-xs text-gray-600">
              Score: {{ result.semantic_score.toFixed(3) }}
            </p>
          </div>
        </NuxtLink>
      </div>

      <!-- Pagination -->
      <div v-if="pagination && pagination.last_page > 1" class="mt-6 flex justify-center gap-2">
        <UButton v-if="pagination.page > 1" variant="ghost" @click="handleSearch(pagination.page - 1)"> Previous </UButton>
        <span class="flex items-center text-sm text-gray-600"> Page {{ pagination.page }} of {{ pagination.last_page }} </span>
        <UButton v-if="pagination.page < pagination.last_page" variant="ghost" @click="handleSearch(pagination.page + 1)">
          Next
        </UButton>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { PaginationMeta, SearchMode, SearchRequest, SearchResultItem, Tag } from '~/types/api'

const searchApi = useSearch()
const tagsApi = useTags()

const mode = ref<SearchMode>('hybrid')
const query = ref('')
const selectedTags = ref<number[]>([])
const tagOptions = ref<{ label: string; value: number }[]>([])
const debug = ref(false)

const results = ref<SearchResultItem[]>([])
const pagination = ref<(PaginationMeta & { mode: SearchMode }) | null>(null)
const searching = ref(false)
const searched = ref(false)
const searchError = ref<string | null>(null)
const searchErrorCode = ref<string | null>(null)

const modeOptions: { label: string; value: SearchMode }[] = [
  { label: 'Hybrid (tags + semantic)', value: 'hybrid' },
  { label: 'Tags only', value: 'tags' },
  { label: 'Semantic query', value: 'semantic' },
]

const tagsLabel = computed(() => (mode.value === 'hybrid' ? 'Tags (required filter)' : 'Tags'))

const canSearch = computed(() => {
  if (mode.value === 'tags') return selectedTags.value.length > 0
  if (mode.value === 'semantic') return query.value.trim().length > 0
  return query.value.trim().length > 0 && selectedTags.value.length > 0
})

const validationHint = computed(() => {
  if (mode.value === 'tags') return 'Select at least one tag.'
  if (mode.value === 'semantic') return 'Enter a search query.'
  return 'Hybrid search needs both a query and at least one tag.'
})

async function onTagSearch(q: string) {
  try {
    const data = await tagsApi.listTags({ q: q || undefined, per_page: 20, status: 'active' })
    tagOptions.value = data.data.map((tag: Tag) => ({ value: tag.id, label: `${tag.name} (${tag.type})` }))
  } catch (e) {
    // Tag lookup failing shouldn't block the rest of the search form.
  }
}

function buildRequest(page: number): SearchRequest {
  const tagIds = selectedTags.value
  const base = { page, per_page: 24, debug: debug.value }
  if (mode.value === 'tags') {
    return { ...base, mode: 'tags', tag_ids: tagIds }
  }
  if (mode.value === 'semantic') {
    return { ...base, mode: 'semantic', query: query.value.trim() }
  }
  return { ...base, mode: 'hybrid', query: query.value.trim(), tag_ids: tagIds }
}

async function handleSearch(page = 1) {
  if (!canSearch.value) return

  searching.value = true
  searched.value = true
  searchError.value = null
  searchErrorCode.value = null

  try {
    const data = await searchApi.search(buildRequest(page))
    results.value = data.data
    pagination.value = data.meta
  } catch (e) {
    const { code, message } = useApiError(e)
    searchErrorCode.value = code
    searchError.value = message
    results.value = []
    pagination.value = null
  } finally {
    searching.value = false
  }
}

onMounted(() => {
  onTagSearch('')
})
</script>
