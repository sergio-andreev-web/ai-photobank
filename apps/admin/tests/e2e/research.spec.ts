import { test, expect } from "@playwright/test";
import { loginAsAdmin, gotoReady, json, apiError } from "./helpers";
const providers = [
  "codex_cli",
  "claude_cli",
  "openai_api",
  "anthropic_api",
  "ollama",
].map((id) => ({ id, available: true }));
test("settings persist automation and provider, explain image transmission", async ({
  page,
}) => {
  let settings = {
    object_research_enabled: false,
    object_research_provider: "codex_cli",
    providers,
  };
  await loginAsAdmin(page);
  await page.route("**/api/admin/settings", async (route) => {
    if (route.request().method() === "PATCH")
      settings = { ...settings, ...route.request().postDataJSON() };
    await json(route, 200, settings);
  });
  await gotoReady(page, "/settings");
  await expect(
    page.getByRole("heading", { name: "Настройки", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("При включении превью новых фотографий", { exact: false }),
  ).toBeVisible();
  await page.getByRole("checkbox").check();
  await page.getByRole("combobox", { name: "Агент" }).click();
  await page
    .getByRole("option", { name: "Локальная модель · Ollama (Qwen3.5 4B)" })
    .click();
  await page.getByRole("button", { name: "Сохранить настройки" }).click();
  await expect(page.getByText("Настройки сохранены.")).toBeVisible();
  await gotoReady(page, "/settings");
  await expect(page.getByRole("checkbox")).toBeChecked();
  expect(settings.object_research_provider).toBe("ollama");
});
test("settings errors preserve form choice and allow retry", async ({
  page,
}) => {
  await loginAsAdmin(page);
  let reject = true;
  await page.route("**/api/admin/settings", (route) =>
    route.request().method() === "PATCH" && reject
      ? apiError(route, 503, "INTERNAL_ERROR", "Не удалось сохранить")
      : json(route, 200, {
          object_research_enabled: false,
          object_research_provider: "codex_cli",
          providers,
        }),
  );
  await gotoReady(page, "/settings");
  await page.getByRole("checkbox").check();
  await page.getByRole("button", { name: "Сохранить настройки" }).click();
  await expect(page.getByRole("checkbox")).toBeChecked();
  reject = false;
  await page.getByRole("button", { name: "Сохранить настройки" }).click();
  await expect(page.getByText("Настройки сохранены.")).toBeVisible();
});
test("manual research polls queued job, shows uncertainty and source without changing caption", async ({
  page,
}) => {
  await loginAsAdmin(page);
  await page.route("**/api/admin/tags**", (route) =>
    json(route, 200, { data: [], meta: { total: 0 } }),
  );
  await page.route("**/api/admin/images/152", (route) =>
    json(route, 200, {
      id: 152,
      batch_id: 42,
      original_name: "object.jpg",
      status: "ready",
      caption: "Моя подпись",
      caption_edited: true,
      width: 800,
      height: 600,
      file_size: 1000,
      mime_type: "image/jpeg",
      image_tags: [],
      latest_analysis: null,
      processing_tasks: [],
      urls: { thumbnail: null, preview: null, original: null },
      created_at: "2026-09-30T09:00:00Z",
    }),
  );
  await page.route("**/api/admin/images/152/faces", (route) =>
    json(route, 200, { data: [] }),
  );
  await page.route("**/api/admin/images/152/similar**", (route) =>
    json(route, 200, { data: [] }),
  );
  let started = false,
    polls = 0;
  const run = {
    id: 1,
    image_id: 152,
    trigger: "manual",
    provider: "ollama",
    question: "Что это?",
    status: "queued",
    result: null,
    error_code: null,
    created_at: "2026-09-30T09:00:00Z",
    finished_at: null,
  };
  await page.route("**/api/admin/images/152/research", (route) => {
    if (route.request().method() === "POST") {
      started = true;
      expect(route.request().postDataJSON().question).toBe("Что это?");
      return json(route, 202, run);
    }
    if (!started) return json(route, 200, { data: [] });
    if (polls++ === 0) return json(route, 200, { data: [run] });
    return json(route, 200, {
      data: [
        {
          ...run,
          status: "succeeded",
          result: {
            status: "uncertain",
            name: null,
            description:
              "Несколько похожих объектов, точный вид не подтвержден.",
            confidence: "low",
            sources: [
              {
                url: "https://example.com/source",
                title: "Описание объекта",
                evidence: "Внешние признаки совпадают частично.",
              },
            ],
          },
        },
      ],
    });
  });
  await gotoReady(page, "/gallery/152");
  const region = page.getByRole("region", { name: "Исследование объекта" });
  await region.getByLabel("Что выяснить", { exact: true }).fill("Что это?");
  await region.getByRole("button", { name: "Найти информацию" }).click();
  await expect(
    region.getByRole("button", { name: "Найти информацию" }),
  ).toBeDisabled();
  await expect(region.getByText("Объект не определен")).toBeVisible({
    timeout: 10000,
  });
  await expect(
    region.getByRole("link", { name: "Описание объекта" }),
  ).toHaveAttribute("href", "https://example.com/source");
  await expect(
    region.getByText("Уверенность: низкая · Требует проверки"),
  ).toBeVisible();
  await expect(
    region.getByRole("button", { name: "Найти информацию" }),
  ).toBeEnabled();
  await expect(
    page.getByText("Подпись: Моя подпись", { exact: false }),
  ).toBeVisible();
});
