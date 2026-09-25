<template>
  <div class="space-y-2">
    <div class="flex items-center gap-3 rounded-lg border border-gray-200 p-3 hover:bg-gray-50">
      <button
        v-if="tag.children?.length"
        class="text-gray-600 hover:text-gray-900"
        :aria-label="expanded ? `Collapse ${tag.name}` : `Expand ${tag.name}`"
        @click="expanded = !expanded"
      >
        <span v-if="expanded">▼</span>
        <span v-else>▶</span>
      </button>
      <div v-else class="w-5" />

      <div class="flex-1">
        <p class="font-medium text-gray-900">{{ tag.name }}</p>
        <p class="text-xs text-gray-600">{{ tag.type }}</p>
      </div>

      <div class="flex items-center gap-2">
        <UButton
          size="sm"
          color="neutral"
          variant="ghost"
          icon="i-heroicons-pencil-square"
          :aria-label="`Edit ${tag.name}`"
          @click="$emit('edit', tag)"
        />
        <UButton
          size="sm"
          color="error"
          variant="ghost"
          icon="i-heroicons-trash"
          :aria-label="`Delete ${tag.name}`"
          @click="$emit('delete', tag)"
        />
      </div>
    </div>

    <div v-if="expanded && tag.children?.length" class="ml-6 space-y-2">
      <TagTreeNode
        v-for="child in tag.children"
        :key="child.id"
        :tag="child"
        @edit="$emit('edit', $event)"
        @delete="$emit('delete', $event)"
        @refresh="$emit('refresh')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import type { Tag } from '~/types/api'

defineProps<{
  tag: Tag
}>()

defineEmits<{
  edit: [Tag]
  delete: [Tag]
  refresh: []
}>()

const expanded = ref(false)
</script>
