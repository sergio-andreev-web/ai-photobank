<template>
  <div>
    <div class="page-heading flex flex-wrap items-end justify-between gap-4">
      <div><span class="subtle-label">LIBRARY STRUCTURE</span><h1>Tags Management</h1><p>Keep canonical tags, aliases, and the hierarchy organized.</p></div>
      <UButton icon="i-heroicons-plus" size="lg" @click="openCreate">New Tag</UButton>
    </div>

    <ErrorAlert v-if="loadError" :message="loadError" @dismiss="loadError = null" />
    <ErrorAlert v-if="actionError" :message="actionError" @dismiss="actionError = null" />

    <section v-if="showEditor" class="surface-card mb-5 p-6" aria-label="Tag editor">
      <div class="mb-5 flex items-start justify-between gap-3">
        <div><span class="subtle-label">{{ editingTag ? 'EDIT TAG' : 'NEW TAG' }}</span><h2 class="section-title mt-1">{{ editingTag ? `Edit ${editingTag.name}` : 'Create a canonical tag' }}</h2></div>
        <UButton variant="ghost" color="neutral" icon="i-heroicons-x-mark" aria-label="Close tag editor" @click="closeEditor" />
      </div>
      <form class="grid gap-4 md:grid-cols-3" @submit.prevent="saveTag">
        <UFormField label="Name" required><UInput v-model="form.name" placeholder="e.g., Excavator" class="w-full" /></UFormField>
        <UFormField label="Type" required><USelect v-model="form.type" :items="typeOptions" class="w-full" /></UFormField>
        <UFormField label="Parent tag"><USelect v-model="form.parent_id" :items="parentOptions" class="w-full" /></UFormField>
        <div class="md:col-span-3 flex items-center gap-3">
          <UButton type="submit" :disabled="!form.name.trim()" :loading="saving">{{ editingTag ? 'Save changes' : 'Create Tag' }}</UButton>
          <UButton variant="ghost" color="neutral" @click="closeEditor">Cancel</UButton>
        </div>
      </form>
    </section>

    <section v-if="deleteTarget" class="surface-card mb-5 border-red-200 bg-red-50 p-5" aria-label="Delete confirmation">
      <h2 class="font-semibold text-red-900">Delete {{ deleteTarget.name }}?</h2>
      <p class="mt-1 text-sm text-red-700">This tag will be archived if it is already used by images.</p>
      <div class="mt-4 flex gap-2"><UButton color="error" :loading="deleting" @click="confirmDelete">Delete tag</UButton><UButton variant="ghost" color="neutral" @click="() => { deleteTarget = null }">Cancel</UButton></div>
    </section>

    <section class="surface-card p-6">
      <div class="mb-5 flex items-center justify-between"><div><span class="subtle-label">TAXONOMY</span><h2 class="section-title mt-1">All tags</h2></div><span class="text-sm text-gray-500">{{ tags.length }} total</span></div>
      <div v-if="loading" class="text-sm text-gray-500">Loading tags...</div>
      <div v-else-if="rootTags.length === 0" class="rounded-xl bg-gray-50 p-9 text-center"><UIcon name="i-heroicons-tag" class="text-3xl text-gray-400" /><p class="mt-2 text-sm text-gray-600">No tags yet. Create one to get started.</p></div>
      <div v-else class="space-y-2">
        <TagTreeNode v-for="tag in rootTags" :key="tag.id" :tag="tag" @edit="openEdit" @delete="deleteTarget = $event" @refresh="loadTags" />
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

import type { Tag, TagType } from '~/types/api'
import { fetchAllPages } from '~/utils/pagination'

const api = useApi()
const tagsApi = useTags()
const tags = ref<Tag[]>([])
const loading = ref(false)
const saving = ref(false)
const deleting = ref(false)
const loadError = ref<string | null>(null)
const actionError = ref<string | null>(null)
const showEditor = ref(false)
const editingTag = ref<Tag | null>(null)
const deleteTarget = ref<Tag | null>(null)
const form = reactive<{ name: string; type: TagType; parent_id: number | 'none' }>({ name: '', type: 'object', parent_id: 'none' })

const rootTags = computed(() => {
  const byId = new Map(tags.value.map(tag => [tag.id, { ...tag, children: [] as Tag[] }]))
  const roots: Tag[] = []
  for (const tag of byId.values()) {
    const parent = tag.parent_id ? byId.get(tag.parent_id) : null
    if (parent && parent.id !== tag.id) parent.children?.push(tag)
    else roots.push(tag)
  }
  return roots
})
const typeOptions: { label: string; value: TagType }[] = [
  'object', 'person', 'activity', 'scene', 'location', 'season', 'weather', 'brand', 'event', 'color', 'concept', 'other',
].map(type => ({ label: type.charAt(0).toUpperCase() + type.slice(1), value: type as TagType }))
const parentOptions = computed(() => [
  { label: 'No parent', value: 'none' as const },
  ...tags.value.filter(tag => tag.id !== editingTag.value?.id && tag.status === 'active').map(tag => ({ label: tag.name, value: tag.id })),
])

function openCreate() {
  editingTag.value = null
  Object.assign(form, { name: '', type: 'object', parent_id: 'none' })
  showEditor.value = true
  deleteTarget.value = null
}
function openEdit(tag: Tag) {
  editingTag.value = tag
  Object.assign(form, { name: tag.name, type: tag.type, parent_id: tag.parent_id ?? 'none' })
  showEditor.value = true
  deleteTarget.value = null
}
function closeEditor() { showEditor.value = false; editingTag.value = null }

async function loadTags() {
  loading.value = true
  loadError.value = null
  try {
    tags.value = await fetchAllPages<Tag>(async (page) => {
      const { data } = await api.get('/admin/tags', { params: { per_page: 500, page } })
      return data
    })
  } catch (e) {
    loadError.value = useApiError(e).message
  } finally { loading.value = false }
}

async function saveTag() {
  if (!form.name.trim()) return
  saving.value = true
  actionError.value = null
  try {
    const parent_id = form.parent_id === 'none' ? null : form.parent_id
    if (editingTag.value) await tagsApi.updateTag(editingTag.value.id, { name: form.name.trim(), type: form.type, parent_id })
    else await tagsApi.createTag({ name: form.name.trim(), type: form.type, parent_id })
    closeEditor()
    await loadTags()
  } catch (e) { actionError.value = useApiError(e).message }
  finally { saving.value = false }
}

async function confirmDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  actionError.value = null
  try {
    await tagsApi.deleteTag(deleteTarget.value.id)
    deleteTarget.value = null
    await loadTags()
  } catch (e) { actionError.value = useApiError(e).message }
  finally { deleting.value = false }
}

onMounted(loadTags)
</script>
