<template>
  <section
    class="rounded-xl border border-slate-200 p-5 space-y-3"
    aria-label="Исследование объекта"
  >
    <h3 class="section-title">Что на изображении?</h3>
    <p class="text-sm text-slate-500">
      Поиск по изображению с проверкой источников. При запуске превью
      отправляется поисковику и выбранному агенту.
    </p>
    <ErrorAlert v-if="error" :error="error" />
    <form class="flex flex-wrap items-end gap-3" @submit.prevent="start">
      <UFormField label="Что выяснить (необязательно)" class="flex-1 min-w-48"
        ><UInput
          v-model="question"
          maxlength="1000"
          placeholder="Например, точное название объекта"
          class="w-full"
          aria-label="Что выяснить"
      /></UFormField>
      <UButton
        type="submit"
        :loading="starting"
        :disabled="active || status !== 'ready'"
        >Найти информацию</UButton
      >
    </form>
    <p v-if="loading" role="status">Загрузка истории…</p>
    <p v-if="status !== 'ready'" class="text-sm text-slate-500">
      Поиск будет доступен, когда фото обработается.
    </p>
    <p v-else-if="!runs.length && !loading" class="text-sm text-slate-500">
      Исследование еще не запускалось. Автозапуск можно включить в
      <NuxtLink to="/settings" class="text-blue-600">настройках</NuxtLink>.
    </p>
    <article
      v-for="run in runs"
      :key="run.id"
      class="border-t border-slate-100 pt-3 space-y-2"
    >
      <p class="text-xs text-slate-500">
        {{ labels[run.status] }} · {{ providerLabel(run.provider) }} ·
        {{ run.trigger === "manual" ? "Ручной запуск" : "После загрузки" }}
      </p>
      <template v-if="run.result">
        <h4 class="font-semibold">
          {{ run.result.name || "Объект не определен" }}
        </h4>
        <p class="text-sm">{{ run.result.description }}</p>
        <p class="text-xs text-slate-500">
          Уверенность: {{ confidence[run.result.confidence]
          }}{{ run.result.status === "uncertain" ? " · Требует проверки" : "" }}
        </p>
        <ul class="space-y-2">
          <li v-for="source in run.result.sources" :key="source.url">
            <a
              v-if="safeUrl(source.url)"
              :href="source.url"
              target="_blank"
              rel="noopener noreferrer"
              class="text-sm text-blue-600 underline"
              >{{ source.title }}</a
            >
            <p class="text-xs text-slate-600">{{ source.evidence }}</p>
          </li>
        </ul>
      </template>
      <p v-if="run.error_code" class="text-sm text-amber-700">
        {{
          run.error_code === "WEB_SEARCH_BLOCKED"
            ? "Поисковик заблокировал автоматический запрос или изменил интерфейс. Попробуйте позже."
            : "Исследование не завершилось (" +
              run.error_code +
              "). Можно запустить повторно."
        }}
      </p>
    </article>
    <UButton v-if="error && !active" variant="link" @click="load"
      >Обновить историю</UButton
    >
  </section>
</template>
<script setup lang="ts">
import {
  RESEARCH_PROVIDERS,
  type ResearchRun,
  type ResearchProvider,
} from "~/types/research";
import { toApiProblem } from "~/utils/errorHandler";
const props = defineProps<{ imageId: number; status: string }>();
const research = useResearch(),
  question = ref("");
const runs = ref<ResearchRun[]>([]),
  starting = ref(false),
  loading = ref(true);
const error = ref<ReturnType<typeof toApiProblem> | null>(null);
const active = computed(() =>
  runs.value.some((r) => ["queued", "running"].includes(r.status)),
);
const labels = {
  queued: "В очереди",
  running: "Исследование…",
  succeeded: "Завершено",
  failed: "Ошибка",
  cancelled: "Отменено",
};
const confidence = { low: "низкая", medium: "средняя", high: "высокая" };
const providerLabel = (id: ResearchProvider) =>
  RESEARCH_PROVIDERS.find((p) => p.value === id)?.label || id;
function safeUrl(url: string) {
  try {
    return ["https:", "http:"].includes(new URL(url).protocol);
  } catch {
    return false;
  }
}
let timer: ReturnType<typeof setTimeout> | undefined,
  disposed = false,
  generation = 0;
async function load() {
  const current = ++generation;
  clearTimeout(timer);
  try {
    const history = await research.history(props.imageId);
    if (disposed || current !== generation) return;
    runs.value = history;
    error.value = null;
  } catch (e) {
    if (!disposed && current === generation) error.value = toApiProblem(e);
  } finally {
    if (!disposed && current === generation) {
      loading.value = false;
      if (active.value && !error.value) timer = setTimeout(load, 3000);
    }
  }
}
async function start() {
  starting.value = true;
  error.value = null;
  try {
    await research.start(props.imageId, question.value.trim());
    await load();
  } catch (e) {
    error.value = toApiProblem(e);
  } finally {
    starting.value = false;
  }
}
onMounted(load);
watch(
  () => props.imageId,
  () => {
    runs.value = [];
    loading.value = true;
    load();
  },
);
onBeforeUnmount(() => {
  disposed = true;
  generation++;
  clearTimeout(timer);
});
</script>
