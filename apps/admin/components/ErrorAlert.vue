<template>
  <div class="rounded-lg bg-red-50 p-4" role="alert">
    <div class="flex items-start">
      <div class="flex-shrink-0">
        <span class="text-2xl">⚠️</span>
      </div>
      <div class="ml-4 flex-1">
        <h3 class="text-sm font-semibold text-red-800">{{ title || 'Ошибка' }}</h3>
        <div v-if="message" class="mt-2 text-sm text-red-700">
          {{ message }}
        </div>
        <div v-if="details" class="mt-2 text-xs text-red-600 bg-red-100 p-2 rounded font-mono">
          {{ details }}
        </div>
      </div>
      <button
        v-if="dismissible"
        type="button"
        class="ml-4 flex-shrink-0 text-red-500 hover:text-red-700"
        aria-label="Закрыть"
        @click="$emit('dismiss')"
      >
        ✕
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    title?: string
    message?: string
    details?: string
    dismissible?: boolean
  }>(),
  {
    title: 'Ошибка',
    dismissible: true,
  }
)

defineEmits<{
  dismiss: []
}>()
</script>
