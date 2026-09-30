<template>
  <div class="space-y-6">
    <header>
      <h1 class="page-title">Настройки</h1>
      <p class="page-subtitle">Обработка изображений и подключение агентов</p>
    </header>
    <ErrorAlert v-if="error" :error="error" />
    <p v-if="loading" role="status">Загрузка настроек…</p>
    <form
      v-else-if="settings"
      class="max-w-3xl rounded-xl border border-slate-200 bg-white p-6 space-y-5"
      @submit.prevent="save"
    >
      <h2 class="section-title">Исследование объектов по изображению</h2>
      <UCheckbox
        v-model="settings.object_research_enabled"
        label="Определять объекты через веб-поиск после загрузки"
      />
      <p class="text-sm text-slate-600">
        При включении превью новых фотографий передается внешнему поисковику.
        Агент открывает найденные страницы и предлагает название объекта со
        ссылками. Ручной запуск доступен в карточке фото при любом положении
        галочки.
      </p>
      <p class="text-sm text-slate-600">
        Работает для предметов, растений и животных. Результат не меняет теги и
        подписи автоматически. Поиск личности человека по лицу не выполняется.
      </p>
      <UFormField label="Агент" name="provider"
        ><USelect
          v-model="settings.object_research_provider"
          :items="RESEARCH_PROVIDERS"
          class="w-full"
          aria-label="Агент"
      /></UFormField>
      <p
        class="text-sm"
        :class="available ? 'text-green-700' : 'text-amber-700'"
      >
        {{
          available
            ? "Провайдер настроен. Доступ и наличие модели проверяются при запуске."
            : "Провайдер недоступен. Администратору сервера нужно настроить сервис исследования."
        }}
      </p>
      <p class="text-sm text-slate-500">
        Ollama может работать на этом компьютере или на сервере. Ключи API и
        адрес модели настраиваются на сервере.
      </p>
      <UButton type="submit" :loading="saving">Сохранить настройки</UButton>
      <p v-if="saved" role="status" class="text-green-700">
        Настройки сохранены.
      </p>
    </form>
  </div>
</template>
<script setup lang="ts">
import { RESEARCH_PROVIDERS, type ResearchSettings } from "~/types/research";
import { toApiProblem } from "~/utils/errorHandler";
definePageMeta({ middleware: "auth" });
const research = useResearch();
const settings = ref<ResearchSettings | null>(null);
const error = ref<ReturnType<typeof toApiProblem> | null>(null);
const loading = ref(true),
  saving = ref(false),
  saved = ref(false);
const available = computed(() =>
  settings.value?.providers.some(
    (p) => p.id === settings.value?.object_research_provider && p.available,
  ),
);
onMounted(async () => {
  try {
    settings.value = await research.settings();
  } catch (e) {
    error.value = toApiProblem(e);
  } finally {
    loading.value = false;
  }
});
async function save() {
  if (!settings.value) return;
  saving.value = true;
  error.value = null;
  saved.value = false;
  try {
    settings.value = await research.saveSettings({
      object_research_enabled: settings.value.object_research_enabled,
      object_research_provider: settings.value.object_research_provider,
    });
    saved.value = true;
  } catch (e) {
    error.value = toApiProblem(e);
  } finally {
    saving.value = false;
  }
}
</script>
