<template>
  <div>
    <div class="page-heading flex flex-wrap items-end justify-between gap-4">
      <div><span class="subtle-label">LIBRARY STRUCTURE</span><h1>Tags Management</h1><p>Keep canonical tags, aliases, and the hierarchy organized.</p></div>
      <UButton icon="i-heroicons-plus" size="lg" @click="openCreate()">New Tag</UButton>
    </div>

    <div v-if="loadError" class="mb-5">
      <ErrorAlert title="Could not load tags" :message="loadError.message" :details="describeProblem(loadError)" @dismiss="loadError = null" />
      <UButton class="mt-3" size="sm" variant="soft" :loading="loading" @click="loadTags">Try again</UButton>
    </div>
    <ErrorAlert
      v-if="actionProblem"
      class="mb-5"
      :title="actionProblem.title"
      :message="actionProblem.problem.message"
      :details="describeProblem(actionProblem.problem, { lookupTag })"
      @dismiss="actionProblem = null"
    />
    <div v-if="notice" class="mb-5" role="status" aria-live="polite" data-testid="tags-notice">
      <UAlert color="success" variant="soft" icon="i-heroicons-check-circle" :title="notice" close @update:open="notice = null" />
    </div>

    <div class="grid items-start gap-5" :class="panelOpen ? 'lg:grid-cols-[minmax(0,1fr)_26rem]' : ''">
      <section class="surface-card p-6" aria-label="Tag tree">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
          <div><span class="subtle-label">TAXONOMY</span><h2 class="section-title mt-1">All tags</h2></div>
          <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            <span class="text-gray-500" data-testid="tags-total">{{ tags.length }} total<template v-if="archivedCount > 0"> · {{ archivedCount }} archived</template></span>
            <UCheckbox v-model="showArchived" label="Show archived" />
            <UButton size="xs" variant="ghost" color="neutral" @click="expandAll">Expand all</UButton>
            <UButton size="xs" variant="ghost" color="neutral" @click="collapseAll">Collapse all</UButton>
          </div>
        </div>

        <div v-if="loading" class="text-sm text-gray-500">Loading tags...</div>
        <div v-else-if="tags.length === 0" class="rounded-xl bg-gray-50 p-9 text-center">
          <UIcon name="i-heroicons-tag" class="text-3xl text-gray-400" />
          <p class="mt-2 text-sm text-gray-600">No tags yet. Create one to get started.</p>
        </div>
        <div v-else-if="visibleTree.length === 0" class="rounded-xl bg-gray-50 p-9 text-center">
          <p class="text-sm text-gray-600">Every tag is archived. Turn on “Show archived” to see them.</p>
        </div>
        <ul v-else class="space-y-2" aria-label="Tag hierarchy">
          <TagTreeNode
            v-for="node in visibleTree"
            :key="node.id"
            :tag="node"
            :expanded-ids="expandedIds"
            :selected-id="editingTag?.id ?? null"
            :busy-id="busyId"
            @toggle="toggleExpanded"
            @add-child="openCreate($event.id)"
            @edit="openEdit"
            @archive="setStatus($event, 'archived')"
            @restore="setStatus($event, 'active')"
            @delete="askDelete"
          />
        </ul>
      </section>

      <div v-if="panelOpen" class="order-first space-y-5 lg:order-none lg:sticky lg:top-6 lg:-m-1 lg:max-h-[calc(100vh-3rem)] lg:overflow-y-auto lg:p-1">
        <section v-if="deleteTarget" class="surface-card border-red-200 bg-red-50 p-5" aria-label="Delete confirmation">
          <h2 class="font-semibold text-red-900">Delete {{ deleteTarget.name }}?</h2>
          <p class="mt-1 text-sm text-red-700">{{ deleteHint }}</p>
          <div class="mt-4 flex gap-2">
            <UButton color="error" :loading="deleting" @click="confirmDelete">Delete tag</UButton>
            <UButton variant="ghost" color="neutral" @click="() => { deleteTarget = null }">Cancel</UButton>
          </div>
        </section>

        <TagEditor
          v-if="editor && editorVisible"
          :key="editorKey"
          :tag="editingTag"
          :tags="tags"
          :initial-parent-id="editor.mode === 'create' ? editor.parentId : null"
          @created="onCreated"
          @changed="onChanged"
          @stale="onStale"
          @close="closeEditor"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

import type { Tag, TagStatus } from '~/types/api'
import { describeProblem, toApiProblem } from '~/utils/errorHandler'
import type { ApiProblem } from '~/utils/errorHandler'
import { ancestorIds, buildTagTree, expandableIds, filterTagTree } from '~/utils/tagTree'

type EditorState = { mode: 'create'; seq: number; parentId: number | null } | { mode: 'edit'; id: number }

const tagsApi = useTags()

const tags = ref<Tag[]>([])
const loading = ref(false)
const loadError = ref<ApiProblem | null>(null)
const actionProblem = ref<{ title: string; problem: ApiProblem } | null>(null)
const notice = ref<string | null>(null)

const showArchived = ref(true)
const expandedIds = ref<Set<number>>(new Set())
const editor = ref<EditorState | null>(null)
const deleteTarget = ref<Tag | null>(null)
const deleting = ref(false)
const busyId = ref<number | null>(null)
let createSeq = 0

const tagsById = computed(() => new Map(tags.value.map(tag => [tag.id, tag])))
const archivedCount = computed(() => tags.value.filter(tag => tag.status === 'archived').length)
const fullTree = computed(() => buildTagTree(tags.value))
// Archived tags stay visible while any of their descendants is shown, so a child never loses its parent row.
const visibleTree = computed(() =>
  showArchived.value ? fullTree.value : filterTagTree(fullTree.value, node => node.status === 'active'),
)

const editingTag = computed(() => (editor.value?.mode === 'edit' ? (tagsById.value.get(editor.value.id) ?? null) : null))
const editorKey = computed(() => (editor.value?.mode === 'edit' ? `edit-${editor.value.id}` : `new-${editor.value?.mode === 'create' ? editor.value.seq : 0}`))
// An edit target that vanished from the catalog (deleted elsewhere) must not fall back to the "create" form.
const editorVisible = computed(() => editor.value?.mode === 'create' || editingTag.value !== null)
const panelOpen = computed(() => (editor.value !== null && editorVisible.value) || deleteTarget.value !== null)

const deleteHint = computed(() => {
  const target = deleteTarget.value
  if (!target) return ''
  const hasChildren = tags.value.some(tag => tag.parent_id === target.id)
  if (target.images_count > 0) {
    const noun = target.images_count === 1 ? 'image' : 'images'
    return `It is linked to ${target.images_count} ${noun}, so it will be archived instead of deleted and stay on them.`
  }
  if (hasChildren) return 'It has child tags, so it will be archived instead of deleted.'
  return 'An unused tag is deleted permanently. If it turns out to be in use (images, child tags or moderation candidates), it is archived instead.'
})

function lookupTag(id: number): string | null {
  const found = tagsById.value.get(id)
  return found ? `${found.name}, ${found.type}` : null
}

async function loadTags() {
  loading.value = true
  loadError.value = null
  try {
    tags.value = await tagsApi.listAllTags()
  } catch (e) {
    loadError.value = toApiProblem(e)
  } finally {
    loading.value = false
  }
}

function upsert(tag: Tag) {
  tags.value = tagsById.value.has(tag.id) ? tags.value.map(existing => (existing.id === tag.id ? tag : existing)) : [...tags.value, tag]
}

function reveal(id: number) {
  expandedIds.value = new Set([...expandedIds.value, ...ancestorIds(tags.value, id)])
}

function toggleExpanded(id: number) {
  const next = new Set(expandedIds.value)
  if (!next.delete(id)) next.add(id)
  expandedIds.value = next
}

function expandAll() {
  expandedIds.value = new Set(expandableIds(fullTree.value))
}

function collapseAll() {
  expandedIds.value = new Set()
}

function openCreate(parentId: number | null = null) {
  editor.value = { mode: 'create', seq: ++createSeq, parentId }
  deleteTarget.value = null
  if (parentId !== null) reveal(parentId)
}

function openEdit(tag: Tag) {
  editor.value = { mode: 'edit', id: tag.id }
  deleteTarget.value = null
  reveal(tag.id)
}

function closeEditor() {
  editor.value = null
}

function onCreated(tag: Tag) {
  upsert(tag)
  reveal(tag.id)
  // Stay on the new tag so aliases can be added right away.
  editor.value = { mode: 'edit', id: tag.id }
  notice.value = `Tag “${tag.name}” created. You can add aliases below.`
  actionProblem.value = null
}

function onChanged(tag: Tag, message: string) {
  upsert(tag)
  reveal(tag.id)
  notice.value = message
  actionProblem.value = null
}

function onStale() {
  editor.value = null
  void loadTags()
}

function failAction(title: string, error: unknown) {
  const problem = toApiProblem(error)
  actionProblem.value = { title, problem }
  notice.value = null
  // Someone else removed the tag: the catalog on screen is stale.
  if (problem.code === 'TAG_NOT_FOUND') {
    deleteTarget.value = null
    void loadTags()
  }
}

async function setStatus(tag: Tag, status: TagStatus) {
  busyId.value = tag.id
  actionProblem.value = null
  try {
    upsert(await tagsApi.updateTag(tag.id, { status }))
    notice.value = status === 'archived' ? `“${tag.name}” archived.` : `“${tag.name}” restored.`
  } catch (e) {
    failAction(status === 'archived' ? 'Could not archive the tag' : 'Could not restore the tag', e)
  } finally {
    busyId.value = null
  }
}

function askDelete(tag: Tag) {
  deleteTarget.value = tag
  actionProblem.value = null
}

async function confirmDelete() {
  const target = deleteTarget.value
  if (!target) return
  deleting.value = true
  actionProblem.value = null
  try {
    // 200 = the tag was in use and got archived; 204 (null) = it was removed for good (§50, ADR-0007).
    const archived = await tagsApi.deleteTag(target.id)
    if (archived) {
      upsert(archived)
      notice.value = `“${target.name}” is still in use, so it was archived instead of deleted.`
    } else {
      tags.value = tags.value.filter(tag => tag.id !== target.id)
      notice.value = `“${target.name}” deleted.`
      if (editor.value?.mode === 'edit' && editor.value.id === target.id) editor.value = null
    }
    deleteTarget.value = null
  } catch (e) {
    failAction('Could not delete the tag', e)
  } finally {
    deleting.value = false
  }
}

onMounted(loadTags)
</script>
