<template>
  <div class="rounded-lg bg-red-50 p-4">
    <div class="flex items-start">
      <div class="flex-shrink-0">
        <span class="text-2xl">⚠️</span>
      </div>
      <div class="ml-4 flex-1">
        <h3 class="text-sm font-semibold text-red-800">{{ title || 'Error' }}</h3>
        <div v-if="message" class="mt-2 text-sm text-red-700">
          {{ message }}
        </div>
        <div v-if="details" class="mt-2 text-xs text-red-600 bg-red-100 p-2 rounded font-mono">
          {{ details }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    title?: string
    message?: string
    details?: string
  }>(),
  {
    title: 'Error',
  }
)
</script>
