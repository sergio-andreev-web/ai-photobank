<template>
  <li class="space-y-2" data-testid="tag-node">
    <div
      class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border p-3 transition-colors"
      :class="[selected ? 'border-blue-300 bg-blue-50' : 'border-gray-200 hover:bg-gray-50', archived ? 'bg-gray-50/60' : '']"
      data-testid="tag-row"
      :data-tag-id="tag.id"
      :data-tag-status="tag.status"
    >
      <button
        v-if="hasChildren"
        type="button"
        class="flex h-5 w-5 shrink-0 items-center justify-center text-gray-600 hover:text-gray-900"
        :aria-expanded="expanded"
        :aria-label="expanded ? `Свернуть ${tag.name}` : `Развернуть ${tag.name}`"
        @click="$emit('toggle', tag.id)"
      >
        <UIcon :name="expanded ? 'i-heroicons-chevron-down' : 'i-heroicons-chevron-right'" />
      </button>
      <span v-else class="h-5 w-5 shrink-0" aria-hidden="true" />

      <div class="min-w-[9rem] flex-1">
        <p class="flex flex-wrap items-center gap-2">
          <span class="font-medium" :class="archived ? 'text-gray-500' : 'text-gray-900'">{{ tag.name }}</span>
          <UBadge v-if="archived" color="warning" variant="soft" size="sm">В архиве</UBadge>
        </p>
        <p class="text-xs text-gray-600">{{ labelOf(TAG_TYPE_LABELS, tag.type) }}</p>
        <p v-if="tag.aliases.length" class="truncate text-xs text-gray-500" data-testid="tag-aliases">
          Синонимы: {{ aliasSummary }}
        </p>
      </div>

      <div class="ml-auto flex items-center gap-3">
        <span class="flex items-center gap-1 whitespace-nowrap text-sm tabular-nums text-gray-600" data-testid="tag-images-count">
          <UIcon name="i-heroicons-photo" class="text-gray-400" />
          {{ tag.images_count }} {{ pluralRu(tag.images_count, IMAGE_FORMS) }}
        </span>

        <div class="flex items-center gap-1">
          <UButton
            size="sm"
            color="neutral"
            variant="ghost"
            icon="i-heroicons-plus"
            :disabled="archived || busy"
            :aria-label="`Добавить дочерний тег к ${tag.name}`"
            :title="archived ? 'Восстановите тег, чтобы добавлять дочерние' : `Добавить дочерний тег к ${tag.name}`"
            @click="$emit('addChild', tag)"
          />
          <UButton
            size="sm"
            color="neutral"
            variant="ghost"
            icon="i-heroicons-pencil-square"
            :disabled="busy"
            :aria-label="`Изменить ${tag.name}`"
            :title="`Изменить ${tag.name}`"
            @click="$emit('edit', tag)"
          />
          <UButton
            v-if="archived"
            size="sm"
            color="neutral"
            variant="ghost"
            icon="i-heroicons-arrow-uturn-left"
            :loading="busy"
            :aria-label="`Восстановить ${tag.name}`"
            :title="`Восстановить ${tag.name}`"
            @click="$emit('restore', tag)"
          />
          <UButton
            v-else
            size="sm"
            color="neutral"
            variant="ghost"
            icon="i-heroicons-archive-box"
            :loading="busy"
            :aria-label="`В архив ${tag.name}`"
            :title="`В архив ${tag.name}`"
            @click="$emit('archive', tag)"
          />
          <UButton
            size="sm"
            color="error"
            variant="ghost"
            icon="i-heroicons-trash"
            :disabled="busy"
            :aria-label="`Удалить ${tag.name}`"
            :title="`Удалить ${tag.name}`"
            @click="$emit('delete', tag)"
          />
        </div>
      </div>
    </div>

    <ul v-if="expanded && hasChildren" class="ml-6 space-y-2">
      <TagTreeNode
        v-for="child in tag.children"
        :key="child.id"
        :tag="child"
        :expanded-ids="expandedIds"
        :selected-id="selectedId"
        :busy-id="busyId"
        @toggle="$emit('toggle', $event)"
        @add-child="$emit('addChild', $event)"
        @edit="$emit('edit', $event)"
        @archive="$emit('archive', $event)"
        @restore="$emit('restore', $event)"
        @delete="$emit('delete', $event)"
      />
    </ul>
  </li>
</template>

<script setup lang="ts">
import type { Tag } from '~/types/api'
import { IMAGE_FORMS, labelOf, pluralRu, TAG_TYPE_LABELS } from '~/utils/labels'
import type { TagNode } from '~/utils/tagTree'

const props = defineProps<{
  tag: TagNode
  expandedIds: ReadonlySet<number>
  selectedId: number | null
  /** Tag with an archive/restore request in flight. */
  busyId: number | null
}>()

defineEmits<{
  toggle: [id: number]
  addChild: [tag: Tag]
  edit: [tag: Tag]
  archive: [tag: Tag]
  restore: [tag: Tag]
  delete: [tag: Tag]
}>()

const hasChildren = computed(() => props.tag.children.length > 0)
const expanded = computed(() => props.expandedIds.has(props.tag.id))
const selected = computed(() => props.selectedId === props.tag.id)
const archived = computed(() => props.tag.status === 'archived')
const busy = computed(() => props.busyId === props.tag.id)
const aliasSummary = computed(() => props.tag.aliases.map(alias => alias.alias).join(', '))
</script>
