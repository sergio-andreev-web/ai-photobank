<template>
  <div class="space-y-6">
    <div class="page-heading">
      <span class="subtle-label">ПОИСК ПО БИБЛИОТЕКЕ</span>
      <h1>Поиск изображений</h1>
      <p>Ищите по тегам, по смыслу запроса или сочетайте оба способа.</p>
    </div>

    <!-- Search Form -->
    <div class="surface-card p-6">
      <form class="max-w-2xl space-y-5" @submit.prevent="handleSearch()">
        <UFormField label="Режим поиска" name="mode">
          <USelect v-model="mode" :items="modeOptions" class="w-full" />
        </UFormField>

        <UFormField v-if="mode !== 'tags'" label="Запрос" name="query">
          <UInput v-model="query" placeholder="Например: рабочие в касках возле промышленного оборудования" class="w-full" />
        </UFormField>

        <UFormField v-if="mode !== 'semantic'" :label="tagsLabel" name="tags">
          <USelectMenu
            v-model="selectedTags"
            :items="tagOptions"
            value-key="value"
            multiple
            placeholder="Выберите теги (нужны все)"
            class="w-full"
            @update:search-term="onTagSearch"
          />
        </UFormField>

        <div class="flex items-center gap-4">
          <UButton type="submit" :loading="searching" :disabled="!canSearch"> Найти </UButton>
          <UCheckbox v-model="debug" label="Отладка (показать степень сходства)" />
        </div>
        <p v-if="!canSearch" class="text-xs text-gray-500">{{ validationHint }}</p>
      </form>
    </div>

    <ErrorAlert
      v-if="searchError"
      :title="searchErrorCode === 'AI_SERVICE_UNAVAILABLE' ? 'ИИ-сервис недоступен' : 'Поиск не удался'"
      :message="searchError"
      @dismiss="searchError = null"
    >
    </ErrorAlert>
    <div v-if="searchErrorCode === 'AI_SERVICE_UNAVAILABLE'" class="-mt-4">
      <UButton size="sm" variant="soft" :loading="searching" @click="handleSearch()"> Повторить поиск </UButton>
    </div>

    <!-- Results -->
    <div v-if="searched && searching" class="text-center text-gray-600">Идёт поиск…</div>

    <div v-else-if="searched && !searchError && results.length === 0" class="surface-card p-12 text-center">
      <p class="text-gray-600">Ничего не найдено. Попробуйте другой запрос или режим поиска.</p>
    </div>

    <div v-else-if="results.length > 0" class="surface-card p-6">
      <h2 class="mb-4 text-lg font-semibold text-gray-900">Результаты ({{ pagination?.total || 0 }})</h2>

      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="search-results">
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
            <div v-else class="text-4xl text-slate-300"><UIcon name="i-heroicons-photo" /></div>
          </div>
          <div class="p-3">
            <p class="truncate text-sm font-medium text-gray-900">{{ result.original_name }}</p>
            <p v-if="debug && result.semantic_score != null" class="mt-1 text-xs text-gray-600">
              Сходство: {{ result.semantic_score.toFixed(3) }}
            </p>
          </div>
        </NuxtLink>
      </div>

      <!-- Pagination -->
      <div v-if="pagination && pagination.last_page > 1" class="mt-6 flex justify-center gap-2">
        <UButton v-if="pagination.page > 1" variant="ghost" @click="handleSearch(pagination.page - 1)"> Назад </UButton>
        <span class="flex items-center text-sm text-gray-600"> Страница {{ pagination.page }} из {{ pagination.last_page }} </span>
        <UButton v-if="pagination.page < pagination.last_page" variant="ghost" @click="handleSearch(pagination.page + 1)">
          Вперёд
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
import { labelOf, TAG_TYPE_LABELS } from '~/utils/labels'
import { readSearchRouteQuery } from '~/utils/searchRoute'

const route = useRoute()
const searchApi = useSearch()
const tagsApi = useTags()

// Semantic by default: it needs no tags, so it works on a fresh library too.
const mode = ref<SearchMode>('semantic')
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
  { label: 'По смыслу запроса', value: 'semantic' },
  { label: 'Гибридный (теги + смысл)', value: 'hybrid' },
  { label: 'Только теги', value: 'tags' },
]

const tagsLabel = computed(() => (mode.value === 'hybrid' ? 'Теги (обязательный фильтр)' : 'Теги'))

const canSearch = computed(() => {
  if (mode.value === 'tags') return selectedTags.value.length > 0
  if (mode.value === 'semantic') return query.value.trim().length > 0
  return query.value.trim().length > 0 && selectedTags.value.length > 0
})

const validationHint = computed(() => {
  if (mode.value === 'tags') return 'Выберите хотя бы один тег.'
  if (mode.value === 'semantic') return 'Введите поисковый запрос.'
  return 'Для гибридного поиска нужны запрос и хотя бы один тег.'
})

async function onTagSearch(q: string) {
  try {
    const data = await tagsApi.listTags({ q: q || undefined, per_page: 20, status: 'active' })
    tagOptions.value = data.data.map((tag: Tag) => ({ value: tag.id, label: `${tag.name} (${labelOf(TAG_TYPE_LABELS, tag.type)})` }))
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

// Deep link, e.g. the gallery's AI search box: /search?mode=semantic&q=… prefills the form and runs the
// search. It only runs when the prefilled form is valid for the mode (hybrid still needs tags).
function applyRouteQuery() {
  const { mode: routeMode, q } = readSearchRouteQuery(route.query)
  if (routeMode) mode.value = routeMode
  if (q !== undefined) query.value = q
  if (canSearch.value) void handleSearch()
}

onMounted(() => {
  onTagSearch('')
  applyRouteQuery()
})
</script>
