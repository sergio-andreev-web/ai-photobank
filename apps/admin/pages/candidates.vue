<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-3xl font-bold text-gray-900">Tag Candidates</h1>
      <p class="mt-2 text-gray-600">Review and manage AI-suggested tag candidates.</p>
    </div>

    <!-- Filters -->
    <div class="rounded-lg bg-white p-4 shadow">
      <UFormGroup label="Status">
        <USelect
          v-model="selectedStatus"
          :options="statusOptions"
          option-attribute="label"
          value-attribute="value"
          @change="loadCandidates"
        />
      </UFormGroup>
    </div>

    <!-- Candidates List -->
    <div v-if="loading" class="text-center text-gray-600">
      Loading candidates...
    </div>

    <div v-else-if="candidates.length === 0" class="rounded-lg bg-white p-12 text-center shadow">
      <p class="text-gray-600">No pending candidates found.</p>
    </div>

    <div v-else class="space-y-4">
      <div v-for="candidate in candidates" :key="candidate.id" class="rounded-lg bg-white p-6 shadow">
        <div class="flex items-start justify-between">
          <div class="flex-1">
            <p class="text-sm text-gray-600">From image #{{ candidate.image_id }}</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ candidate.concept }}</p>
            <p class="text-sm text-gray-600 mt-2">Confidence: {{ (candidate.confidence * 100).toFixed(1) }}%</p>

            <div v-if="candidate.resolved_tag" class="mt-3 p-3 bg-blue-50 rounded">
              <p class="text-sm text-blue-900">Resolved to: <strong>{{ candidate.resolved_tag.name }}</strong></p>
            </div>
          </div>

          <div class="flex gap-2">
            <UButton
              v-if="candidate.status === 'pending'"
              color="green"
              variant="soft"
              size="sm"
              @click="handleApprove(candidate)"
            >
              Approve as Tag
            </UButton>
            <UButton
              v-if="candidate.status === 'pending'"
              color="orange"
              variant="soft"
              size="sm"
              @click="handleMerge(candidate)"
            >
              Merge into
            </UButton>
            <UButton
              v-if="candidate.status === 'pending'"
              color="red"
              variant="soft"
              size="sm"
              @click="handleReject(candidate)"
            >
              Reject
            </UButton>
            <UBadge v-else :color="getCandidateColor(candidate.status)">
              {{ candidate.status }}
            </UBadge>
          </div>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="pagination && pagination.last_page > 1" class="flex justify-center gap-2">
      <UButton
        v-if="pagination.page > 1"
        variant="ghost"
        @click="loadCandidates(pagination.page - 1)"
      >
        Previous
      </UButton>
      <span class="flex items-center text-sm text-gray-600">
        Page {{ pagination.page }} of {{ pagination.last_page }}
      </span>
      <UButton
        v-if="pagination.page < pagination.last_page"
        variant="ghost"
        @click="loadCandidates(pagination.page + 1)"
      >
        Next
      </UButton>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { TagCandidate } from '~/types/api'

const api = useApi()

const candidates = ref<TagCandidate[]>([])
const loading = ref(false)
const pagination = ref<any>(null)
const selectedStatus = ref('pending')

const statusOptions = [
  { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' },
  { label: 'Merged', value: 'merged' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'All', value: '' },
]

function getCandidateColor(status: string): string {
  const colors: Record<string, string> = {
    pending: 'amber',
    approved: 'green',
    merged: 'blue',
    rejected: 'gray',
  }
  return colors[status] || 'gray'
}

async function loadCandidates(page: number = 1) {
  loading.value = true
  try {
    const params: any = { page, per_page: 20 }
    if (selectedStatus.value) params.status = selectedStatus.value

    const { data } = await api.get('/admin/tag-candidates', { params })
    candidates.value = data.data
    pagination.value = data.meta
  } catch (e) {
    console.error('Failed to load candidates:', e)
  } finally {
    loading.value = false
  }
}

async function handleApprove(candidate: TagCandidate) {
  try {
    await api.patch(`/admin/tag-candidates/${candidate.id}`, { status: 'approved' })
    await loadCandidates(pagination.value?.page || 1)
  } catch (e) {
    console.error('Failed to approve candidate:', e)
  }
}

async function handleMerge(candidate: TagCandidate) {
  try {
    const tagId = prompt('Enter tag ID to merge into:')
    if (tagId) {
      await api.patch(`/admin/tag-candidates/${candidate.id}`, {
        status: 'merged',
        tag_id: parseInt(tagId),
      })
      await loadCandidates(pagination.value?.page || 1)
    }
  } catch (e) {
    console.error('Failed to merge candidate:', e)
  }
}

async function handleReject(candidate: TagCandidate) {
  try {
    await api.patch(`/admin/tag-candidates/${candidate.id}`, { status: 'rejected' })
    await loadCandidates(pagination.value?.page || 1)
  } catch (e) {
    console.error('Failed to reject candidate:', e)
  }
}

onMounted(() => {
  loadCandidates()
})
</script>
