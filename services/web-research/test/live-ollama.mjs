// Opt-in real model check with deterministic source text; not a live internet accuracy test.
import { apiAgent } from "../src/agents.mjs";
import { validateResult } from "../src/core.mjs";
import { chromium } from "playwright";
process.env.OLLAMA_BASE_URL ||= "http://127.0.0.1:11434";
const browser = await chromium.launch();
const page = await browser.newPage();
await page.setContent(
  '<svg width="300" height="200"><rect width="300" height="200" fill="white"/><circle cx="150" cy="100" r="80" fill="orange" stroke="black"/><path d="M70 100H230M150 20V180" stroke="black" stroke-width="5"/></svg>',
);
const image = (
  await page.screenshot({ clip: { x: 0, y: 0, width: 300, height: 200 } })
).toString("base64");
await browser.close();
const input = {
  provider: "ollama",
  mime_type: "image/png",
  image_base64: image,
  question:
    "Определи предмет. Обязательно сначала visual_search, потом read_source.",
};
const source = "https://example.com/synthetic-source";
const session = {
  input,
  calls: 0,
  searched: false,
  visited: new Set(),
  search: async () => {
    console.log("TOOL visual_search");
    session.searched = true;
    return {
      content: [
        {
          type: "text",
          text: JSON.stringify({
            links: [{ url: source, title: "Описание спортивного предмета" }],
          }),
        },
      ],
    };
  },
  read: async (url) => {
    console.log("TOOL read_source");
    session.visited.add(url);
    return {
      content: [
        {
          type: "text",
          text: "Тестовая страница: баскетбольный мяч обычно оранжевого цвета, с черными швами. Рисунок показывает похожие признаки, но это стилизованное изображение.",
        },
      ],
    };
  },
};
try {
  console.log(
    "RESULT",
    JSON.stringify(
      validateResult(
        await apiAgent(input, session, AbortSignal.timeout(180000)),
        session,
      ),
    ),
  );
} catch (e) {
  console.log("FAIL", e.code || e.message);
  process.exitCode = 1;
}
