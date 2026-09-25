<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-3xl font-bold text-gray-900">Search Images</h1>
      <p class="mt-2 text-gray-600">Search your photobank by tags or semantic query.</p>
    </div>

    <!-- Search Form -->
    <div class="rounded-lg bg-white p-6 shadow">
      <form class="space-y-4" @submit.prevent="handleSearch">
        <div class="grid gap-4 md:grid-cols-2">
          <UFormGroup label="Search Query" name="query">
            <UInput
              v-model="form.q"
              placeholder="e.g., workers in hard hats"
            />
          </UFormGroup>

          <UFormGroup label="Search Mode" name="mode">
            <USelect
              v-model="form.mode"
              :options="modeOptions"
              option-attribute="label"
              value-attribute="value"
            />
          </UFormGroup>
        </div>

        <UFormGroup v-if="form.mode === 'tags'" label="Tags (optional)" name="tags">
          <USelectMenu
            v-model="selectedTagIds"
            :options="tags"
            multiple
            option-attribute="name"
            value-attribute="id"
            placeholder="Select tags to filter"
          />
        </UFormGroup>

        <div class="flex gap-2">
          <UButton type="submit" :loading="searching">
            Search
          </UButton>
          <UButton
            v-if="form.debug === false"
            color="gray"
            variant="ghost"
            @click="form.debug = true"
          >
            Enable Debug
          </UButton>
        </div>
      </form>
    </div>

    <!-- Results -->
    <div v-if="searched && loading" class="text-center text-gray-600">
      Searching...
    </div>

    <div v-else-if="searched && results.length === 0" class="rounded-lg bg-white p-12 text-center shadow">
      <p class="text-gray-600">No results found. Try a different query or search mode.</p>
    </div>

    <div v-else-if="results.length > 0" class="rounded-lg bg-white p-6 shadow">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">
        Results ({{ pagination?.total || 0 }})
      </h2>

      <div class="grid gap-4 md:grid-cols-4">
        <NuxtLink
          v-for="result in results"
          :key="result.id"
          :to="`/gallery/${result.id}`"
          class="group block overflow-hidden rounded-lg border border-gray-200 hover:shadow-lg transition-shadow"
        >
          <div class="aspect-square overflow-hidden bg-gray-200 flex items-center justify-center">
            <div class="text-4xl">📷</div>
          </div>
          <div class="p-3">
            <p class="truncate text-sm font-medium text-gray-900">{{ result.filename }}</p>
            <p v-if="result.similarity" class="text-xs text-gray-600 mt-1">
              Match: {{ (result.similarity * 100).toFixed(1) }}%
            </p>
          </div>
        </NuxtLink>
      </div>

      <!-- Pagination -->
      <div v-if="pagination && pagination.last_page > 1" class="mt-6 flex justify-center gap-2">
        <UButton
          v-if="pagination.page > 1"
          variant="ghost"
          @click="handleSearch(pagination.page - 1)"
        >
          Previous
        </UButton>
        <span class="flex items-center text-sm text-gray-600">
          Page {{ pagination.page }} of {{ pagination.last_page }}
        </span>
        <UButton
          v-if="pagination.page < pagination.last_page"
          variant="ghost"
          @click="handleSearch(pagination.page + 1)"
        >
          Next
        </UButton>
      </div>
    </div>

    <!-- Debug Info -->
    <div v-if="form.debug && debugInfo" class="rounded-lg bg-gray-50 p-4 shadow">
      <h3 class="font-semibold text-gray-900">Debug Info</h3>
      <pre class="mt-2 overflow-auto text-xs text-gray-600">{{ JSON.stringify(debugInfo, null, 2) }}</pre>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { SearchResponse, SearchResult, Tag } from '~/types/api'

const api = useApi()

const form = reactive({
  q: '',
  mode: 'hybrid' as 'tags' | 'semantic' | 'hybrid',
  debug: false,
})

const selectedTagIds = ref<number[]>([])
const tags = ref<Tag[]>([])
const results = ref<SearchResult[]>([])
const pagination = ref<any>(null)
const searching = ref(false)
const searched = ref(false)
const debugInfo = ref<any>(null)

const modeOptions = [
  { label: 'Hybrid (Tags + Semantic)', value: 'hybrid' },
  { label: 'Tags Only', value: 'tags' },
  { label: 'Semantic Query', value: 'semantic' },
]

async function handleSearch(page: number = 1) {
  if (!form.q && selectedTagIds.value.length === 0) return

  searching.value = true
  searched.value = true

  try {
    const payload: any = {
      q: form.q,
      mode: form.mode,
      page,
      per_page: 20,
    }

    if (selectedTagIds.value.length > 0) {
      payload.tag_ids = selectedTagIds.value
    }

    if (form.debug) {
      payload.debug = true
    }

    const { data } = await api.post<SearchResponse>('/admin/search', payload)
    results.value = data.data
    pagination.value = data.meta
    debugInfo.value = data.debug
  } catch (e) {
    console.error('Search failed:', e)
    results.value = []
  } finally {
    searching.value = false
  }
}

async function loadTags() {
  try {
    const { data } = await api.get('/admin/tags', { params: { per_page: 1000 } })
    tags.value = data.data
  } catch (e) {
    console.error('Failed to load tags:', e)
  }
}

onMounted(() => {
  loadTags()
})
</script>
