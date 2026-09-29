<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-3xl font-bold text-gray-900">Tag Candidates</h1>
      <p class="mt-2 text-gray-600">Review AI-suggested concepts that are not yet canonical tags.</p>
    </div>

    <!-- Filters -->
    <div class="rounded-lg bg-white p-4 shadow">
      <UFormField label="Status">
        <USelect v-model="selectedStatus" :items="statusOptions" />
      </UFormField>
    </div>

    <ErrorAlert v-if="loadError" :message="loadError" @dismiss="loadError = null" />
    <ErrorAlert v-if="actionError" :message="actionError" @dismiss="actionError = null" />

    <!-- Candidates List -->
    <div v-if="loading" class="text-center text-gray-600">Loading candidates...</div>

    <div v-else-if="candidates.length === 0" class="rounded-lg bg-white p-12 text-center shadow">
      <p class="text-gray-600">No candidates found for this filter.</p>
    </div>

    <div v-else class="space-y-4">
      <div v-for="candidate in candidates" :key="candidate.id" class="rounded-lg bg-white p-6 shadow">
        <div class="flex items-start justify-between gap-4">
          <div class="flex-1">
            <p class="text-2xl font-bold text-gray-900">{{ candidate.display_name }}</p>
            <p class="mt-1 text-sm text-gray-600">
              {{ candidate.type }} &middot; seen in {{ candidate.occurrences_count }} image(s)
            </p>

            <div v-if="candidate.closest_tag" class="mt-2 text-sm text-gray-600">
              Closest existing tag: <strong>{{ candidate.closest_tag.name }}</strong>
              <span v-if="candidate.similarity != null"> ({{ (candidate.similarity * 100).toFixed(0) }}% similar)</span>
            </div>

            <div v-if="candidate.sample_images.length" class="mt-3 flex gap-2">
              <img
                v-for="sample in candidate.sample_images"
                :key="sample.id"
                :src="sample.thumbnail_url ?? undefined"
                class="h-16 w-16 rounded object-cover"
                :alt="`Sample image #${sample.id}`"
              />
            </div>

            <div v-if="candidate.status !== 'pending'" class="mt-3 rounded bg-blue-50 p-3 text-sm text-blue-900">
              Resolved as <strong>{{ candidate.status }}</strong>
              <span v-if="candidate.resolved_tag_id"> → tag #{{ candidate.resolved_tag_id }}</span>
            </div>
          </div>

          <div v-if="candidate.status === 'pending'" class="flex shrink-0 flex-col gap-2">
            <UButton
              color="green"
              variant="soft"
              size="sm"
              :loading="pendingAction(candidate.id) === 'approve'"
              @click="handleApprove(candidate)"
            >
              Create Tag
            </UButton>
            <UButton
              color="orange"
              variant="soft"
              size="sm"
              @click="openMerge(candidate)"
            >
              Merge Into...
            </UButton>
            <UButton
              color="red"
              variant="soft"
              size="sm"
              :loading="pendingAction(candidate.id) === 'reject'"
              @click="handleReject(candidate)"
            >
              Reject
            </UButton>
          </div>
        </div>

        <!-- Inline merge form -->
        <div v-if="mergeTargetId === candidate.id" class="mt-4 flex items-end gap-2 border-t pt-4">
          <UFormField label="Target tag" class="flex-1">
            <USelectMenu
              v-model="mergeTag"
              :items="mergeOptions"
              value-key="value"
              placeholder="Search tags..."
              @update:search-term="onMergeSearch"
            />
          </UFormField>
          <UCheckbox v-model="mergeCreateAlias" label="Add as alias" />
          <UButton :disabled="!mergeTag" :loading="pendingAction(candidate.id) === 'merge'" @click="handleMerge(candidate)">
            Confirm Merge
          </UButton>
          <UButton variant="ghost" @click="mergeTargetId = null">Cancel</UButton>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="pagination && pagination.last_page > 1" class="flex justify-center gap-2">
      <UButton v-if="pagination.page > 1" variant="ghost" @click="loadCandidates(pagination.page - 1)"> Previous </UButton>
      <span class="flex items-center text-sm text-gray-600"> Page {{ pagination.page }} of {{ pagination.last_page }} </span>
      <UButton v-if="pagination.page < pagination.last_page" variant="ghost" @click="loadCandidates(pagination.page + 1)">
        Next
      </UButton>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { PaginationMeta, Tag, TagCandidate, TagCandidateStatus } from '~/types/api'

// Nuxt UI v3 reserves an empty string `value` on a SelectItem to mean "clear
// selection" and throws if an item uses it, so "no filter" needs its own
// sentinel instead of ''.
const ALL = 'all' as const

const candidatesApi = useCandidates()
const tagsApi = useTags()

const candidates = ref<TagCandidate[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)
const actionError = ref<string | null>(null)
const pagination = ref<PaginationMeta | null>(null)
const selectedStatus = ref<TagCandidateStatus | typeof ALL>('pending')
const actionInFlight = ref<{ id: number; action: 'approve' | 'merge' | 'reject' } | null>(null)

const mergeTargetId = ref<number | null>(null)
const mergeTag = ref<number | null>(null)
const mergeCreateAlias = ref(true)
const mergeOptions = ref<{ label: string; value: number }[]>([])

const statusOptions: { label: string; value: TagCandidateStatus | typeof ALL }[] = [
  { label: 'Pending', value: 'pending' },
  { label: 'Created', value: 'created' },
  { label: 'Merged', value: 'merged' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'All', value: ALL },
]

function pendingAction(id: number) {
  return actionInFlight.value?.id === id ? actionInFlight.value.action : null
}

async function loadCandidates(page = 1) {
  loading.value = true
  loadError.value = null
  try {
    const data = await candidatesApi.listCandidates({
      page,
      per_page: 20,
      status: selectedStatus.value === ALL ? undefined : selectedStatus.value,
    })
    candidates.value = data.data
    pagination.value = data.meta
  } catch (e) {
    loadError.value = useApiError(e).message
  } finally {
    loading.value = false
  }
}

async function handleApprove(candidate: TagCandidate) {
  actionInFlight.value = { id: candidate.id, action: 'approve' }
  actionError.value = null
  try {
    await candidatesApi.approveCandidate(candidate.id)
    await loadCandidates(pagination.value?.page || 1)
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionInFlight.value = null
  }
}

async function handleReject(candidate: TagCandidate) {
  actionInFlight.value = { id: candidate.id, action: 'reject' }
  actionError.value = null
  try {
    await candidatesApi.rejectCandidate(candidate.id)
    await loadCandidates(pagination.value?.page || 1)
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionInFlight.value = null
  }
}

function openMerge(candidate: TagCandidate) {
  mergeTargetId.value = candidate.id
  mergeTag.value = null
  mergeCreateAlias.value = true
  onMergeSearch('')
}

async function onMergeSearch(query: string) {
  try {
    const data = await tagsApi.listTags({ q: query || undefined, per_page: 20, status: 'active' })
    mergeOptions.value = data.data.map((tag: Tag) => ({ value: tag.id, label: `${tag.name} (${tag.type})` }))
  } catch (e) {
    // Non-fatal: merge search box just stays empty.
  }
}

async function handleMerge(candidate: TagCandidate) {
  if (!mergeTag.value) return
  actionInFlight.value = { id: candidate.id, action: 'merge' }
  actionError.value = null
  try {
    await candidatesApi.mergeCandidate(candidate.id, {
      tag_id: mergeTag.value,
      create_alias: mergeCreateAlias.value,
    })
    mergeTargetId.value = null
    await loadCandidates(pagination.value?.page || 1)
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionInFlight.value = null
  }
}

watch(selectedStatus, () => loadCandidates())

onMounted(() => {
  loadCandidates()
})
</script>
